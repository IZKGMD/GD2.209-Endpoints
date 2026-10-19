<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

/**
 * GD 2.209 thread-message response serialization.
 *
 * Verified framing: messages#members#formerMembers#invites#metadata#
 *                   lastMessageID#3,1,10,50
 * Verified message object: id:sender:base64(text):timestamp:deleted.
 * Metadata and invite slots are provisional for MuchoCore group threads,
 * pending full, redacted native-client fixtures.
 */
final class Gd2209ThreadWire
{
    private static function timestamp(string $date): int
    {
        $time = strtotime($date);
        return $time === false ? 0 : $time;
    }

    /** Native client-facing relative time. Unknown dates stay empty. */
    public static function relativeTime(int $timestamp, ?int $now = null): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $elapsed = max(0, ($now ?? time()) - $timestamp);
        if ($elapsed < 60) {
            return 'just now';
        }
        $units = [
            [31536000, 'year'], [2592000, 'month'],
            [604800, 'week'], [86400, 'day'], [3600, 'hour'],
            [60, 'minute'],
        ];
        foreach ($units as [$seconds, $name]) {
            if ($elapsed >= $seconds) {
                $amount = max(1, (int)floor($elapsed / $seconds));
                return $amount . ' ' . $name . ($amount === 1 ? '' : 's');
            }
        }
        return 'just now';
    }

    public static function message(array $row): string
    {
        return implode(':', [
            (int)$row['id'],
            (int)$row['sender_account_id'],
            base64_encode((string)$row['body']),
            self::timestamp((string)$row['created_at']),
            (int)($row['is_deleted'] ?? 0) === 1 ? 1 : 0,
        ]);
    }

    public static function messages(array $rows): string
    {
        return $rows === [] ? 'none' : implode('|', array_map(self::message(...), $rows));
    }

    /**
     * Native GD 2.209 thread metadata (11 positional values).
     * GD Docs: threadID, unknown, memberCount, description, mv,
     * showLoadEarlier, unknown, unknown, title, locked, unknown.
     * Keep unknown fields at zero rather than substituting unrelated data.
     */
    public static function metadata(array $thread): string
    {
        return implode(':', [
            (int)$thread['id'],
            0,
            (int)($thread['member_count'] ?? 0),
            base64_encode((string)($thread['description'] ?? '')),
            0,
            (int)($thread['has_older_messages'] ?? 0),
            0, 0,
            base64_encode((string)($thread['title'] ?? '')),
            (int)($thread['is_closed'] ?? 0),
            0,
        ]);
    }

    /**
     * Inviters and targets are actual stored accounts, but optional native
     * ThreadInviteUser trailing fields remain unconfirmed.
     */
    public static function invites(array $rows): string
    {
        $result = [];
        foreach ($rows as $row) {
            // Native 2.209 Thread Invite User Object: seventeen positional
            // fields, unlike the distinct getGJThreadInvites invite records.
            $result[] = implode(':', [
                (int)$row['invitee_account_id'], // account ID
                (int)($row['user_id'] ?? 0),     // user ID
                base64_encode((string)$row['username']),
                (int)$row['inviter_account_id'],
                (int)($row['cube'] ?? 1),
                (int)($row['color1'] ?? 0),
                (int)($row['color2'] ?? 3),
                0, // glow color not persisted
                0, // icon type unavailable in this query
                (int)($row['special'] ?? 0),
                0, 0, 0, 0, 0, 0, 0, // unavailable profile counters/date
            ]);
        }
        return implode('|', $result);
    }

    /** Inbox invite is a separate, 19-position native object. */
    public static function inboxInvite(array $row): string
    {
        $timestamp = self::timestamp((string)$row['created_at']);
        $age = self::relativeTime($timestamp);
        return implode(':', [
            (int)$row['thread_id'],
            (int)$row['inviter_account_id'],
            base64_encode((string)$row['inviter_username']),
            base64_encode((string)$row['title']),
            (int)($row['is_dm'] ?? 0) === 1 ? 0 : 1,
            $age,
            $age,
            '',
            base64_encode((string)($row['description'] ?? '')),
            0,
            0,
            (int)($row['inviter_cube'] ?? 1),
            (int)($row['inviter_color1'] ?? 0),
            (int)($row['inviter_color2'] ?? 0),
            0,
            (int)($row['inviter_special'] ?? 0),
            0,
            (string)($row['emote'] ?? ''),
            $timestamp,
        ]);
    }

    public static function inboxInvites(array $rows): string
    {
        return $rows === [] ? 'none'
            : implode('|', array_map(self::inboxInvite(...), $rows));
    }

    /** Thread object is fixed positional, not a key/value encoder. */
    public static function thread(array $row): string
    {
        $dm = (int)($row['is_dm'] ?? 0) === 1;
        $peer = $dm ? (int)($row['peer_account_id'] ?? 0) : 0;
        return implode(':', [
            (int)$row['id'],                                // 1
            $peer,                                         // 2 DM peer
            $dm ? base64_encode((string)($row['peer_username'] ?? '')) : '', // 3
            $dm ? '' : base64_encode((string)$row['title']), // 4
            (int)($row['unread_count'] ?? 0),               // 5
            (int)($row['last_sender'] ?? 0),                // 6
            self::relativeTime((int)($row['last_timestamp'] ?? 0)), // 7
            (int)($row['member_count'] ?? 0),               // 8
            base64_encode((string)($row['last_username'] ?? '')), // 9
            (int)$row['owner_account_id'],                 // 10
            (int)($row['is_muted'] ?? 0),                   // 11
            0,                                             // 12
            $dm ? 1 : 0,                                   // 13
            0,                                             // 14
            (int)($row['is_pinned'] ?? 0),                  // 15
            $dm ? (int)($row['peer_cube'] ?? 1) : 0,        // 16
            $dm ? (int)($row['peer_color1'] ?? 0) : 0,      // 17
            $dm ? (int)($row['peer_color2'] ?? 0) : 0,      // 18
            0,                                             // 19
            0,                                             // 20
            $dm ? (int)($row['peer_special'] ?? 0) : 0,     // 21
            $dm ? '' : base64_encode((string)($row['description'] ?? '')), // 22
            $dm ? '' : (string)($row['emote'] ?? ''),         // 23
            (int)($row['last_timestamp'] ?? 0),             // 24
            0,                                             // 25 mod badge
            0,                                             // 26 status
        ]);
    }

    /**
     * Native thread-list pagination: list#total:offset:amount:invites:interval.
     * GD Docs says 10/page; RobTop examples may return 20 items per page.
     */
    public static function threads(array $page): string
    {
        $items = array_map(self::thread(...), $page['rows']);
        return implode('|', $items) . '#' . implode(':', [
            (int)$page['total'], (int)$page['offset'], 10,
            (int)$page['invites'], 60
        ]);
    }

    public static function snapshot(
        array $data, GdRelationshipEncoder $users
    ): string {
        $members = $data['members'] === []
            ? '' : $users->users($data['members'], true);
        $thread = $data['thread'];
        $thread['member_count'] = count($data['members']);
        return implode('#', [
            self::messages($data['messages']),
            $members,
            '', // Former members are not yet serialized in this response.
            self::invites($data['invites']),
            self::metadata($thread),
            '0', // Active members see all permitted messages.
            '3,1,10,50',
        ]);
    }
}
