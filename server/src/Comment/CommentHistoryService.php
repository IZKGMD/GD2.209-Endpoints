<?php
declare(strict_types=1);

namespace MuchoCore\Comment;

use MuchoCore\V71\Request;
use Throwable;

final class CommentHistoryService
{
    public function __construct(private readonly CommentHistoryRepository $repo)
    {
    }

    public function get(): string
    {
        $userId = Request::int('userID');

        if ($userId <= 0) {
            $userId = Request::int('accountID');
        }

        if ($userId <= 0) {
            return '-1';
        }

        $binaryVersion = Request::int('binaryVersion', 0);
        $gameVersion = Request::int('gameVersion', 0);
        $mode = Request::int('mode', 0);
        $count = min(50, max(1, Request::int('count', 10)));
        $page = min(1000, max(0, Request::int('page', 0)));
        $offset = $page * $count;

        try {
            $result = $this->repo->byUserId($userId, $offset, $count, $mode !== 0);
        } catch (Throwable) {
            return '-1';
        }

        if ($result['total'] === 0) {
            return '-2';
        }

        $comments = [];
        $users = [];
        $seenUsers = [];

        foreach ($result['rows'] as $row) {
            $timestamp = is_numeric((string)$row['timestamp']) ? (int)$row['timestamp'] : 0;
            $uploadDate = $timestamp > 0 ? date('d/m/Y G.i', $timestamp) : '01/01/1970 0.00';

            $comment = str_replace(
                ['~', '#', '|', ':'],
                ['', '', '', ''],
                (string)$row['comment']
            );

            $base = '1~' . (int)$row['levelID']
                . '~2~' . $comment
                . '~3~' . (int)$row['userID']
                . '~4~' . (int)$row['likes']
                . '~5~0'
                . '~7~' . (int)$row['isSpam']
                . '~9~' . $uploadDate
                . '~6~' . (int)$row['commentID']
                . '~10~' . (int)$row['percent'];

            $userName = str_replace(['~', ':', '#', '|'], ['', '', '', ''], (string)$row['userName']);
            $extId = is_numeric((string)$row['extID']) ? (int)$row['extID'] : 0;

            if ($userName !== '') {
                if ($binaryVersion > 31) {
                    // v7.1 does not forge moderator state; badge stays 0 until permission service integration.
                    $base .= '~11~0'
                        . ':1~' . $userName
                        . '~7~1'
                        . '~9~' . (int)$row['icon']
                        . '~10~' . (int)$row['color1']
                        . '~11~' . (int)$row['color2']
                        . '~14~' . (int)$row['iconType']
                        . '~15~' . (int)$row['special']
                        . '~16~' . $extId;
                } else {
                    $uid = (int)$row['userID'];
                    if (!isset($seenUsers[$uid])) {
                        $seenUsers[$uid] = true;
                        $users[] = "{$uid}:{$userName}:{$extId}";
                    }
                }
            }

            $comments[] = $base;
        }

        $out = implode('|', $comments);

        if ($binaryVersion < 32) {
            $out .= '#' . implode('|', $users);
        }

        $out .= '#'
            . (int)$result['total']
            . ':' . $offset
            . ':' . (int)$result['visible'];

        return $out;
    }
}
