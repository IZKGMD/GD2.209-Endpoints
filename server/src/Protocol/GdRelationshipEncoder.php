<?php
declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdRelationshipEncoder
{
    private function displayUsername(array $user): string
    {
        $username=(string)($user['username'] ?? 'Player');
        $tag=strtoupper(trim((string)($user['clan_tag'] ?? '')));

        if ($tag === '') {
            return ProtocolText::username($username);
        }

        $prefix='['.$tag.']';
        return ProtocolText::username(
            $prefix.substr($username,0,max(1,20-strlen($prefix)))
        );
    }

    public function requests(
        array $rows,
        int $total,
        int $offset,
        bool $sent
    ): string {
        if ($rows === []) {
            return '-2';
        }

        $out = [];

        foreach ($rows as $r) {
            $peer = $sent
                ? (int)$r['to_account_id']
                : (int)$r['account_id'];

            $out[] = implode(':', [
                1, $this->displayUsername($r),
                2, (int)($r['user_id'] ?? $peer),
                9, (int)($r['icon_id'] ?? $r['cube'] ?? 1),
                10, (int)($r['color1'] ?? 0),
                11, (int)($r['color2'] ?? 3),
                14, (int)($r['icon_type'] ?? 0),
                15, (int)($r['special'] ?? 0),
                16, $peer,
                32, (int)$r['id'],
                35, (string)($r['comment'] ?? ''),
                41, ((int)($r['is_read'] ?? 0) === 0 ? 1 : 0),
                37, $this->date((string)($r['created_at'] ?? '')),
            ]);
        }

        return implode('|', $out)
            . '#' . $total . ':' . $offset . ':10';
    }

    public function users(array $rows, bool $include209Stats = false): string
    {
        if ($rows === []) {
            return '-2';
        }

        $out = [];

        foreach ($rows as $r) {
            $accountId = (int)($r['account_id'] ?? 0);

            $fields = [
                1, $this->displayUsername($r),
                2, (int)($r['user_id'] ?? $accountId),
                9, (int)($r['icon_id'] ?? $r['cube'] ?? 1),
                10, (int)($r['color1'] ?? 0),
                11, (int)($r['color2'] ?? 3),
                14, (int)($r['icon_type'] ?? 0),
                15, (int)($r['special'] ?? 0),
                16, $accountId,
                18, 0,
                41, (int)($r['is_new'] ?? 0),
            ];
            if ($include209Stats) {
                $fields = array_merge($fields, [
                    3, (int)($r['stars'] ?? 0),
                    4, (int)($r['demons'] ?? 0),
                    8, (int)($r['creator_points'] ?? 0),
                    13, (int)($r['secret_coins'] ?? 0),
                    17, (int)($r['user_coins'] ?? 0),
                    46, (int)($r['diamonds'] ?? 0),
                    52, (int)($r['moons'] ?? 0),
                ]);
            }
            $out[] = implode(':', $fields);
        }

        return implode('|', $out);
    }

    private function date(string $value): string
    {
        $t = strtotime($value);
        return $t === false ? '' : date('d/m/Y G.i', $t);
    }
}
