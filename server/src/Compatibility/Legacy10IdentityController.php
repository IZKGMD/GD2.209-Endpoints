<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class Legacy10IdentityController
{
    public function __construct(
        private Legacy10IdentityService $service
    ) {}

    public function updateUsername(
        Request $request
    ): Response {
        $udid = $request->postString('udid');
        $username = $request->postString('userName');

        if ($udid === '' || $username === '') {
            return Response::text('-1');
        }

        try {
            return Response::text(
                $this->service->updateUsername($udid, $username)
                    ? '1'
                    : '-1'
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
