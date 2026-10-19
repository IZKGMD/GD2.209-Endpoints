<?php

declare(strict_types=1);

namespace MuchoCore\User;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Clan\ClanQuestRepository;
use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Protocol\GdUserEncoder;
use PDO;
use RuntimeException;

final readonly class UserService
{
    public function __construct(
        private PDO $pdo,
        private AccountAuthenticator $auth,
        private AccountRepository $accountRepository,
        private UserRepository $userRepository,
        private GdUserEncoder $userEncoder,
        private ?Legacy10IdentityService $legacy10 = null,
        private ?ClanQuestRepository $clanQuests = null
    ) {}

    public function updateScore(
        int $accountId,
        string $gjp,
        array $data,
        string $udid = '',
        string $username = '',
        string $ip = ''
    ): string
    {
        $gameVersion = (int)($data['gameVersion'] ?? 1);

        if ($gjp !== '') {
            $this->auth->authenticate($accountId, $gjp);
        } elseif (
            $accountId <= 0 &&
            $gameVersion > 0 &&
            $gameVersion < 19 &&
            trim($udid) !== '' &&
            $this->legacy10 !== null
        ) {
            $accountId = $this->legacy10->resolveAccount(
                $udid,
                $username,
                $ip
            );
        } else {
            throw new RuntimeException('Unauthorized.');
        }

        /*
         * Keep the modern Mucho schema compatible while applying the
         * validation rules from fix-2356. Optional fields are written only
         * when the current profiles table actually contains them.
         */
        $protocolVersion = ClientVersion::fromValues(
            $this->boundedInt($data, ['gameVersion'], 22, 0, 1000),
            $this->boundedInt($data, ['binaryVersion'], 0, 0, 10000)
        );

        $fields = [
            'game_version' => $this->boundedInt($data, ['gameVersion'], 22, 0, 1000),
            'binary_version' => $this->boundedInt($data, ['binaryVersion'], 0, 0, 10000),
            'stars' => $this->boundedInt($data, ['stars'], 0, 0, 10_000_000),
            'moons' => $this->boundedInt($data, ['moons'], 0, 0, 10_000_000),
            'demons' => $this->boundedInt($data, ['demons'], 0, 0, 1_000_000),
            'diamonds' => $this->boundedInt($data, ['diamonds'], 0, 0, 100_000_000),
            'secret_coins' => $this->boundedInt($data, ['coins'], 0, 0, 1_000_000),
            'user_coins' => $this->boundedInt($data, ['userCoins'], 0, 0, 1_000_000),

            'icon_id' => $this->boundedInt(
                $data,
                ['icon', 'accIcon'],
                1,
                0,
                65535
            ),

            'icon_type' => $this->boundedInt(
                $data,
                ['iconType'],
                0,
                0,
                32
            ),

            'cube' => $this->boundedInt($data, ['accIcon'], 1, 0, 65535),
            'ship' => $this->boundedInt($data, ['accShip'], 1, 0, 65535),
            'ball' => $this->boundedInt($data, ['accBall'], 1, 0, 65535),
            'ufo' => $this->boundedInt($data, ['accBird'], 1, 0, 65535),
            'wave' => $this->boundedInt($data, ['accDart'], 1, 0, 65535),
            'robot' => $this->boundedInt($data, ['accRobot'], 1, 0, 65535),
            'spider' => $this->boundedInt($data, ['accSpider'], 1, 0, 65535),
            'swing' => $this->boundedInt($data, ['accSwing'], 1, 0, 65535),
            'jetpack' => $this->boundedInt($data, ['accJetpack'], 1, 0, 65535),

            'explosion' => $this->boundedInt(
                $data,
                ['accExplosion'],
                1,
                0,
                65535
            ),

            'color1' => $this->boundedInt($data, ['color1'], 0, 0, 65535),
            'color2' => $this->boundedInt($data, ['color2'], 3, 0, 65535),
            'color3' => $this->boundedInt($data, ['color3'], 0, -1, 32767),

            'special' => $this->boundedInt(
                $data,
                ['special', 'accSpecial'],
                0,
                0,
                1
            ),

            'glow' => $this->boundedInt(
                $data,
                ['accGlow', 'glow'],
                0,
                0,
                65535
            ),

            'demon_info' => $this->boundedText(
                $data,
                ['dinfo'],
                '',
                65535
            ),

            'star_info' => $this->boundedText(
                $data,
                ['sinfo'],
                '',
                65535
            ),

            'platformer_info' => $this->boundedText(
                $data,
                ['pinfo'],
                '',
                65535
            )
        ];

        $schema = $this->pdo->query('SHOW COLUMNS FROM profiles');

        if ($schema === false) {
            throw new RuntimeException('Unable to inspect profile schema.');
        }

        $profileColumns = $schema->fetchAll(PDO::FETCH_COLUMN);

        if ($profileColumns === []) {
            throw new RuntimeException('Profile schema is empty.');
        }

        $available = array_fill_keys(
            array_map('strval', $profileColumns),
            true
        );

        $effectiveGameVersion = $protocolVersion->effectiveGameVersion();

        if ($effectiveGameVersion >= 20 && $effectiveGameVersion <= 21) {
            $fields['demon_info'] = $this->normalizeDemonInfo(
                $data,
                (int)$fields['demons'],
                (string)$fields['demon_info']
            );

            [$fields['star_info'], $fields['platformer_info']] =
                $this->normalizeStarInfo(
                    $data,
                    (string)$fields['star_info'],
                    (string)$fields['platformer_info']
                );
        }

        $fields = array_filter(
            $fields,
            static fn (mixed $value, string $column): bool =>
                isset($available[$column]),
            ARRAY_FILTER_USE_BOTH
        );

        if ($fields === []) {
            throw new RuntimeException(
                'No compatible profile fields are available.'
            );
        }

        $columns = array_keys($fields);

        $quotedColumns = array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns
        );

        $placeholders = array_map(
            static fn (string $column): string => ':' . $column,
            $columns
        );

        $updates = array_map(
            static fn (string $column): string =>
                sprintf('`%1$s` = VALUES(`%1$s`)', $column),
            $columns
        );

        $sql = sprintf(
            'INSERT INTO profiles (`account_id`, %s)
             VALUES (:account_id, %s)
             ON DUPLICATE KEY UPDATE %s',
            implode(', ', $quotedColumns),
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        // Lock the clan before the profile, matching join/leave and quest writes.
        // Persist score and quest progress atomically; retries cannot double count.
        if ($this->clanQuests!==null) $this->pdo->beginTransaction();
        try {
            $membership=$this->clanQuests?->lockForAccount($accountId);
            $stmt = $this->pdo->prepare($sql);

            if (!$stmt->execute(
                array_merge(
                    ['account_id' => $accountId],
                    $fields
                )
            )) {
                throw new RuntimeException('Unable to update profile.');
            }

            $userStmt = $this->pdo->prepare(
                'SELECT user_id
                 FROM profiles
                 WHERE account_id = :account_id
                 LIMIT 1'
            );

            $userStmt->execute([
                'account_id' => $accountId,
            ]);

            $userId = (int)$userStmt->fetchColumn();

            if ($userId <= 0) {
                throw new RuntimeException('Unable to resolve profile user ID.');
            }

            if ($membership!==null) $this->clanQuests?->settle((int)$membership['clan_id']);
            if ($this->clanQuests!==null) $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->clanQuests!==null && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        /*
         * updateGJUserScore returns the legacy userID, not accountID.
         */
        return (string)$userId;
    }

    public function updateSettings(
        int $accountId,
        string $gjp,
        int $mS,
        int $frS,
        int $cS,
        string $yt,
        string $twitter,
        string $twitch,
        string $instagram = '',
        string $discord = '',
        string $tiktok = '',
        string $custom = ''
    ): bool {
        $this->auth->authenticate($accountId, $gjp);

        $q = $this->pdo->prepare(
            'UPDATE accounts SET
                messages_state=:ms,
                friend_requests_state=:fr,
                comments_state=:cs,
                youtube_url=:yt,
                twitter=:tw,
                twitch=:tt,
                instagram=:ig,
                discord=:dc,
                tiktok=:tk,
                custom_link=:custom
             WHERE account_id=:id'
        );

        return $q->execute([
            'ms' => max(0, min(2, $mS)),
            'fr' => max(0, min(2, $frS)),
            'cs' => max(0, min(2, $cS)),
            'yt' => substr(trim($yt), 0, 255),
            'tw' => substr(trim($twitter), 0, 64),
            'tt' => substr(trim($twitch), 0, 64),
            'ig' => substr(trim($instagram), 0, 64),
            'dc' => substr(trim($discord), 0, 64),
            'tk' => substr(trim($tiktok), 0, 64),
            'custom' => substr(trim($custom), 0, 255),
            'id' => $accountId,
        ]);
    }

    public function requestAccess(int $accountId, string $gjp): string
    {
        $this->auth->authenticate($accountId, $gjp);

        $q = $this->pdo->prepare('SELECT COALESCE(r.code, \'user\') AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id
             LIMIT 1');
        $q->execute(['id' => $accountId]);

        try {
            return GameRole::accessLevel((string)$q->fetchColumn());
        } catch (\InvalidArgumentException) {
            return '-1';
        }
    }

    public function search(string $query, int $page = 0): string
    {
        $page = min(1000, max(0, $page));
        $limit = 10;
        $offset = $page * $limit;
        $users = $this->userRepository->search($query, $offset, $limit);
        $total = $this->userRepository->searchCount($query);

        if ($users === []) {
            return '-1';
        }

        return $this->userEncoder->search($users, $total, $offset, $limit);
    }

    public function resolveAccountIdByUserId(int $userId): int
    {
        return $this->userRepository->findAccountIdByUserId(
            $userId
        );
    }

    public function authenticate(
        int $accountId,
        string $credential
    ): void {
        $this->auth->authenticate($accountId, $credential);
    }

    public function getProfile(
        int $targetAccountId,
        int $viewerAccountId = 0,
        int $gameVersion = 22,
        int $binaryVersion = 0
    ): string
    {
        $profile = $this->userRepository->getProfileByTarget(
            $targetAccountId,
            $viewerAccountId
        );
        if (!$profile) {
            return '-1';
        }

        if ($gameVersion >= 22 && $binaryVersion >= 51) {
            // A single indexed lookup for all 2.209 follow/notification
            // counters; do not alter 2.2081 or older profile responses.
            $counters = $this->userRepository->getGd2209ProfileCounters(
                $targetAccountId, $viewerAccountId
            );
            $profile['gd2209_followers'] = (int)($counters['followers'] ?? 0);
            $profile['gd2209_is_following'] = (int)($counters['is_following'] ?? 0);
            if ($viewerAccountId === $targetAccountId) {
                $profile['gd2209_following'] = (int)($counters['following'] ?? 0);
                $profile['gd2209_notifications'] = (int)($counters['notification_count'] ?? 0);
            }
        }

        return $this->userEncoder->profile($profile, $gameVersion, $binaryVersion);
    }

    public function getLeaderboard(
        string $type,
        int $accountId = 0,
        int $gameVersion = 0,
        int $limit = 100,
        string $credential = ''
    ): string {
        /*
         * Legacy getGJScores authenticates whenever accountID is supplied.
         * Top/creator lists can still be requested without an account context,
         * while friends/relative leaderboards require one.
         */
        if ($accountId > 0) {
            if ($credential === '') {
                return '-1';
            }

            $this->auth->authenticate($accountId, $credential);
        } elseif (in_array($type, ['friends', 'relative'], true)) {
            return '-1';
        }

        $users = match ($type) {
            'creators' => $this->userRepository->leaderboardCreators($limit, $gameVersion),
            'friends'  => $this->userRepository->leaderboardFriends($accountId, $limit, $gameVersion),
            'relative' => $this->userRepository->leaderboardRelative($accountId, 50, $gameVersion),
            default    => $this->userRepository->leaderboardTop($limit, $gameVersion)
        };

        return $this->userEncoder->leaderboard($users);
    }

    /**
     * @param list<string> $keys
     */

    private function normalizeDemonInfo(
        array $data,
        int $demons,
        string $fallback
    ): string {
        $raw = $data['dinfo'] ?? '';

        if (!is_scalar($raw) || trim((string)$raw) === '') {
            return $fallback;
        }

        $ids = $this->numericIdList((string)$raw, 1000);

        if ($ids === []) {
            return $fallback;
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $key = 'demon_level_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $sql = 'SELECT
                COALESCE(SUM(CASE WHEN demon=1 AND length<>5 AND demon_difficulty=3 THEN 1 ELSE 0 END),0) easy_normal,
                COALESCE(SUM(CASE WHEN demon=1 AND length<>5 AND demon_difficulty=4 THEN 1 ELSE 0 END),0) medium_normal,
                COALESCE(SUM(CASE WHEN demon=1 AND length<>5 AND COALESCE(demon_difficulty,0)=0 THEN 1 ELSE 0 END),0) hard_normal,
                COALESCE(SUM(CASE WHEN demon=1 AND length<>5 AND demon_difficulty=5 THEN 1 ELSE 0 END),0) insane_normal,
                COALESCE(SUM(CASE WHEN demon=1 AND length<>5 AND demon_difficulty=6 THEN 1 ELSE 0 END),0) extreme_normal,
                COALESCE(SUM(CASE WHEN demon=1 AND length=5 AND demon_difficulty=3 THEN 1 ELSE 0 END),0) easy_platformer,
                COALESCE(SUM(CASE WHEN demon=1 AND length=5 AND demon_difficulty=4 THEN 1 ELSE 0 END),0) medium_platformer,
                COALESCE(SUM(CASE WHEN demon=1 AND length=5 AND COALESCE(demon_difficulty,0)=0 THEN 1 ELSE 0 END),0) hard_platformer,
                COALESCE(SUM(CASE WHEN demon=1 AND length=5 AND demon_difficulty=5 THEN 1 ELSE 0 END),0) insane_platformer,
                COALESCE(SUM(CASE WHEN demon=1 AND length=5 AND demon_difficulty=6 THEN 1 ELSE 0 END),0) extreme_platformer
             FROM levels
             WHERE level_id IN (' . implode(',', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $values = [
            (int)($counts['easy_normal'] ?? 0),
            (int)($counts['medium_normal'] ?? 0),
            (int)($counts['hard_normal'] ?? 0),
            (int)($counts['insane_normal'] ?? 0),
            (int)($counts['extreme_normal'] ?? 0),
            (int)($counts['easy_platformer'] ?? 0),
            (int)($counts['medium_platformer'] ?? 0),
            (int)($counts['hard_platformer'] ?? 0),
            (int)($counts['insane_platformer'] ?? 0),
            (int)($counts['extreme_platformer'] ?? 0),
            $this->boundedInt($data, ['dinfow'], 0, 0, 1000000),
            $this->boundedInt($data, ['dinfog'], 0, 0, 1000000),
        ];

        $missing = max(0, min($demons - array_sum($values), 3));
        $values[0] += $missing;

        return implode(',', $values);
    }

    /** @return array{0:string,1:string} */
    private function normalizeStarInfo(
        array $data,
        string $fallbackStars,
        string $fallbackPlatformer
    ): array {
        $raw = $data['sinfo'] ?? '';

        if (!is_scalar($raw) || trim((string)$raw) === '') {
            return [$fallbackStars, $fallbackPlatformer];
        }

        $parts = preg_split('/\s*,\s*/', trim((string)$raw)) ?: [];

        if (count($parts) < 12) {
            return [$fallbackStars, $fallbackPlatformer];
        }

        $values = [];

        foreach (array_slice($parts, 0, 12) as $part) {
            if (preg_match('/^\d{1,10}$/D', $part) !== 1) {
                return [$fallbackStars, $fallbackPlatformer];
            }

            $values[] = (int)$part;
        }

        $starValues = array_merge(
            array_slice($values, 0, 6),
            [
                $this->boundedInt($data, ['sinfod'], 0, 0, 1000000),
                $this->boundedInt($data, ['sinfog'], 0, 0, 1000000),
            ]
        );

        $platformerValues = array_merge(array_slice($values, 6, 6), [0]);

        return [
            implode(',', $starValues),
            implode(',', $platformerValues),
        ];
    }

    /** @return list<int> */
    private function numericIdList(
        string $value,
        int $maximum
    ): array {
        $ids = [];

        foreach (preg_split('/[,\s]+/', trim($value)) ?: [] as $part) {
            if (ctype_digit($part)) {
                $id = (int)$part;

                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            if (count($ids) >= $maximum) {
                break;
            }
        }

        return $ids;
    }

    private function boundedText(
        array $data,
        array $keys,
        string $default,
        int $maximum
    ): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (!is_scalar($value)) {
                throw new RuntimeException(
                    'Invalid profile text value.'
                );
            }

            $value = (string)$value;

            if (strlen($value) > $maximum) {
                throw new RuntimeException(
                    'Profile text value is too large.'
                );
            }

            return $value;
        }

        return $default;
    }

    private function boundedInt(
        array $data,
        array $keys,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = null;

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                break;
            }
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (
            !is_int($value) &&
            (
                !is_string($value) ||
                preg_match('/^-?\d{1,10}$/D', $value) !== 1
            )
        ) {
            throw new RuntimeException('Invalid profile value.');
        }

        $value = (int)$value;

        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                'Profile value is outside the allowed range.'
            );
        }

        return $value;
    }

}
