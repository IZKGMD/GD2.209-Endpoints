<?php

declare(strict_types=1);

namespace MuchoCore\Security;

use MuchoCore\Core\Environment;
use MuchoCore\Database\Database;
use MuchoCore\Http\Request;

final readonly class MuchoProtect
{
    private const GLOBAL_LIMIT = 900;
    private const GLOBAL_WINDOW = 60;
    private const GLOBAL_BURST = 180;
    private const GLOBAL_BURST_WINDOW = 10;

    /*
     * A network budget is deliberately wider than the per-IP budget. It is
     * a second line of defense against attackers rotating source addresses
     * inside one IPv4 /24 or IPv6 /64. The factor is intentionally generous
     * so normal shared-NAT traffic is not treated like a single client.
     */
    private const NETWORK_FACTOR = 3;

    /** @var array<string, array{limit:int, window:int, burst:int, burstWindow:int, identityLimit?:int, identityWindow?:int, deviceLimit?:int, deviceWindow?:int}> */
    private const POLICIES = [
        '/logingjaccount' => ['limit' => 12, 'window' => 60, 'burst' => 5, 'burstWindow' => 10],
        // Registration is limited independently by source IP and the client-provided
        // Geometry Dash device identifier (UDID). UDID is not a true hardware
        // fingerprint, so it remains a second layer alongside network controls.
        '/registergjaccount' => [
            'limit' => 6,
            'window' => 300,
            'burst' => 2,
            'burstWindow' => 30,
            'deviceLimit' => 2,
            'deviceWindow' => 86400,
        ],
        '/backupgjaccount' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],
        '/backupgjaccount20' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],
        '/syncgjaccount' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],
        '/syncgjaccount20' => ['limit' => 12, 'window' => 60, 'burst' => 4, 'burstWindow' => 10],

        '/uploadgjlevel21' => ['limit' => 8, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/uploadgjlevel22' => ['limit' => 8, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/deletegjleveluser20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/updategjleveldesc20' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/uploadgjlevellist' => ['limit' => 5, 'window' => 60, 'burst' => 2, 'burstWindow' => 15],
        '/deletegjlevellist' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/updategjusername' => ['limit' => 20, 'window' => 60, 'burst' => 5, 'burstWindow' => 10],

        // Clan API: authenticated writes get both IP and account/device limits.
        '/clans/create' => ['limit' => 4, 'window' => 300, 'burst' => 2, 'burstWindow' => 30],
        '/clans/my' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/clans/get' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/clans/search' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/clans/join' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/leave' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 10],
        '/clans/invite' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/clans/invite/accept' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/invite/decline' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/kick' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/role' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/invites' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/clans/settings' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 10],
        '/clans/transfer' => ['limit' => 4, 'window' => 300, 'burst' => 2, 'burstWindow' => 30],
        '/clans/disband' => ['limit' => 4, 'window' => 300, 'burst' => 2, 'burstWindow' => 30],
        '/clans/invite/revoke' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/ban' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/unban' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/clans/bans' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/appearance/batch' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/clans/quests' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/clans/quests/create' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 10],
        '/clans/quests/cancel' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 10],
        '/clans/quests/contributions' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/clans/activity' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/appearance/my' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/appearance/save' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/profile/get' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/profile/image' => ['limit' => 240, 'window' => 60, 'burst' => 60, 'burstWindow' => 10],
        '/profile/upload' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 10, 'identityLimit' => 10, 'identityWindow' => 60],
        '/profile/remove' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],

        // API v2 uses the same MuchoProtect engine as the legacy transport.
        '/v2' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/v2/health' => ['limit' => 60, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/v2/profile' => ['limit' => 180, 'window' => 60, 'burst' => 40, 'burstWindow' => 10],
        '/v2/client-config' => ['limit' => 180, 'window' => 60, 'burst' => 40, 'burstWindow' => 10],
        '/v2/heartbeat' => ['limit' => 120, 'window' => 60, 'burst' => 20, 'burstWindow' => 10],
        '/v2/music' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/v2/music-upload' => [
            'limit' => 20,
            'window' => 60,
            'burst' => 4,
            'burstWindow' => 10,
            'identityLimit' => 5,
            'identityWindow' => 900,
        ],

        // Read-heavy endpoints: high enough for normal gameplay, low enough
        // to prevent a single client from turning them into a DB flood.
        '/getgjcommenthistory' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjlevellists' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjtopartists' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjlevels21' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/downloadgjlevel21' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/downloadgjlevel22' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjsonginfo' => ['limit' => 180, 'window' => 60, 'burst' => 40, 'burstWindow' => 10],
        '/getgjuserinfo20' => ['limit' => 180, 'window' => 60, 'burst' => 40, 'burstWindow' => 10],
        '/getgjusers20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjscores20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjcomments21' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjaccountcomments20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjmessages20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/downloadgjmessage20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjfriendrequests20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjuserlist20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjcreators' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjcreators19' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjdailylevel' => ['limit' => 60, 'window' => 60, 'burst' => 20, 'burstWindow' => 10],
        '/getgjgauntlets' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjgauntlets21' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjmappacks' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjmappacks20' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjmappacks21' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjlevelscores' => ['limit' => 90, 'window' => 60, 'burst' => 20, 'burstWindow' => 10],
        '/getgjlevelscores211' => ['limit' => 90, 'window' => 60, 'burst' => 20, 'burstWindow' => 10],
        '/getgjlevelscoresplat' => ['limit' => 90, 'window' => 60, 'burst' => 20, 'burstWindow' => 10],
        '/getgjrewards' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],
        '/getgjsecretreward' => ['limit' => 60, 'window' => 60, 'burst' => 20, 'burstWindow' => 10],
        '/getgjchallenges' => ['limit' => 120, 'window' => 60, 'burst' => 30, 'burstWindow' => 10],

        '/uploadgjcomment20' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/uploadgjcomment21' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],
        '/uploadgjacccomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjcomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjaccountcomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjacccomment20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],

        '/uploadgjmessage20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],
        '/deletegjmessages20' => ['limit' => 20, 'window' => 60, 'burst' => 6, 'burstWindow' => 10],

        '/uploadfriendrequest20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/acceptgjfriendrequest20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/blockgjuser20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/unblockgjuser20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/removegjfriend20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/deletegjfriendrequests20' => ['limit' => 30, 'window' => 60, 'burst' => 10, 'burstWindow' => 10],
        '/readgjfriendrequest20' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],

        '/updategjuserscore' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/updategjuserscore22' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/updategjaccsettings20' => ['limit' => 10, 'window' => 60, 'burst' => 3, 'burstWindow' => 15],
        '/requestuseraccess' => ['limit' => 6, 'window' => 300, 'burst' => 2, 'burstWindow' => 30],
        '/rategjstars20' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/rategjstars211' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/rategjdemon21' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/suggestgjstars20' => ['limit' => 40, 'window' => 60, 'burst' => 12, 'burstWindow' => 10],
        '/reportgjlevel' => ['limit' => 30, 'window' => 60, 'burst' => 8, 'burstWindow' => 10],

        '/likegjitem21' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
        '/likegjitem211' => ['limit' => 60, 'window' => 60, 'burst' => 15, 'burstWindow' => 10],
    ];

    private RateLimitBackend $limiter;
    private PenaltyStoreBackend $penalties;

    public function __construct(
        ?RateLimitBackend $limiter = null,
        ?PenaltyStoreBackend $penalties = null
    ) {
        $db = null;
        if (($limiter === null || $penalties === null) && $this->storageMode() === 'db') {
            $db = (new Database())->connection();
        }

        $this->limiter = $limiter
            ?? ($db !== null ? new DatabaseRateLimiter($db) : new RateLimiter());

        if ($penalties !== null) {
            $this->penalties = $penalties;
        } elseif ($db !== null) {
            $this->penalties = new DatabasePenaltyStore($db);
        } else {
            $directory = Environment::get('MUCHO_PROTECT_PENALTY_DIR')
                ?: dirname(__DIR__, 2) . '/storage/control/protect-penalties';
            $this->penalties = new AbusePenaltyStore($directory);
        }
    }

    public function storageMode(): string
    {
        $value = strtolower(trim((string)(Environment::get('MUCHO_PROTECT_STORAGE', 'file') ?? 'file')));
        return in_array($value, ['db','database','mysql','mariadb'], true) ? 'db' : 'file';
    }

    /** @return array{decision:'allow'|'block', reason:string} */
    public function inspect(Request $request, string $endpoint): array
    {
        $endpoint = $this->normalizeEndpoint($endpoint);

        if (!$this->enabled() || $this->isExempt($endpoint)) {
            return ['decision' => 'allow', 'reason' => 'disabled_or_exempt'];
        }

        $ip = $request->clientIp();
        $identityKeys = $this->identityKeys($request);

        $ipPenaltyKey = 'ip:' . $ip . ':endpoint:' . $endpoint;
        $ipPenalty = $this->penalties->status($ipPenaltyKey);

        if ($ipPenalty['active']) {
            $this->audit(
                $request,
                $endpoint,
                'temporary_penalty',
                $ipPenalty['remaining'],
                $ipPenalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'temporary_penalty'];
        }

        $network = $this->networkKey($ip);
        $networkPenaltyKey = $network === null
            ? null
            : 'network:' . $network . ':endpoint:' . $endpoint;

        if ($networkPenaltyKey !== null) {
            $networkPenalty = $this->penalties->status($networkPenaltyKey);

            if ($networkPenalty['active']) {
                $this->audit(
                    $request,
                    $endpoint,
                    'network_penalty',
                    $networkPenalty['remaining'],
                    $networkPenalty['strikes']
                );
                return ['decision' => 'block', 'reason' => 'network_penalty'];
            }
        }

        foreach ($identityKeys as $kind => $identityKey) {
            $penaltyKey = $kind . ':' . $identityKey . ':endpoint:' . $endpoint;
            $identityPenalty = $this->penalties->status($penaltyKey);

            if ($identityPenalty['active']) {
                $this->audit(
                    $request,
                    $endpoint,
                    $kind . '_penalty',
                    $identityPenalty['remaining'],
                    $identityPenalty['strikes']
                );
                return ['decision' => 'block', 'reason' => $kind . '_penalty'];
            }
        }

        $globalKey = 'ip:' . $ip . ':global';

        if (!$this->allow(
            $globalKey,
            self::GLOBAL_LIMIT,
            self::GLOBAL_WINDOW
        )) {
            $penalty = $this->penalties->penalize($globalKey);
            $this->audit(
                $request,
                $endpoint,
                'global_rate_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'global_rate_limit'];
        }

        if (!$this->allow(
            $globalKey . ':burst',
            self::GLOBAL_BURST,
            self::GLOBAL_BURST_WINDOW
        )) {
            $penalty = $this->penalties->penalize($globalKey);
            $this->audit(
                $request,
                $endpoint,
                'global_burst_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'global_burst_limit'];
        }

        $policy = self::POLICIES[$endpoint] ?? null;

        if ($policy === null) {
            return ['decision' => 'allow', 'reason' => 'no_policy'];
        }

        if ($networkPenaltyKey !== null) {
            $networkLimit = $this->scaledLimit($policy['limit']);
            $networkBurst = $this->scaledLimit($policy['burst']);

            if (!$this->allow(
                $networkPenaltyKey,
                $networkLimit,
                $policy['window']
            )) {
                $penalty = $this->penalties->penalize($networkPenaltyKey);
                $this->audit(
                    $request,
                    $endpoint,
                    'network_rate_limit',
                    $penalty['seconds'],
                    $penalty['strikes']
                );
                return ['decision' => 'block', 'reason' => 'network_rate_limit'];
            }

            if (!$this->allow(
                $networkPenaltyKey . ':burst',
                $networkBurst,
                $policy['burstWindow']
            )) {
                $penalty = $this->penalties->penalize($networkPenaltyKey);
                $this->audit(
                    $request,
                    $endpoint,
                    'network_burst_limit',
                    $penalty['seconds'],
                    $penalty['strikes']
                );
                return ['decision' => 'block', 'reason' => 'network_burst_limit'];
            }
        }

        if (!$this->allow(
            $ipPenaltyKey,
            $policy['limit'],
            $policy['window']
        )) {
            $penalty = $this->penalties->penalize($ipPenaltyKey);
            $this->audit(
                $request,
                $endpoint,
                'ip_rate_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'ip_rate_limit'];
        }

        if (!$this->allow(
            $ipPenaltyKey . ':burst',
            $policy['burst'],
            $policy['burstWindow']
        )) {
            $penalty = $this->penalties->penalize($ipPenaltyKey);
            $this->audit(
                $request,
                $endpoint,
                'burst_limit',
                $penalty['seconds'],
                $penalty['strikes']
            );
            return ['decision' => 'block', 'reason' => 'burst_limit'];
        }

        foreach ($identityKeys as $kind => $identityKey) {
            $identityRateKey = $kind . ':' . $identityKey . ':endpoint:' . $endpoint;
            if (
                $kind === 'device' &&
                isset($policy['deviceLimit'])
            ) {
                $identityLimit = $policy['deviceLimit'];
                $identityWindow = $policy['deviceWindow'] ?? $policy['window'];
            } else {
                $identityLimit = $policy['identityLimit'] ?? $policy['limit'];
                $identityWindow = $policy['identityWindow'] ?? $policy['window'];

                // Registration has an explicit device budget only. Do not turn
                // username/email into hidden registration quotas.
                if (
                    $endpoint === '/registergjaccount' &&
                    !isset($policy['identityLimit'])
                ) {
                    continue;
                }
            }

            if (!$this->allow(
                $identityRateKey,
                $identityLimit,
                $identityWindow
            )) {
                $penalty = $this->penalties->penalize($identityRateKey);
                $this->audit(
                    $request,
                    $endpoint,
                    $kind . '_rate_limit',
                    $penalty['seconds'],
                    $penalty['strikes']
                );
                return [
                    'decision' => 'block',
                    'reason' => $kind . '_rate_limit'
                ];
            }
        }

        return ['decision' => 'allow', 'reason' => 'ok'];
    }

    private function scaledLimit(int $limit): int
    {
        return max($limit + 1, $limit * self::NETWORK_FACTOR);
    }

    private function networkKey(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);

            if (count($octets) === 4) {
                return $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0/24';
            }

            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return null;
            }

            return bin2hex(substr($packed, 0, 8)) . '/64';
        }

        return null;
    }

    private function allow(
        string $key,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($this->failOpen()) {
            return $this->limiter->allow(
                $key,
                $limit,
                $windowSeconds
            );
        }

        return $this->limiter->allowStrict(
            $key,
            $limit,
            $windowSeconds
        );
    }

    private function failOpen(): bool
    {
        $value = $this->env('MUCHO_PROTECT_FAIL_OPEN');

        return $value !== null &&
            in_array(
                strtolower($value),
                ['1', 'true', 'on', 'yes'],
                true
            );
    }

    private function enabled(): bool
    {
        $value = $this->env('MUCHO_PROTECT');

        if ($value === null) {
            return true;
        }

        return !in_array(
            strtolower($value),
            ['0', 'false', 'off', 'no'],
            true
        );
    }

    private function isExempt(string $endpoint): bool
    {
        return in_array($endpoint, [
            '/health',
            '/checkifserveronline',
            '/getaccounturl',
            '/getcustomcontenturl',
        ], true);
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH);
        $endpoint = is_string($path) ? $path : '/';
        $endpoint = preg_replace('#/+#', '/', $endpoint) ?? '/';
        $endpoint = preg_replace(
            '#^/(?:database|accounts|api|a)(?:/|$)#i',
            '/',
            $endpoint
        ) ?? $endpoint;
        $endpoint = preg_replace('#\.php$#i', '', $endpoint) ?? $endpoint;

        if ($endpoint !== '/') {
            $endpoint = rtrim($endpoint, '/');
        }

        $endpoint = strtolower($endpoint === '' ? '/' : $endpoint);

        return substr($endpoint, 0, 256);
    }

    /**
     * Build non-spoofable request identities without ever trusting accountID
     * on its own. Authenticated requests use accountID + credential; pre-auth
     * requests use username/email, while legacy clients may contribute a UDID.
     *
     * @return array<string,string> kind => stable hashed identity
     */
    private function identityKeys(Request $request): array
    {
        $keys = [];

        $accountId = $this->accountId($request);
        $credential = $request->gdCredential();

        if ($accountId !== null && $credential !== '') {
            $keys['account'] = hash(
                'sha256',
                'account:' . $accountId . ':' . $credential
            );
        }

        $username = $this->inputString($request, 'userName');
        if ($username === '') {
            $username = $this->inputString($request, 'username');
        }

        $email = $this->inputString($request, 'email');
        $udid = $this->inputString($request, 'udid');

        if ($username !== '') {
            $keys['username'] = hash(
                'sha256',
                'username:' . strtolower(trim($username))
            );
        }

        if ($email !== '') {
            $keys['email'] = hash(
                'sha256',
                'email:' . strtolower(trim($email))
            );
        }

        if ($udid !== '') {
            $keys['device'] = hash(
                'sha256',
                'udid:' . trim($udid)
            );
        }

        return $keys;
    }

    private function inputString(Request $request, string $key): string
    {
        $value = $request->post[$key] ?? $request->query[$key] ?? '';

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 256) {
            return '';
        }

        return $value;
    }

    private function accountId(Request $request): ?int
    {
        foreach (['accountID', 'accountId'] as $key) {
            $value = $request->post[$key] ?? $request->query[$key] ?? null;

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (
                is_string($value) &&
                preg_match('/^\d+$/', $value) === 1 &&
                (int)$value > 0
            ) {
                return (int)$value;
            }
        }

        return null;
    }

    private function env(string $key): ?string
    {
        $value = Environment::get($key);

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function audit(
        Request $request,
        string $endpoint,
        string $reason,
        int $penaltySeconds = 0,
        int $strikes = 0
    ): void {
        $directory = $this->env('MUCHO_PROTECT_AUDIT_DIR')
            ?: dirname(__DIR__, 2) . '/storage/control/protect-audit';

        if (
            !is_dir($directory) &&
            !@mkdir($directory, 0700, true) &&
            !is_dir($directory)
        ) {
            return;
        }

        try {
            $entry = json_encode([
                'time' => gmdate('c'),
                'endpoint' => $endpoint,
                'reason' => $reason,
                'ip_hash' => hash('sha256', $request->clientIp()),
                'method' => $request->method,
                'penalty_seconds' => $penaltySeconds,
                'strikes' => $strikes,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

            @file_put_contents(
                $directory . '/events.ndjson',
                $entry,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // Security telemetry must never break the game protocol.
        }
    }
}
