<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

use MuchoCore\User\GameRole;

final class GdUserEncoder
{
    private function displayUsername(array $user): string
    {
        $username=(string)($user['username'] ?? 'Player');
        $tag=strtoupper(trim((string)($user['clan_tag'] ?? '')));

        if ($tag === '') {
            return ProtocolText::username($username);
        }

        $prefix='['.$tag.']';
        $remaining=max(1,20-strlen($prefix));
        $name=substr($username,0,$remaining);

        return ProtocolText::username($prefix.$name);
    }

    public function profile(array $u, int $gameVersion = 22, int $binaryVersion = 0): string
    {
        $accountId = (int)($u['account_id'] ?? 0);
        $userId = (int)($u['user_id'] ?? $accountId);

        try {
            $modBadge = GameRole::badgeLevel(
                (string)($u['role_code'] ?? 'user')
            );
        } catch (\InvalidArgumentException) {
            $modBadge = 0;
        }

        /*
         * Canonical getGJUserInfo field order. Request/notification
         * sections are emitted only when their context exists.
         */
        $pairs = [
            '1:' . $this->displayUsername($u),
            '2:' . $userId,
            '13:' . (int)($u['secret_coins'] ?? 0),
            '17:' . (int)($u['user_coins'] ?? 0),
            '10:' . (int)($u['color1'] ?? 0),
            '11:' . (int)($u['color2'] ?? 3),
            '51:' . (int)($u['color3'] ?? 0),
            '3:' . (int)($u['stars'] ?? 0),
            '46:' . (int)($u['diamonds'] ?? 0),
            '52:' . (int)($u['moons'] ?? 0),
            '4:' . (int)($u['demons'] ?? 0),
            '8:' . (int)round((float)($u['creator_points'] ?? 0), 0, PHP_ROUND_HALF_DOWN),
            '18:' . (int)($u['message_state'] ?? 0),
            '19:' . (int)($u['friend_request_state'] ?? 0),
            '50:' . (int)($u['comment_history_state'] ?? 0),
            '20:' . (string)($u['youtube'] ?? ''),
            '21:' . (int)($u['icon_id'] ?? $u['cube'] ?? 1),
            '22:' . (int)($u['ship'] ?? 1),
            '23:' . (int)($u['ball'] ?? 1),
            '24:' . (int)($u['ufo'] ?? 1),
            '25:' . (int)($u['wave'] ?? 1),
            '26:' . (int)($u['robot'] ?? 1),
            '28:' . (int)($u['glow'] ?? 0),
            '43:' . (int)($u['spider'] ?? 1),
            '48:' . (int)($u['explosion'] ?? 1),
            '53:' . (int)($u['swing'] ?? 1),
            '54:' . (int)($u['jetpack'] ?? 1),
            '30:' . (int)($u['rank'] ?? 0),
            '16:' . $accountId,
            '31:' . (int)($u['friend_state'] ?? 0),
            '44:' . (string)($u['twitter'] ?? ''),
            '45:' . (string)($u['twitch'] ?? ''),
            '49:' . $modBadge,
            '55:' . (string)($u['demon_info'] ?? ''),
            '56:' . (string)($u['star_info'] ?? ''),
            '57:' . (string)($u['platformer_info'] ?? ''),
            '58:' . (string)($u['discord'] ?? ''),
            '59:' . (string)($u['instagram'] ?? ''),
            '60:' . (string)($u['tiktok'] ?? ''),
            '61:' . (string)($u['custom_link'] ?? ''),
        ];

        if ((int)($u['request_id'] ?? 0) > 0) {
            $pairs[] = '32:' . (int)$u['request_id'];
            $pairs[] = '35:' . (string)($u['request_comment'] ?? '');
            $pairs[] = '37:' . (string)($u['request_date'] ?? '');
        }

        if (array_key_exists('messages_count', $u)) {
            $pairs[] = '38:' . (int)($u['messages_count'] ?? 0);
            $pairs[] = '39:' . (int)($u['friend_requests_count'] ?? 0);
            $pairs[] = '40:' . (int)($u['friends_count'] ?? 0);
        }

        // GD 2.209 profile extension. Self-only fields are added by the
        // service only when the credential-backed viewer equals target.
        if ($gameVersion >= 22 && $binaryVersion >= 51) {
            if (array_key_exists('gd2209_followers', $u)) {
                $pairs[] = '67:' . max(0, (int)$u['gd2209_followers']);
            }
            if (array_key_exists('gd2209_following', $u)) {
                $pairs[] = '68:' . max(0, (int)$u['gd2209_following']);
            }
            if (array_key_exists('gd2209_is_following', $u)) {
                $pairs[] = '69:' . ((int)$u['gd2209_is_following'] > 0 ? 1 : 0);
            }
            if (array_key_exists('gd2209_notifications', $u)) {
                $pairs[] = '66:' . max(0, (int)$u['gd2209_notifications']);
            }
        }

        $pairs[] = '29:1';

        return implode(':', $pairs);
    }

