<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/**
 * Native GD 2.209 sound endpoints. Output framing is provisional until
 * official response captures are available.
 */
final readonly class EditorSoundController
{
    public function __construct(private EditorSoundService $service) {}

    private static function b64(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    private function soundId(Request $r): int
    {
        return $r->postInt('soundID', $r->postInt('id'));
    }

    public function get(Request $r): Response
    {
        try {
            $rows = $this->service->search(
                $r->postInt('page'),
                $r->postInt('creatorID', $r->postInt('targetAccountID'))
            );
            if ($rows === []) {
                return Response::text('-2');
            }
            $result = [];
            foreach ($rows as $row) {
                $result[] = implode(':', [
                    1, (int)$row['id'],
                    2, self::b64((string)$row['title']),
                    3, self::b64((string)$row['description']),
                    4, (int)$row['account_id'],
                    5, (int)$row['size_bytes'],
                    6, (string)$row['mime_type'],
                    7, (string)$row['sha256'],
                ]);
            }
            return Response::text(implode('|', $result));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function upload(Request $r): Response
    {
        try {
            $data = $r->postString('data');
            if ($data === '') {
                $data = $r->postString('audio');
            }
            $id = $this->service->upload(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postString('title') ?: $r->postString('name'),
                $r->postString('description'),
                $data
            );
            return Response::text($id > 0 ? (string)$id : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function download(Request $r): Response
    {
        try {
            $data = $this->service->download($this->soundId($r));
            return Response::text(
                $data === null ? '-1' : self::b64($data)
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function delete(Request $r): Response
    {
        try {
            return Response::text($this->service->delete(
                $r->postInt('accountID'), $r->gdCredential(), $this->soundId($r)
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function updateDescription(Request $r): Response
    {
        try {
            return Response::text($this->service->updateDescription(
                $r->postInt('accountID'), $r->gdCredential(), $this->soundId($r),
                $r->postString('description')
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
