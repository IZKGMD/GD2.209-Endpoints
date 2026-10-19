<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdLegacyText;
use PDO;
use MuchoCore\User\GameRole;

final class CommentService
{
    public function __construct(
        private readonly CommentRepository $repository,
        private readonly AccountAuthenticator $auth,
        private readonly GdCommentEncoder $encoder,
        private readonly ?PDO $pdo = null,
        private readonly ?Legacy10IdentityService $legacy10 = null
    ) {}

    private function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $db = new \MuchoCore\Database\Database();
        return $db->connection();
    }

    public function uploadLevelComment(
        int $levelId,
        int $accountId,
        string $gjp,
        string $content,
        int $percent,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): string
    {
        $accountId = $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );
        if (\MuchoCore\Moderation\Gd2209Restrictions::active(
            $this->getPdo(), $accountId, 'comment'
        )) {
            throw new \RuntimeException('Account temporarily restricted from comments.');
        }
        $decodedContent = GdLegacyText::decodeComment(
            $content,
            $gameVersion
        );
        $decodedContent = $this->normalizeCommentText($decodedContent);

        if ($decodedContent === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }

        if (!$this->levelExists($levelId)) {
            throw new \RuntimeException('Level not found.');
        }

        /*
         * Any text beginning with "!" is a command attempt.
         * Commands are never persisted as ordinary comments.
         */
        if (str_starts_with($decodedContent, "!")) {
            $commandName = strtolower(
                (string)(preg_split("/\\s+/", $decodedContent)[0] ?? "")
            );

            if ($commandName === "!help") {
                return $this->commandResponse(
                    $gameVersion,
                    true,
                    $this->commandHelp()
                );
            }

            $handled = $this->handleCommand(
                $levelId,
                $accountId,
                $decodedContent
            );

            return $this->commandResponse(
                $gameVersion,
                $handled === true
            );
        }

        $percent = max(0, min(100, $percent));

        if ($this->pdo === null) {
            $commentId = $this->repository->addLevelComment(
                $levelId,
                $accountId,
                $decodedContent,
                $percent
            );

            return $gameVersion >= 21
                ? (string)$commentId
                : "1";
        }

        $this->pdo->beginTransaction();

        try {
            $commentId = $this->repository->addLevelComment(
                $levelId,
                $accountId,
                $decodedContent,
                $percent
            );

            /*
             * Legacy GD also records the progress attached to a comment as a
             * level score when percent is non-zero.
             */
            if ($percent > 0) {
                $this->recordCommentProgress(
                    $accountId,
                    $levelId,
                    $percent
                );
            }

            $this->pdo->commit();

            return $gameVersion >= 21
                ? (string)$commentId
                : "1";
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function recordCommentProgress(
        int $accountId,
        int $levelId,
        int $percent
    ): void {
        if ($this->pdo === null) {
            return;
        }

        $now = time();

        $q = $this->pdo->prepare(
            'INSERT INTO mucho_level_scores
             (account_id, level_id, is_daily, daily_id, percent,
              coins, attempts, clicks, play_time, progresses,
              created_at, updated_at)
             VALUES
             (:account_id, :level_id, 0, 0, :percent,
              0, 0, 0, 0, \'\',
              :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                percent = GREATEST(percent, VALUES(percent)),
                updated_at = CASE
                    WHEN VALUES(percent) > percent
                    THEN VALUES(updated_at)
                    ELSE updated_at
                END'
        );

        $q->execute([
            'account_id' => $accountId,
            'level_id' => $levelId,
            'percent' => $percent,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function commandResponse(
        int $gameVersion,
        bool $success,
        string $message = ""
    ): string {
        /*
         * Geometry Dash 2.1+ understands temporary server messages
         * in the "temp_<seconds>_<message>" response form.
         *
         * GD 1.9/2.0 use strict numeric comment-upload responses, so
         * preserve their wire compatibility: 1 = accepted, -1 = failed.
         */
        if ($gameVersion >= 21) {
            if ($message === "") {
                $message = $success
                    ? "Command executed successfully!"
                    : "Command failed!";
            }

            return "temp_0_" . $message;
        }

        return $success ? "1" : "-1";
    }

    private function commandHelp(): string
    {
        return 'MuchoCore commands: !help / !rate <difficulty> <stars> [coins] [featured] / ' .
            '!r <difficulty> <stars> [coins] [featured] / !demon <1-5> / ' .
            '!feature / !epic / !unepic / !verifycoins / !delete / !cp <amount>';
    }

    private function handleCommand(
        int $levelId,
        int $accountId,
        string $commandStr
    ): ?bool {
        $pdo = $this->getPdo();

        $parts = preg_split('/\\s+/', trim($commandStr)) ?: [];
        $cmd = strtolower((string)($parts[0] ?? ''));

        $aliases = [
            '!r' => '!rate',
            '!f' => '!feature',
            '!e' => '!epic',
            '!ue' => '!unepic',
            '!vc' => '!verifycoins',
            '!d' => '!delete',
            '!delet' => '!delete',
        ];
        $cmd = $aliases[$cmd] ?? $cmd;

        $roleStmt = $pdo->prepare(
            "SELECT NULLIF(r.code, '') AS role_code,
                    NULLIF(a.role, '') AS legacy_role
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id
               AND a.is_active = 1
               AND a.is_banned = 0
             LIMIT 1"
        );
        $roleStmt->execute([':id' => $accountId]);

        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $role = 'user';

        foreach ([
            $roleRow['role_code'] ?? '',
            $roleRow['legacy_role'] ?? '',
        ] as $rawRole) {
            $candidate = trim((string)$rawRole);

            if ($candidate === '') {
                continue;
            }

            try {
                $candidate = GameRole::normalize($candidate);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (in_array(
                $candidate,
                [
                    GameRole::OWNER,
                    GameRole::ELDER_MODERATOR,
                    GameRole::MODERATOR,
                ],
                true
            )) {
                $role = $candidate;
                break;
            }
        }

        if (!in_array(
            $role,
            [
                GameRole::OWNER,
                GameRole::ELDER_MODERATOR,
                GameRole::MODERATOR,
            ],
            true
        )) {
            return null;
        }

        // Keep direct rating consistent with ModerationService:
        // regular moderators may suggest ratings, but only elder moderators
        // and owners may apply a direct !rate mutation.
        if (
            $cmd === '!rate' &&
            !in_array(
                $role,
                [
                    GameRole::OWNER,
                    GameRole::ELDER_MODERATOR,
                ],
                true
            )
        ) {
            return null;
        }

        if ($levelId <= 0 || !$this->levelExists($levelId)) {
            return null;
        }

        $authorStmt = $pdo->prepare(
            "SELECT account_id
             FROM levels
             WHERE level_id = :id
               AND is_deleted = 0
             LIMIT 1"
        );
        $authorStmt->execute([':id' => $levelId]);

        $authorAccountId = (int)(
            $authorStmt->fetchColumn() ?: 0
        );

        if ($authorAccountId <= 0) {
            return null;
        }

        $pdo->beginTransaction();

        try {
            $result = false;
            $details = ['command' => $cmd];

            switch ($cmd) {
                case '!rate':
                    $difficultyName = strtolower(
                        (string)($parts[1] ?? '')
                    );

                    $stars = isset($parts[2]) &&
                        preg_match('/^-?\d+$/', (string)$parts[2]) === 1
                        ? (int)$parts[2]
                        : 0;

                    $coins = isset($parts[3]) &&
                        preg_match('/^-?\d+$/', (string)$parts[3]) === 1
                        ? (int)$parts[3]
                        : null;

                    $featured = isset($parts[4]) &&
                        preg_match('/^-?\d+$/', (string)$parts[4]) === 1
                        ? (int)$parts[4]
                        : null;

                    $difficultyMap = [
                        'na' => [0, 0, 0],
                        'none' => [0, 0, 0],
                        'auto' => [50, 1, 0],
                        'easy' => [10, 0, 0],
                        'normal' => [20, 0, 0],
                        'hard' => [30, 0, 0],
                        'harder' => [40, 0, 0],
                        'insane' => [50, 0, 0],
                        'demon' => [50, 0, 1],
                    ];

                    if (!isset($difficultyMap[$difficultyName])) {
                        break;
                    }

                    if ($stars < 0 || $stars > 10) {
                        break;
                    }

                    if (
                        $coins !== null &&
                        ($coins < 0 || $coins > 3)
                    ) {
                        break;
                    }

                    if (
                        $featured !== null &&
                        ($featured < 0 || $featured > 4)
                    ) {
                        break;
                    }

                    [
                        $difficulty,
                        $auto,
                        $demon
                    ] = $difficultyMap[$difficultyName];

                    if (
                        $difficultyName === 'demon' &&
                        $stars === 0
                    ) {
                        $stars = 10;
                    }

                    if (
                        $difficultyName === 'auto' &&
                        $stars === 0
                    ) {
                        $stars = 1;
                    }

                    $updateSql = "
                        UPDATE levels
                        SET stars = :stars,
                            difficulty = :difficulty,
                            demon = :demon,
                            demon_difficulty =
                                CASE
                                    WHEN :demon_state = 1
                                    THEN demon_difficulty
                                    ELSE 0
                                END,
                            auto_level = :auto,
                            updated_at = NOW()";

                    $params = [
                        ':stars' => $stars,
                        ':difficulty' => $difficulty,
                        ':demon' => $demon,
                        ':demon_state' => $demon,
                        ':auto' => $auto,
                        ':id' => $levelId,
                    ];

                    if ($coins !== null) {
                        $updateSql .=
                            ", coins_verified = :coins_verified";
                        $params[':coins_verified'] =
                            $coins > 0 ? 1 : 0;
                    }

                    if ($featured !== null) {
                        $updateSql .=
                            ", featured = :featured";
                        $params[':featured'] =
                            $featured > 0 ? 1 : 0;

                        if ($featured === 0) {
                            $updateSql .= ", epic = 0";
                        } elseif ($featured >= 2) {
                            $updateSql .=
                                ",
                                epic = CASE
                                    WHEN :featured_tier = 2 THEN 1
                                    WHEN :featured_tier = 3 THEN 2
                                    WHEN :featured_tier = 4 THEN 3
                                    ELSE epic
                                END";
                            $params[':featured_tier'] =
                                $featured;
                        }
                    }

                    $updateSql .=
                        " WHERE level_id = :id
                          AND is_deleted = 0";

                    $update = $pdo->prepare($updateSql);
                    $update->execute($params);

                    $verify = $pdo->prepare(
                        "SELECT stars, difficulty, demon,
                                auto_level, coins_verified,
                                featured, epic
                         FROM levels
                         WHERE level_id = :id
                           AND is_deleted = 0
                         LIMIT 1"
                    );
                    $verify->execute([':id' => $levelId]);

                    $state = $verify->fetch(PDO::FETCH_ASSOC);

                    if (!$state) {
                        break;
                    }

                    if (
                        (int)$state['stars'] !== $stars ||
                        (int)$state['difficulty'] !== $difficulty ||
                        (int)$state['demon'] !== $demon ||
                        (int)$state['auto_level'] !== $auto
                    ) {
                        break;
                    }

                    if (
                        $coins !== null &&
                        (int)$state['coins_verified'] !==
                        ($coins > 0 ? 1 : 0)
                    ) {
                        break;
                    }

                    if (
                        $featured !== null &&
                        (int)$state['featured'] !==
                        ($featured > 0 ? 1 : 0)
                    ) {
                        break;
                    }

                    $details += [
                        'stars' => $stars,
                        'difficulty' => $difficulty,
                        'demon' => $demon,
                        'auto_level' => $auto,
                    ];

                    if ($coins !== null) {
                        $details['coins_verified'] =
                            $coins > 0 ? 1 : 0;
                    }

                    if ($featured !== null) {
                        $details['featured'] =
                            $featured > 0 ? 1 : 0;
                    }

                    $result = true;
                    break;

                case '!demon':
                    $demonDifficulty =
                        isset($parts[1]) &&
                        preg_match('/^\d+$/', (string)$parts[1]) === 1
                        ? (int)$parts[1]
                        : 3;

                    if (
                        $demonDifficulty < 1 ||
                        $demonDifficulty > 5
                    ) {
                        break;
                    }

                    $update = $pdo->prepare(
                        "UPDATE levels
                         SET stars = 10,
                             difficulty = 50,
                             demon = 1,
                             demon_difficulty =
                                 :demon_difficulty,
                             auto_level = 0,
                             custom_difficulty = 0,
                             updated_at = NOW()
                         WHERE level_id = :id
                           AND is_deleted = 0"
                    );

                    $update->execute([
                        ':demon_difficulty' => $demonDifficulty,
                        ':id' => $levelId,
                    ]);

                    $details['demon_difficulty'] =
                        $demonDifficulty;
                    $result = true;
                    break;

                case '!feature':
                    $q = $pdo->prepare(
                        "UPDATE levels
                         SET featured = 1,
                             updated_at = NOW()
                         WHERE level_id = :id
                           AND is_deleted = 0"
                    );
                    $q->execute([':id' => $levelId]);
                    $result = true;
                    break;

                case '!epic':
                    $q = $pdo->prepare(
                        "UPDATE levels
                         SET epic = 1,
                             updated_at = NOW()
                         WHERE level_id = :id
                           AND is_deleted = 0"
                    );
                    $q->execute([':id' => $levelId]);
                    $result = true;
                    break;

                case '!unepic':
                    $q = $pdo->prepare(
                        "UPDATE levels
                         SET epic = 0,
                             updated_at = NOW()
                         WHERE level_id = :id
                           AND is_deleted = 0"
                    );
                    $q->execute([':id' => $levelId]);
                    $result = true;
                    break;

                case '!verifycoins':
                    $q = $pdo->prepare(
                        "UPDATE levels
                         SET coins_verified = 1,
                             updated_at = NOW()
                         WHERE level_id = :id
                           AND is_deleted = 0"
                    );
                    $q->execute([':id' => $levelId]);
                    $result = true;
                    break;

                case '!delete':
                    $q = $pdo->prepare(
                        "UPDATE levels
                         SET is_deleted = 1,
                             updated_at = NOW()
                         WHERE level_id = :id
                           AND is_deleted = 0"
                    );
                    $q->execute([':id' => $levelId]);
                    $result = true;
                    break;

                case '!cp':
                    $amount =
                        isset($parts[1]) &&
                        preg_match('/^\d+$/', (string)$parts[1]) === 1
                        ? (int)$parts[1]
                        : 1;

                    if ($amount < 1 || $amount > 100) {
                        break;
                    }

                    $pdo->prepare(
                        "INSERT IGNORE INTO profiles
                         (account_id, stars, created_at, updated_at)
                         VALUES (:account, 0, NOW(), NOW())"
                    )->execute([
                        ':account' => $authorAccountId,
                    ]);

                    $q = $pdo->prepare(
                        "UPDATE profiles
                         SET creator_points =
                             creator_points + :cp,
                             updated_at = NOW()
                         WHERE account_id = :account"
                    );

                    $q->execute([
                        ':cp' => $amount,
                        ':account' => $authorAccountId,
                    ]);

                    $result = true;
                    $details['amount'] = $amount;
                    $details['target_account_id'] =
                        $authorAccountId;
                    break;

                default:
                    $result = false;
                    break;
            }

            if (!$result) {
                $pdo->rollBack();
                return null;
            }

            if ($cmd !== '!cp') {
                $this->recalculateCreatorPoints(
                    $pdo,
                    $levelId
                );
            }

            $details['moderator_account_id'] = $accountId;

            try {
                $log = $pdo->prepare(
                    "INSERT INTO moderation_logs
                     (moderator_account_id, level_id, action, details)
                     VALUES (:account, :level, :action, :details)"
                );
                $log->execute([
                    ':account' => $accountId,
                    ':level' => $levelId,
                    ':action' =>
                        'COMMENT_COMMAND_' .
                        strtoupper(ltrim($cmd, '!')),
                    ':details' => json_encode(
                        $details,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
                ]);
            } catch (\Throwable) {
                // Optional audit storage must not break a successful command.
            }

            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function recalculateCreatorPoints(
        PDO $pdo,
        int $levelId
    ): void {
        $author = $pdo->prepare(
            "SELECT account_id
             FROM levels
             WHERE level_id = :id
             LIMIT 1"
        );
        $author->execute([":id" => $levelId]);

        $accountId = (int)($author->fetchColumn() ?: 0);

        if ($accountId <= 0) {
            return;
        }

        $cp = $pdo->prepare(
            "SELECT COALESCE(
                SUM(
                    CASE WHEN stars > 0 THEN 1 ELSE 0 END
                    + CASE WHEN featured > 0 THEN 1 ELSE 0 END
                    + epic
                ),
                0
             )
             FROM levels
             WHERE account_id = :account
               AND is_deleted = 0"
        );
        $cp->execute([":account" => $accountId]);

        $update = $pdo->prepare(
            "UPDATE profiles
             SET creator_points = :cp
             WHERE account_id = :account"
        );
        $update->execute([
            ":cp" => (int)$cp->fetchColumn(),
            ":account" => $accountId,
        ]);
    }

    public function getLevelComments(
        int $levelId,
        int $page,
        int $gameVersion = 22,
        int $binaryVersion = 0,
        int $count = 10,
        int $mode = 0
    ): string
    {
        if (!$this->levelExists($levelId)) {
            return '-2';
        }

        $limit = min(100, max(1, $count));
        $page = min(1000, max(0, $page));
        $offset = $page * $limit;
        $comments = $this->repository->getLevelComments(
            $levelId,
            $page,
            $limit,
            $mode !== 0
        );
        $total = $this->repository->countLevelComments($levelId);

        if ($total === 0 || empty($comments)) {
            return "-2";
        }

        $encodedComments = [];
        foreach ($comments as $comment) {
            $role = strtolower((string)($comment["role"] ?? "user"));
            $badge = match ($role) {
                "owner", "admin" => 2,
                "elder_moderator" => 2,
                "moderator" => 1,
                default => 0
            };

            $profile = [
                "username" => $comment["username"] ?? "Unknown",
                "user_id"  => $comment["user_id"] ?? $comment["account_id"] ?? 0,
                "cube"     => $comment["cube"] ?? 1,
                "color1"   => $comment["color1"] ?? 0,
                "color2"   => $comment["color2"] ?? 3,
                "special"  => $comment["special"] ?? 0,
                "icon_type"=> $comment["icon_type"] ?? 0,
                "badge"    => $badge,
                "clan_tag" => $comment["clan_tag"] ?? ''
            ];
            $encodedComments[] = $this->encoder->encode(
                $comment,
                $profile,
                $gameVersion,
                $binaryVersion
            );
        }

        $body = implode("|", $encodedComments);

        if ($binaryVersion < 32) {
            $users = [];
            $seen = [];

            foreach ($comments as $comment) {
                $uid = (int)($comment['user_id'] ?? $comment['account_id'] ?? 0);
                $account = (int)($comment['account_id'] ?? 0);
                $key = $uid . ':' . $account;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $tag = strtoupper(trim((string)($comment['clan_tag'] ?? '')));
                $username = (string)($comment['username'] ?? 'Player');

                if ($tag !== '') {
                    $prefix = '[' . $tag . ']';
                    $username = $prefix . substr(
                        $username,
                        0,
                        max(1, 20 - strlen($prefix))
                    );
                }

                $users[] = $uid . ':' .
                    \MuchoCore\Protocol\ProtocolText::username($username)
                    . ':' . $account;
            }

            $body .= '#' . implode('|', $users);
        }

        return $body
            . '#' . $total . ':' . $offset . ':' . count($comments);
    }

    public function getUserComments(
        int $userId,
        int $page,
        int $count,
        int $mode,
        int $gameVersion,
        int $binaryVersion
    ): string {
        if ($userId <= 0) {
            return '-1';
        }

        $limit = min(100, max(1, $count));
        $page = min(1000, max(0, $page));
        $offset = $page * $limit;
        $orderByLikes = $mode !== 0;

        $comments = $this->repository->getUserComments(
            $userId,
            $page,
            $limit,
            $orderByLikes
        );
        $total = $this->repository->countUserComments($userId);

        if ($total === 0 || $comments === []) {
            return '-2';
        }

        $encoded = [];
        $users = [];
        $seenUsers = [];

        foreach ($comments as $comment) {
            $role = strtolower((string)($comment['role'] ?? 'user'));
            $badge = match ($role) {
                'owner', 'admin', 'elder_moderator' => 2,
                'moderator' => 1,
                default => 0,
            };

            $profile = [
                'username' => $comment['username'] ?? 'Player',
                'user_id' => $comment['user_id'] ?? 0,
                'cube' => $comment['cube'] ?? 1,
                'color1' => $comment['color1'] ?? 0,
                'color2' => $comment['color2'] ?? 3,
                'special' => $comment['special'] ?? 0,
                'icon_type' => $comment['icon_type'] ?? 0,
                'badge' => $badge,
                'account_id' => $comment['account_id'] ?? 0,
                'clan_tag' => $comment['clan_tag'] ?? '',
            ];

            $encodedComment = $this->encoder->encode(
                $comment,
                $profile,
                $gameVersion,
                $binaryVersion
            );

            $encoded[] =
                '1~' . (int)$comment['commented_level_id'] .
                '~' . $encodedComment;

            if ($binaryVersion < 32) {
                $uid = (int)($comment['user_id'] ?? 0);
                $accountId = (int)($comment['account_id'] ?? 0);
                $key = $uid . ':' . $accountId;

                if ($uid > 0 && !isset($seenUsers[$key])) {
                    $seenUsers[$key] = true;
                    $tag = strtoupper(trim((string)($comment['clan_tag'] ?? '')));
                    $username = (string)($comment['username'] ?? 'Player');
                    if ($tag !== '') {
                        $prefix = '[' . $tag . ']';
                        $username = $prefix . substr($username, 0, max(1, 20 - strlen($prefix)));
                    }

                    $users[] =
                        $uid . ':' .
                        \MuchoCore\Protocol\ProtocolText::username($username) .
                        ':' . $accountId;
                }
            }
        }

        $body = implode('|', $encoded);

        if ($binaryVersion < 32) {
            $body .= '#' . implode('|', $users);
        }

        return $body .
            '#' . $total . ':' . $offset . ':' . count($comments);
    }

    public function uploadAccountComment(
        int $accountId,
        string $gjp,
        string $content,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): int
    {
        $accountId = $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );
        if (\MuchoCore\Moderation\Gd2209Restrictions::active(
            $this->getPdo(), $accountId, 'comment'
        )) {
            throw new \RuntimeException('Account temporarily restricted from comments.');
        }
        $decodedContent = GdLegacyText::decodeComment(
            $content,
            $gameVersion
        );
        $decodedContent = $this->normalizeCommentText($decodedContent);

        if ($decodedContent === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }

        $this->repository->addAccountComment(
            $accountId,
            $decodedContent
        );

        return 1;
    }

    public function getAccountComments(int $accountId, int $page, int $gameVersion = 22, int $binaryVersion = 0): string
    {
        $limit = 10;
        $offset = max(0, $page) * $limit;
        $comments = $this->repository->getAccountComments($accountId, $page, $limit);
        $total = $this->repository->countAccountComments($accountId);

        if ($total === 0 || empty($comments)) {
            return '#0:0:0';
        }

        $encoded = [];
        foreach ($comments as $comment) {
            $encoded[] = $this->encoder->encodeAccountComment(
                $comment,
                $gameVersion,
                $binaryVersion
            );
        }

        return implode("|", $encoded) . "#" . $total . ":" . $offset . ":" . count($comments);
    }

    public function deleteComment(
        int $commentId,
        int $accountId,
        string $gjp,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): bool {
        $accountId = $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );

        return $this->repository->deleteLevelComment(
            $commentId,
            $accountId
        );
    }

    public function deleteAccountComment(
        int $commentId,
        int $accountId,
        string $gjp,
        int $gameVersion = 22,
        string $udid = '',
        string $ip = ''
    ): bool {
        $accountId = $this->authenticateCommenter(
            $accountId,
            $gjp,
            $gameVersion,
            $udid,
            $ip
        );

        return $this->repository->deleteAccountComment(
            $commentId,
            $accountId
        );
    }

    private function normalizeCommentText(string $content): string
    {
        $content = str_replace(
            ["\\0", "~", "|", "#", ":"],
            "",
            $content
        );

        $content = preg_replace(
            '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',
            '',
            $content
        ) ?? '';

        $content = trim($content);

        if (
            $content !== '' &&
            mb_strlen($content, 'UTF-8') > 4096
        ) {
            throw new \RuntimeException(
                'Comment is too long.'
            );
        }

        return $content;
    }

    private function levelExists(int $levelId): bool
    {
        $q = $this->getPdo()->prepare(
            'SELECT 1
             FROM levels
             WHERE level_id = :id
               AND is_deleted = 0
             LIMIT 1'
        );

        $q->execute([
            'id' => $levelId
        ]);

        return $q->fetchColumn() !== false;
    }

    private function authenticateCommenter(
        int $accountId,
        string $gjp,
        int $gameVersion,
        string $udid,
        string $ip
    ): int {
        if ($gjp !== '') {
            $account = $this->auth->authenticate($accountId, $gjp);
            return (int)$account['account_id'];
        }

        if (
            $gameVersion > 0 &&
            $gameVersion < 19 &&
            trim($udid) !== '' &&
            $this->legacy10 !== null
        ) {
            /*
             * Cvolton-compatible early clients can submit comments without
             * accountID/gjp and identify themselves by device UDID.
             * Resolve that UDID to MuchoCore's non-privileged legacy identity.
             */
            $resolvedAccountId = $this->legacy10->resolveAccount(
                $udid,
                '',
                $ip
            );

            if ($resolvedAccountId > 0) {
                return $resolvedAccountId;
            }
        }

        if (
            $gameVersion === 19 &&
            $accountId > 0 &&
            trim($udid) !== '' &&
            trim($ip) !== ''
        ) {
            $account = $this->auth->authenticateLegacy19Upload(
                $accountId,
                $udid,
                $ip
            );
            return (int)$account['account_id'];
        }

        throw new RuntimeException('Unauthorized.');
    }
}
