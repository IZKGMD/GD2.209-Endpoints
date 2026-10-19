<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class RelationshipController
{
    public function __construct(private RelationshipService $service) {}

    private function gjp(Request $r): string
    {
        return $r->gdCredential();
    }

    public function send(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->send(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('toAccountID'),
                    $r->postString('comment')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function get(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->get(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    max(0, $r->postInt('page')),
                    $r->postInt('getSent') === 1
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function read(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->read(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('requestID')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function accept(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->accept(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('requestID')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function delete(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->delete(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('targetAccountID'),
                    $r->postInt('isSender') === 1
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function remove(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->remove(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('targetAccountID')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function block(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->block(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('targetAccountID')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function unblock(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->unblock(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('targetAccountID')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function userList(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->userList(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('type'),
                    $r->clientVersion()->effectiveGameVersion() >= 22
                        && $r->clientVersion()->binaryVersion >= 51
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
