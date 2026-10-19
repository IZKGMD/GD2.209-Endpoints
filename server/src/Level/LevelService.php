<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Cache\CacheInterface;
use MuchoCore\Protocol\GdLevelListEncoder;

final readonly class LevelService
{
    private const PAGE_SIZE = 10;

    public function __construct(
        private LevelRepository $levels,
        private GdLevelListEncoder $encoder,
        private CacheInterface $cache,
    ) {
    }

    public function getLevels(array $input): string
    {
        $type = $this->integer($input['type'] ?? 0);
        $page = min(
            1000,
            max(0, $this->integer($input['page'] ?? 0))
        );
        $gameVersion = max(
            0,
            $this->integer($input['gameVersion'] ?? 0)
        );

        $binaryVersion = max(
            0,
            $this->integer($input['binaryVersion'] ?? 0)
        );

        $version = \MuchoCore\Compatibility\ClientVersion::fromValues(
            $gameVersion,
            $binaryVersion
        );
        $gameVersion = $version->effectiveGameVersion();

        $demonFilter = max(
            0,
            $this->integer($input['demonFilter'] ?? 0)
        );

        $search = $input['str'] ?? '';

        if (!is_string($search)) {
            $search = '';
        }

        $search = trim($search);

        if (strlen($search) > 100) {
            return '-1';
        }

        $offset = $page * self::PAGE_SIZE;

        $cacheable = $this->cacheable($type, $input);
        $cacheKey = '';

        if ($cacheable) {
            $cacheInput = [];
            foreach ($input as $key => $value) {
                if (in_array((string)$key, ['gjp','gjp2','password','email'], true)) {
                    continue;
                }
                if (is_scalar($value)) {
                    $cacheInput[(string)$key] = (string)$value;
                }
            }
            $cacheKey = 'mucho:levels:' . hash(
                'sha256',
                json_encode([
                    'type' => $type,
                    'page' => $page,
                    'game_version' => $gameVersion,
                    'demon_filter' => $demonFilter,
                    'input' => $cacheInput,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            );
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->levels->search(
            type: $type,
            search: $search,
            gameVersion: $gameVersion,
            offset: $offset,
            limit: self::PAGE_SIZE,
            demonFilter: $demonFilter,
            input: $input,
        );

        if (empty($result['levels'])) {
            $encoded = '-2';
        } else {
            $encoded = $this->encoder->encode(
                levels: $result['levels'],
                total: $result['total'],
                offset: $offset,
                limit: self::PAGE_SIZE,
                gameVersion: $gameVersion,
                binaryVersion: $binaryVersion,
            );
        }

        if ($cacheable && $cacheKey !== '') {
            $ttl = max(1, min(300, (int)(getenv('MUCHO_LEVEL_CACHE_TTL') ?: 15)));
            $this->cache->set($cacheKey, $encoded, $ttl);
        }

        return $encoded;
    }

    private function cacheable(int $type, array $input): bool
    {
        if (in_array($type, [5, 12, 13, 25, 26, 27], true)) {
            return false;
        }

        foreach (['accountID','followed','completedLevels','gauntlet'] as $key) {
            if (isset($input[$key]) && (string)$input[$key] !== '' && (string)$input[$key] !== '0') {
                return false;
            }
        }

        return in_array(
            $type,
            [0, 1, 2, 3, 6, 11, 15, 16, 17, 21, 22, 23],
            true
        );
    }

    private function integer(mixed $value): int
    {
        if (
            is_int($value) ||
            (is_string($value) && preg_match('/^-?\d+$/', $value))
        ) {
            return (int) $value;
        }

        return 0;
    }
}
