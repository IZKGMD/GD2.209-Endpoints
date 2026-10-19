<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Moderation\Gd2209Restrictions;
use PDO;
use RuntimeException;

/**
 * Staged 2.209 utilities. Byte-level reply encoding remains unverified.
 *
 * No fake song/SFX size metadata: unknown IDs reject the request. Opening
 * a registration screen is a non-authorizing event, not account creation.
 */
final readonly class Gd2209UtilityService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth
    ) {}

    /** @return list<int> */
    private static function ids(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        if (strlen($raw) > 1024 ||
            !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,63}$/D', $raw)) {
            throw new RuntimeException('Malformed ID list');
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new RuntimeException('Bad ID');
            }
        }
        return $ids;
    }

    public function audioSizes(string $songs, string $sounds): string
    {
        $songIds = self::ids($songs);
        $sfxIds = self::ids($sounds);
        if ($songIds === [] && $sfxIds === []) {
            throw new RuntimeException('No audio IDs');
        }
        $a = [];
        $b = [];
        foreach ($songIds as $id) {
            $q = $this->db->prepare(
                'SELECT size FROM songs WHERE id=:id AND is_verified=1 LIMIT 1'
            );
            $q->execute(['id'=>$id]);
            $size = $q->fetchColumn();
            if ($size === false || !is_numeric($size)
                || (float)$size <= 0 || (float)$size > 10000) {
                throw new RuntimeException('Unknown or invalid song byte size');
            }
            // Existing MuchoCore songs.size is measured in MiB.
            $a[] = $id . ':' . (int)round((float)$size * 1048576);
        }
        foreach ($sfxIds as $id) {
            // Only custom editor audio uploads are backed by local bytes.
            // Native RobTop CDN SFX IDs are NOT assumed to be these IDs.
            $q = $this->db->prepare(
                'SELECT size_bytes FROM mucho_editor_sounds
                 WHERE id=:id LIMIT 1'
            );
            $q->execute(['id'=>$id]);
            $size = $q->fetchColumn();
            if ($size === false || (int)$size < 1) {
                throw new RuntimeException('Unknown editor SFX byte size');
            }
            $b[] = $id . ':' . (int)$size;
        }
        // Provisional envelope until native parser capture.
        return implode(',', $a) . '#' . implode(',', $b);
    }

    public function contentStatus(
        int $actor, string $credential, int $special
    ): string {
        $account = $this->auth->authenticate($actor, $credential);
        if (!in_array($special, [0, 1], true)) {
            throw new RuntimeException('Invalid content status selector');
        }
        $comment = Gd2209Restrictions::active($this->db, $actor, 'comment') ? 1 : 0;
        $upload = Gd2209Restrictions::active($this->db, $actor, 'upload') ? 1 : 0;
        $message = Gd2209Restrictions::active($this->db, $actor, 'message') ? 1 : 0;
        // Provisional positional status flags, derived from actual persisted state.
        return '1:' . (int)$account['account_id'] . ':2:' . $comment
            . ':3:' . $upload . ':4:' . $message;
    }

    /**
     * GD Docs 2.209 registration-open operation:
     * POST requires the account secret only; reply is 32 hex characters.
     * Token hashes are retained for future registration handshake validation.
     * A registration response must never blindly trust a supplied rsession.
     */
    public function openedRegister(string $secret): string
    {
        if (!hash_equals('Wmfv3899gc9', $secret)) {
            throw new RuntimeException('Invalid account secret');
        }
        $token = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $token, true);

        // No user/device ID accompanies the native request. Do not invent one,
        // or derive a reversible hash of the caller's IP address.
        $anonymous = random_bytes(32);
        $cleanup = $this->db->prepare(
            'DELETE FROM mucho_gd2209_registration_opens
             WHERE expires_at < UTC_TIMESTAMP() LIMIT 1000'
        );
        $cleanup->execute();

        $q = $this->db->prepare(
            'INSERT INTO mucho_gd2209_registration_opens
             (token_hash,actor_hash,last_seen_at,expires_at)
             VALUES (:token,:actor,UTC_TIMESTAMP(),
                     DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
        );
        $q->execute(['token'=>$tokenHash,'actor'=>$anonymous]);

        return $token;
    }
}
