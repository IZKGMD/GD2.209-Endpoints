<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Cache\CacheInterface;
use MuchoCore\Job\JobQueue;
use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Protocol\GdLegacyText;
use MuchoCore\Search\LevelSearchIndexer;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use PDO;
use RuntimeException;
use Throwable;

final readonly class LevelTransferService
{
    private const MAX_LEVEL_DATA = 32 * 1024 * 1024;
    private const MAX_LEVEL_INFO = 1024 * 1024;

    public function __construct(
        private PDO $pdo,
        private AccountAuthenticator $auth,
        private Legacy10IdentityService $legacy10,
        private LevelTransferRepository $repository,
        private LevelDownloadTracker $downloadTracker,
        private GdLevelDownloadEncoder $downloadEncoder,
        private LevelValidator $validator,
        private LevelRevisionService $revisions,
        private LevelSearchIndexer $searchIndexer,
        private CacheInterface $cache,
        private JobQueue $jobs
    ) {}

    public function upload(
        int $accountId,
        string $gjp,
        array $data,
        string $udid = '',
        string $ip = ''
    ): int {
        $version = ClientVersion::fromValues(
            $this->intField($data, 'gameVersion', 1, 1, 1000),
            $this->intField($data, 'binaryVersion', 0, 0, 1000000)
        );

        if ($gjp !== '') {
            if ($accountId <= 0) {
                throw new RuntimeException('Unauthorized.');
            }

            $this->auth->authenticate($accountId, $gjp);
        } elseif (
            $version->effectiveGameVersion() > 0 &&
            $version->effectiveGameVersion() < 19 &&
            trim($udid) !== ''
        ) {
            /*
             * Cvolton-compatible legacy clients before 1.9 may upload
             * without an account credential. Map each UDID to an internal,
             * non-privileged account identity so existing level ownership,
             * moderation and protocol encoders remain intact.
             */
            $accountId = $this->legacy10->resolveAccount(
                $udid,
                $this->stringField($data, 'userName', '', 20),
                $ip
            );
        } else {
            throw new RuntimeException('Unauthorized.');
        }

        if (\MuchoCore\Moderation\Gd2209Restrictions::active(
            $this->pdo, $accountId, 'upload'
        )) {
            throw new RuntimeException('Account temporarily restricted from uploads.');
        }

        $levelId = $this->intField(
            $data,
            'levelID',
            0,
            0,
            PHP_INT_MAX
        );

        /* Never allow a client to update another user's level by ID alone. */
        $existing = null;

        if ($levelId > 0) {
            $existing = $this->repository->findLevel($levelId);

            if (
                !$existing ||
                (int)($existing['account_id'] ?? 0) !== $accountId
            ) {
                throw new RuntimeException('Level ownership mismatch.');
            }
        }

        $name = trim(
            $this->stringField(
                $data,
                'levelName',
                'Unnamed',
                64
            )
        );

        if ($name === '') {
            $name = 'Unnamed';
        }

        if (
            preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new RuntimeException('Invalid level name.');
        }

        $levelString = $this->stringField(
            $data,
            'levelString',
            '',
            self::MAX_LEVEL_DATA
        );

        if ($levelString === '') {
            throw new RuntimeException('Empty level data.');
        }

        $levelData = [
            'account_id' => $accountId,

            'name' => $name,

            'description' => GdLegacyText::encodeDescriptionForStorage(
                $this->stringField(
                    $data,
                    'levelDesc',
                    '',
                    8192
                ),
                $version->effectiveGameVersion()
            ),

            'level_version' => $this->intField(
                $data,
                'levelVersion',
                1,
                1,
                1000000
            ),

            'game_version' => $this->intField(
                $data,
                'gameVersion',
                22,
                1,
                1000
            ),

            'binary_version' => $this->intField(
                $data,
                'binaryVersion',
                0,
                0,
                1000000
            ),

            'length' => $this->intField(
                $data,
                'levelLength',
                0,
                0,
                10
            ),

            'audio_track' => $this->intField(
                $data,
                'audioTrack',
                0,
                0,
                1000000
            ),

            'song_id' => $this->intField(
                $data,
                'songID',
                0,
                0,
                PHP_INT_MAX
            ),

            'auto_level' => $this->boolInt($data, 'auto'),

            'copy_password' => $this->stringField(
                $data,
                'password',
                '0',
                64
            ),

            'original_level_id' => $this->intField(
                $data,
                'original',
                0,
                0,
                PHP_INT_MAX
            ),

            'two_player' => $this->boolInt(
                $data,
                'twoPlayer'
            ),

            'object_count' => $this->intField(
                $data,
                'objects',
                0,
                0,
                5000000
            ),

            'coins' => $this->intField(
                $data,
                'coins',
                0,
                0,
                1000
            ),

            'requested_stars' => $this->intField(
                $data,
                'requestedStars',
                0,
                0,
                100
            ),

            'is_unlisted' => $this->boolInt(
                $data,
                isset($data['unlisted1']) ? 'unlisted1' : 'unlisted'
            ),

            'unlisted2' => $this->boolInt(
                $data,
                isset($data['unlisted2'])
                    ? 'unlisted2'
                    : (isset($data['unlisted1']) ? 'unlisted1' : 'unlisted')
            ),

            'wt' => $this->intField(
                $data,
                'wt',
                0,
                0,
                PHP_INT_MAX
            ),

            'wt2' => $this->intField(
                $data,
                'wt2',
                0,
                0,
                PHP_INT_MAX
            ),

            'extra_string' => $this->stringField(
                $data,
                'extraString',
                '29_29_29_40_29_29_29_29_29_29_29_29_29_29_29_29',
                65536
            ),

            'level_data' => $levelString,

            'level_info' => $this->stringField(
                $data,
                'levelInfo',
                '',
                self::MAX_LEVEL_INFO
            ),

            'settings_string' => $this->stringField(
                $data,
                'settingsString',
                '',
                self::MAX_LEVEL_INFO
            ),

            'song_ids' => $this->stringField(
                $data,
                'songIDs',
                '',
                65536
            ),

            'sfx_ids' => $this->stringField(
                $data,
                'sfxIDs',
                '',
                65536
            ),

            'ldm' => $this->boolInt($data, 'ldm'),

            'ts' => $this->intField(
                $data,
                'ts',
                0,
                0,
                PHP_INT_MAX
            )
        ];

        if ($levelId > 0) {
            $levelData['level_id'] = $levelId;
        }

        $validation = $this->validator::validate($levelData);
        if (!$validation['ok']) {
            throw new RuntimeException(
                'LEVEL_INVALID:' . implode(',', $validation['errors'])
            );
        }

        $savedId = $this->repository->saveLevel($levelData);
        $saved = $this->repository->findLevel($savedId);

        if ($saved !== null) {
            $this->revisions->record(
                $saved,
                $accountId,
                $existing === null ? 'upload' : 'update'
            );
            $this->searchIndexer->upsert($savedId);
            $this->cache->deletePrefix('mucho:levels:');

            try {
                $this->jobs->enqueue(
                    'level.index',
                    ['level_id' => $savedId]
                );
                $this->jobs->enqueue(
                    'webhook.dispatch',
                    [
                        'event' => $existing === null ? 'level.uploaded' : 'level.updated',
                        'request_id' => (string)($_SERVER['MUCHO_REQUEST_ID'] ?? ''),
                        'data' => [
                            'level_id' => $savedId,
                            'account_id' => $accountId,
                            'name' => (string)($saved['name'] ?? ''),
                            'game_version' => (int)($saved['game_version'] ?? 0),
                        ],
                    ]
                );
            } catch (Throwable) {
                // Background work is best-effort and must not break GD traffic.
            }
        }

        return $savedId;
    }

    public function checkUpdate(
        int $levelId,
        int $clientLevelVersion
    ): string {
        if ($levelId <= 0 || $clientLevelVersion <= 0) {
            return '-1';
        }

        $level = $this->repository->findLevel($levelId);

        if ($level === null) {
            return '-1';
        }

        $serverLevelVersion = max(
            1,
            (int)($level['level_version'] ?? 1)
        );

        return $clientLevelVersion >= $serverLevelVersion
            ? '1'
            : '2';
    }


    public function download(
        int $levelId,
        int $gameVersion,
        int $binaryVersion,
        bool $extras,
        bool $incrementDownloads,
        int $viewerAccountId = 0,
        string $viewerCredential = '',
        string $clientIp = ''
    ): string {
        $timelyId=0;

        if($levelId<0){

            $timely=$this->resolveTimelyLevel(
                $levelId
            );

            if($timely===null){
                return '-1';
            }

            $levelId=(int)$timely['level_id'];
            $timelyId=(int)$timely['timely_id'];
        }

        if($levelId<=0){
            return '-1';
        }

        $level=$this->repository->findLevel(
            $levelId
        );

        if(!$level){
            return '-1';
        }

        if ((int)($level['unlisted2'] ?? 0) !== 0) {
            if ($viewerAccountId <= 0 || trim($viewerCredential) === '') {
                return '-1';
            }

            try {
                $this->auth->authenticate(
                    $viewerAccountId,
                    $viewerCredential
                );
            } catch (\Throwable) {
                return '-1';
            }

            $ownerAccountId = (int)($level['account_id'] ?? 0);

            if ($viewerAccountId !== $ownerAccountId) {
                $friend = $this->pdo->prepare(
                    'SELECT 1
                     FROM friends
                     WHERE (account_id=:viewer AND friend_account_id=:owner)
                        OR (account_id=:owner AND friend_account_id=:viewer)
                     LIMIT 1'
                );
                $friend->execute([
                    'viewer' => $viewerAccountId,
                    'owner' => $ownerAccountId,
                ]);

                if ($friend->fetchColumn() === false) {
                    return '-1';
                }
            }
        }

        if ($incrementDownloads) {
            $this->downloadTracker->record(
                $levelId,
                $clientIp
            );
        }

        $response=$this->downloadEncoder->encode(
            $level,
            $gameVersion,
            $extras,
            $binaryVersion
        );

        if(
            $timelyId>0 &&
            $response!=='-1'
        ){
            $response=$this->decorateTimelyResponse(
                $response,
                $timelyId,
                $gameVersion,
                $level
            );
        }

        return $response;
    }


    private function resolveTimelyLevel(
        int $negativeId
    ): ?array {

        $kind=match($negativeId){
            -1 => 'daily',
            -2 => 'weekly',
            -3 => 'event',
            default => null,
        };

        if($kind===null){
            return null;
        }

        $q=$this->pdo->prepare("
            SELECT
                id,
                level_id

            FROM mucho_daily_rotation

            WHERE kind=:kind
              AND enabled=1
              AND starts_at<=UTC_TIMESTAMP()
              AND ends_at>UTC_TIMESTAMP()

            ORDER BY
                starts_at DESC,
                id DESC

            LIMIT 1
        ");

        $q->execute([
            'kind'=>$kind
        ]);

        $row=$q->fetch(PDO::FETCH_ASSOC);

        if(!$row){
            return null;
        }

        $timelyId=(int)$row['id'];

        if($negativeId===-2){
            $timelyId+=100001;
        }elseif($negativeId===-3){
            $timelyId+=200001;
        }

        return [
            'level_id'=>(int)$row['level_id'],
            'timely_id'=>$timelyId,
        ];
    }


    private function decorateTimelyResponse(
        string $response,
        int $timelyId,
        int $gameVersion,
        array $level
    ): string {

        $parts=explode('#',$response);

        if(count($parts)<3){
            return $response;
        }

        $main=$parts[0];

        if(
            strpos(
                $main,
                ':41:'
            )===false
        ){
            $main.=':41:'.$timelyId;
        }

        $parts[0]=$main;

        /*
         * Rebuild download hash #2 because
         * timely ID is one of its inputs.
         */

        $userId=$this->protocolInt(
            $main,
            6,
            (int)(
                $level['user_id']
                ?? $level['account_id']
                ?? 0
            )
        );

        $stars=$this->protocolInt(
            $main,
            18,
            (int)($level['stars'] ?? 0)
        );

        $demon=$this->protocolInt(
            $main,
            17,
            (int)($level['demon'] ?? 0)
        );

        $levelId=$this->protocolInt(
            $main,
            1,
            (int)($level['level_id'] ?? 0)
        );

        $verifiedCoins=$this->protocolInt(
            $main,
            38,
            (int)($level['coins_verified'] ?? 0)
        );

        $featured=$this->protocolInt(
            $main,
            19,
            (int)($level['featured'] ?? 0)
        );

        $encodedPassword=$this->protocolString(
            $main,
            27,
            '0'
        );

        $password=$this->decodePassword(
            $encodedPassword,
            $gameVersion
        );

        $passwordInt=
            preg_match('/^-?\d+$/',$password)
                ? (int)$password
                : 0;

        $hashInput=
            $userId.
            $stars.
            $demon.
            $levelId.
            $verifiedCoins.
            $featured.
            $passwordInt.
            $timelyId;

        $parts[2]=sha1(
            $hashInput.
            'xI25fpAapCQg'
        );


        /*
         * Daily downloads traditionally append
         * creator information after hash #2.
         */
        if(count($parts)===3){

            $creator=$this->creatorString(
                (int)($level['account_id'] ?? 0)
            );

            if($creator!==''){
                $parts[]=$creator;
            }
        }

        return implode('#',$parts);
    }


    private function protocolInt(
        string $data,
        int $key,
        int $fallback
    ): int {

        $value=$this->protocolString(
            $data,
            $key,
            ''
        );

        if(
            $value==='' ||
            preg_match('/^-?\d+$/',$value)!==1
        ){
            return $fallback;
        }

        return (int)$value;
    }


    private function protocolString(
        string $data,
        int $key,
        string $fallback
    ): string {

        if(
            preg_match(
                '/(?:^|:)'.preg_quote(
                    (string)$key,
                    '/'
                ).':([^:#]*)/',
                $data,
                $m
            )===1
        ){
            return (string)$m[1];
        }

        return $fallback;
    }


    private function decodePassword(
        string $value,
        int $gameVersion
    ): string {

        if(
            $value==='0' ||
            $value==='1' ||
            $gameVersion<=19
        ){
            return $value;
        }

        $encoded=strtr(
            $value,
            '-_',
            '+/'
        );

        $mod=strlen($encoded)%4;

        if($mod!==0){
            $encoded.=
                str_repeat('=',4-$mod);
        }

        $raw=base64_decode(
            $encoded,
            true
        );

        if($raw===false){
            return '0';
        }

        $key='26364';
        $out='';
        $keyLength=strlen($key);

        for(
            $i=0,
            $len=strlen($raw);
            $i<$len;
            $i++
        ){
            $out.=chr(
                ord($raw[$i]) ^
                ord($key[$i%$keyLength])
            );
        }

        return $out;
    }


    private function creatorString(
        int $accountId
    ): string {

        if($accountId<=0){
            return '';
        }

        $q=$this->pdo->prepare("
            SELECT
                a.account_id,
                a.username,
                COALESCE(
                    p.user_id,
                    a.account_id
                ) AS user_id

            FROM accounts a

            LEFT JOIN profiles p
              ON p.account_id=a.account_id

            WHERE a.account_id=:id

            LIMIT 1
        ");

        $q->execute([
            'id'=>$accountId
        ]);

        $row=$q->fetch(PDO::FETCH_ASSOC);

        if(!$row){
            return '';
        }

        return
            (int)$row['user_id'].
            ':'.
            \MuchoCore\Protocol\ProtocolText::username(
                $row['username'] ?? 'Player'
            ).
            ':'.
            (int)$row['account_id'];
    }


    public function updateDescription(
        int $levelId,
        int $accountId,
        string $gjp,
        string $description,
        int $gameVersion = 22
    ): bool {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        if ($levelId <= 0 || $accountId <= 0) {
            return false;
        }

        if (strlen($description) > 8192) {
            throw new RuntimeException(
                'Level description too large.'
            );
        }

        $existing = $this->repository->findLevel(
            $levelId
        );

        if (
            !$existing ||
            (int)($existing['account_id'] ?? 0) !== $accountId
        ) {
            return false;
        }

        $updated = $this->repository->updateDescription(
            $levelId,
            $accountId,
            GdLegacyText::encodeDescriptionUpdateForStorage(
                $description,
                $gameVersion
            )
        );

        if ($updated) {
            $this->searchIndexer->upsert($levelId);
            $this->cache->deletePrefix('mucho:levels:');
        }

        return $updated;
    }

    public function delete(
        int $levelId,
        int $accountId,
        string $gjp
    ): bool {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        $existing = $this->repository->findLevel($levelId);

        if (
            !$existing ||
            (int)($existing['account_id'] ?? 0) !== $accountId
        ) {
            return false;
        }

        // GD legacy clients cannot delete rated/starred levels.
        if ((int)($existing['stars'] ?? 0) > 0) {
            return false;
        }

        $deleted = $this->repository->deleteLevel(
            $levelId,
            $accountId
        );

        if ($deleted) {
            $this->searchIndexer->delete($levelId);
            $this->cache->deletePrefix('mucho:levels:');
            try {
                $this->jobs->enqueue(
                    'webhook.dispatch',
                    [
                        'event' => 'level.deleted',
                        'request_id' => (string)($_SERVER['MUCHO_REQUEST_ID'] ?? ''),
                        'data' => [
                            'level_id' => $levelId,
                            'account_id' => $accountId,
                        ],
                    ]
                );
            } catch (Throwable) {
            }
        }

        return $deleted;
    }

    private function stringField(
        array $data,
        string $key,
        string $default,
        int $maxLength
    ): string {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        if (!is_scalar($data[$key])) {
            throw new RuntimeException(
                'Invalid field: ' . $key
            );
        }

        $value = (string)$data[$key];

        if (strlen($value) > $maxLength) {
            throw new RuntimeException(
                'Field too large: ' . $key
            );
        }

        return $value;
    }

    private function intField(
        array $data,
        string $key,
        int $default,
        int $min,
        int $max
    ): int {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (
            !(is_int($value) || is_string($value)) ||
            preg_match('/^-?\d+$/', (string)$value) !== 1
        ) {
            throw new RuntimeException(
                'Invalid integer: ' . $key
            );
        }

        $number = (int)$value;

        if ($number < $min || $number > $max) {
            throw new RuntimeException(
                'Integer out of range: ' . $key
            );
        }

        return $number;
    }

    private function boolInt(
        array $data,
        string $key
    ): int {
        if (!array_key_exists($key, $data)) {
            return 0;
        }

        return ((string)$data[$key] === '1') ? 1 : 0;
    }
}