    public function search(
        array $users,
        int $total,
        int $offset,
        int $limit
    ): string {
        if ($users === []) {
            return '#0:0:0';
        }

        $entries = [];

        foreach ($users as $u) {
            $accountId = (int)($u['account_id'] ?? 0);
            $userId = (int)($u['user_id'] ?? $accountId);

            $mapping = [
                1  => $this->displayUsername($u),
                2  => $userId,
                13 => (int)($u['secret_coins'] ?? 0),
                17 => (int)($u['user_coins'] ?? 0),
                9  => (int)($u['icon_id'] ?? 1),
                10 => (int)($u['color1'] ?? 0),
                11 => (int)($u['color2'] ?? 3),
                51 => (int)($u['color3'] ?? 0),
                14 => (int)($u['icon_type'] ?? 0),
                15 => (int)($u['special'] ?? 0),
                16 => $accountId,
                3  => (int)($u['stars'] ?? 0),
                8  => (int)round((float)($u['creator_points'] ?? 0), 0, PHP_ROUND_HALF_DOWN),
                4  => (int)($u['demons'] ?? 0),
                46 => (int)($u['diamonds'] ?? 0),
                52 => (int)($u['moons'] ?? 0),
            ];

            $pairs = [];

            foreach ($mapping as $key => $value) {
                $pairs[] = $key . ':' . $value;
            }

            $entries[] = implode(':', $pairs);
        }

        return implode('|', $entries)
            . '#' . $total . ':' . $offset . ':' . $limit;
    }

    public function leaderboard(array $users): string
    {
        if ($users === []) {
            return '-1';
        }

        $entries = [];

        foreach ($users as $u) {
            $accountId = (int)($u['account_id'] ?? 0);
            $userId = (int)($u['user_id'] ?? $accountId);

            $mapping = [
                1  => $this->displayUsername($u),
                2  => $userId,
                13 => (int)($u['secret_coins'] ?? 0),
                17 => (int)($u['user_coins'] ?? 0),
                6  => (int)($u['rank'] ?? 0),
                9  => (int)($u['icon_id'] ?? 1),
                10 => (int)($u['color1'] ?? 0),
                11 => (int)($u['color2'] ?? 3),
                51 => (int)($u['color3'] ?? 0),
                14 => (int)($u['icon_type'] ?? 0),
                15 => (int)($u['special'] ?? 0),
                16 => $accountId,
                3  => (int)($u['stars'] ?? 0),
                8  => (int)round((float)($u['creator_points'] ?? 0), 0, PHP_ROUND_HALF_DOWN),
                4  => (int)($u['demons'] ?? 0),
                7  => $accountId,
                46 => (int)($u['diamonds'] ?? 0),
                52 => (int)($u['moons'] ?? 0),
            ];

            $pairs = [];

            foreach ($mapping as $key => $value) {
                $pairs[] = $key . ':' . $value;
            }

            $entries[] = implode(':', $pairs);
        }

        return implode('|', $entries);
    }
}
