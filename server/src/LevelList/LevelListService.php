<?php
declare(strict_types=1);

namespace MuchoCore\LevelList;

use MuchoCore\V71\Request;
use MuchoCore\V71\AuthService;

final class LevelListService
{
    public function __construct(
        private readonly LevelListRepository $repo,
        private readonly AuthService $auth
    ) {
    }

    public function get(): string
    {
        $type = Request::int('type', 0);
        $page = min(1000, max(0, Request::int('page', 0)));
        $offset = $page * 10;
        $str = Request::protocolText(Request::string('str'), 128);

        $filters = [];
        $order = 'likes';

        switch ($type) {
            case 0:
                if ($str !== '') {
                    if (ctype_digit($str)) {
                        $filters['id'] = (int)$str;
                    } else {
                        $filters['name'] = $str;
                    }
                }
                $order = 'likes';
                break;

            case 1:
                $order = 'downloads';
                break;

            case 2:
                $order = 'likes';
                break;

            case 3:
                $filters['min_created'] = time() - 604800;
                $order = 'downloads';
                break;

            case 4:
                $order = 'created';
                break;

            case 5:
                if (!ctype_digit($str) || (int)$str <= 0) {
                    return '-1';
                }
                $filters['account_id'] = (int)$str;
                $order = 'created';
                break;

            case 6:
                $filters['rated'] = true;
                $filters['featured'] = true;
                $order = 'downloads';
                break;

            case 11:
                $filters['rated'] = true;
                $order = 'downloads';
                break;

            case 12:
                $ids = Request::idList(Request::string('followed'), 500);
                if (!$ids) {
                    return '-1';
                }
                $filters['account_ids'] = $ids;
                $order = 'created';
                break;

            case 13:
                $accountId = $this->auth->authenticatedAccountId();
                if ($accountId === null) {
                    return '-1';
                }

                $filters['account_ids'] = array_values(
                    array_diff(
                        $this->repo->friendAccountIds($accountId),
                        [$accountId]
                    )
                );
                if (!$filters['account_ids']) {
                    return '-1';
                }

                $order = 'created';
                break;

            case 7:
            case 27:
                $filters['suggested'] = true;
                $order = 'suggested';
                break;

            default:
                $order = 'created';
                break;
        }

        $diff = Request::string('diff', '-');
        if ($diff !== '-') {
            $diffIds = Request::idList($diff, 20);

            if ($diffIds) {
                $filters['difficulties'] = $diffIds;
            }
        }
        if (Request::boolInt('star') === 1) {
            $filters['rated'] = true;
        }
        if (Request::boolInt('featured') === 1) {
            $filters['featured'] = true;
        }

        $result = $this->repo->search($filters, $offset, 10, $order);
        if (!$result['rows']) {
            return '-1';
        }

        if (isset($filters['id']) && count($result['rows']) === 1) {
            $this->repo->incrementDownload((int)$filters['id']);
            $result['rows'][0]['downloads'] = (int)$result['rows'][0]['downloads'] + 1;
        }

        $listParts = [];
        $userParts = [];
        $seenUsers = [];

        foreach ($result['rows'] as $row) {
            $listParts[] = implode(':', [
                '1', (int)$row['list_id'],
                '2', $this->field((string)$row['list_name']),
                '3', $this->field((string)$row['list_desc']),
                '5', (int)$row['list_version'],
                '49', (int)$row['account_id'],
                '50', $this->field((string)$row['user_name']),
                '10', (int)$row['downloads'],
                '7', (int)$row['difficulty'],
                '14', (int)$row['likes'],
                '19', (int)$row['featured'],
                '51', $this->field((string)$row['level_ids']),
                '55', (int)$row['stars'],
                '56', (int)$row['count_for_reward'],
                '28', (int)$row['created_at'],
                '29', (int)$row['updated_at'],
            ]);

            $uid = (int)$row['user_id'];
            $uname = $this->field((string)$row['user_name']);
            $extId = is_numeric((string)$row['ext_id']) ? (int)$row['ext_id'] : 0;
            $userKey = $uid . ':' . $extId;

            if (!isset($seenUsers[$userKey])) {
                $seenUsers[$userKey] = true;
                $userParts[] = "{$uid}:{$uname}:{$extId}";
            }
        }

        return implode('|', $listParts)
            . '#'
            . implode('|', $userParts)
            . '#'
            . (int)$result['total'] . ':' . $offset . ':10'
            . '#Sa1ntSosetHuiHelloFromGreenCatsServerLOL';
    }

    public function upload(): string
    {
        if (!$this->auth->validateLevelSecret()) {
            return '-100';
        }

        $accountId = $this->auth->authenticatedAccountId();
        if ($accountId === null) {
            return '-1';
        }

        $listLevels = Request::idList(Request::string('listLevels'), 1000);
        if (!$listLevels || !$this->repo->allLevelsExist($listLevels)) {
            return '-6';
        }

        $listId = max(0, Request::int('listID'));
        $name = Request::protocolText(Request::string('listName', 'Unnamed list'), 64);
        $desc = Request::protocolText(Request::string('listDesc'), 500);
        $version = max(1, Request::int('listVersion', 1));
        $difficulty = Request::int('difficulty', 0);
        $original = max(0, Request::int('original', 0));
        $unlisted = Request::boolInt('unlisted');
        $now = time();

        if ($name === '') {
            $name = 'Unnamed list';
        }

        if ($listId > 0) {
            $ok = $this->repo->updateOwned($listId, $accountId, [
                ':list_name' => $name,
                ':list_desc' => $desc,
                ':list_version' => $version,
                ':level_ids' => implode(',', $listLevels),
                ':difficulty' => $difficulty,
                ':original_id' => $original,
                ':unlisted' => $unlisted,
                ':updated_at' => $now,
            ]);
            return $ok ? (string)$listId : '-1';
        }

        $newId = $this->repo->create([
            ':account_id' => $accountId,
            ':list_name' => $name,
            ':list_desc' => $desc,
            ':list_version' => $version,
            ':level_ids' => implode(',', $listLevels),
            ':difficulty' => $difficulty,
            ':original_id' => $original,
            ':unlisted' => $unlisted,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $newId > 0 ? (string)$newId : '-1';
    }

    public function delete(): string
    {
        if (!$this->auth->validateLevelSecret()) {
            return '-1';
        }

        $accountId = $this->auth->authenticatedAccountId();
        $listId = Request::int('listID');

        if ($accountId === null || $listId <= 0) {
            return '-1';
        }

        return $this->repo->deleteOwned($listId, $accountId) ? '1' : '-1';
    }

    private function field(string $value): string
    {
        return str_replace([':', '#', '|'], ['', '', ''], $value);
    }
}
