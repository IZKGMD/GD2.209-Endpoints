<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdCommentEncoder
{
    private function displayUsername(array $profile): string
    {
        $username=(string)($profile['username'] ?? 'Player');
        $tag=strtoupper(trim((string)($profile['clan_tag'] ?? '')));

        if ($tag === '') {
            return ProtocolText::username($username);
        }

        $prefix='['.$tag.']';
        $remaining=max(1,20-strlen($prefix));

        return ProtocolText::username(
            $prefix.substr($username,0,$remaining)
        );
    }

    public function encode(
        array $comment,
        array $profile,
        int $gameVersion = 22,
        int $binaryVersion = 0
    ): string {
        $content = (string)($comment['content'] ?? '');

        $content = GdLegacyText::encodeCommentForResponse(
            $content,
            $gameVersion
        );

        $badge = (int)($profile['badge'] ?? 0);

        $commentParts = [
            '2', $content,
            '3', (string)(
                $profile['user_id']
                ?? $comment['user_id']
                ?? $comment['account_id']
            ),
            '4', (string)($comment['likes'] ?? 0),
            '5', '0',
            '7', (string)($comment['is_spam'] ?? 0),
            '9', $this->date((string)($comment['created_at'] ?? '')),
            '6', (string)$comment['id'],
            '10', (string)($comment['percent'] ?? 0),
        ];

        // GD 2.209 added key 15: exact Unix creation timestamp.
        // Never add it to 2.2081 or earlier wire responses.
        if ($gameVersion >= 22 && $binaryVersion >= 51) {
            $commentParts[] = '15';
            $commentParts[] = (string)$this->dateEpoch((string)($comment['created_at'] ?? ''));
        }

        if ($binaryVersion > 31 || $gameVersion >= 22) {
            $commentParts[] = '11';
            $commentParts[] = (string)$badge;

            if ($badge > 0) {
                $commentParts[] = '12';
                $commentParts[] = (string)($profile['comment_color'] ?? '255,255,255');
            }

            $userParts = [
                '1', $this->displayUsername($profile),
                '7', '1',
                '9', (string)($profile['cube'] ?? 1),
                '10', (string)($profile['color1'] ?? 0),
                '11', (string)($profile['color2'] ?? 3),
                '14', (string)($profile['icon_type'] ?? 0),
                '15', (string)($profile['special'] ?? 0),
                '16', (string)(
                    $profile['account_id']
                    ?? $comment['account_id']
                    ?? 0
                ),
            ];

            return implode('~', $commentParts)
                . ':'
                . implode('~', $userParts);
        }

        return implode('~', $commentParts);
    }

    public function encodeAccountComment(
        array $comment,
        int $gameVersion = 22,
        int $binaryVersion = 0
    ): string {
        $content = (string)($comment['content'] ?? '');

        $content = GdLegacyText::encodeCommentForResponse(
            $content,
            $gameVersion
        );

        $parts = [
            '2', $content,
            '3', (string)(
                $comment['user_id']
                ?? $comment['account_id']
            ),
            '4', (string)($comment['likes'] ?? 0),
            '5', '0',
            '7', (string)($comment['is_spam'] ?? 0),
            '9', $this->date((string)($comment['created_at'] ?? '')),
            '6', (string)$comment['id'],
        ];
        if ($gameVersion >= 22 && $binaryVersion >= 51) {
            $parts[] = '15';
            $parts[] = (string)$this->dateEpoch((string)($comment['created_at'] ?? ''));
        }
        return implode('~', $parts);
    }

    private function dateEpoch(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        try {
            return max(0, (new \DateTimeImmutable($value,
                new \DateTimeZone('UTC')))->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function date(string $value): string
    {
        $time = strtotime($value);

        return $time === false
            ? ''
            : date('d/m/Y G.i', $time);
    }
}
