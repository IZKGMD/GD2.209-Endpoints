<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class MessageController
{
    public function __construct(private MessageService $service) {}

    private function gjp(Request $r): string
    {
        return $r->gdCredential();
    }

    public function getMessages(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->getMessages(
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

    public function readMessage(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->readMessage(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('messageID')
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function sendMessage(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->sendMessage(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('toAccountID'),
                    $r->postString('subject'),
                    $r->postString('body')
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function deleteMessage(Request $r): Response
    {
        try {
            return Response::text(
                $this->service->deleteMessage(
                    $r->postInt('accountID'),
                    $this->gjp($r),
                    $r->postInt('messageID'),
                    $r->postInt('isSender') === 1
                ) ? '1' : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
