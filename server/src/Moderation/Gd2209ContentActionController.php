<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class Gd2209ContentActionController
{
    public function __construct(private Gd2209ContentActionService $service) {}

    private function dispatch(Request $r, callable $operation): Response
    {
        if (strtoupper($r->method) !== 'POST'
            || $r->postInt('accountID') <= 0
            || $r->gdCredential() === '') {
            return Response::text('-1');
        }
        try {
            return Response::text($operation(
                $r->postInt('accountID'), $r->gdCredential()
            ) ? '1' : '-1');
        } catch (Throwable $e) {
            error_log('[MuchoCore GD2.209 content action] ' . $e::class);
            return Response::text('-1');
        }
    }

    public function deleteOwn(Request $r): Response
    {
        return $this->dispatch($r, fn(int $id,string $secret): bool =>
            $this->service->deleteOwnComments(
                $id, $secret, $r->postString('commentIDs') ?: $r->postString('items')
            )
        );
    }

    public function deleteUser(Request $r): Response
    {
        return $this->dispatch($r, fn(int $id,string $secret): bool =>
            $this->service->deleteUserComments(
                $id, $secret, $r->postInt('targetAccountID')
            )
        );
    }

    public function threadAction(Request $r): Response
    {
        return $this->dispatch($r, fn(int $id,string $secret): bool =>
            $this->service->threadAction(
                $id, $secret, $r->postInt('threadID'),
                $r->postString('action'), $r->postInt('messageID')
            )
        );
    }
}
