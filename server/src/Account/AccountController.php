<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class AccountController
{
    public function __construct(
        private AccountService $service
    ) {
    }

    public function register(Request $request): Response
    {
        $result = $this->service->register(
            username: $request->postString('userName'),
            password: $request->postString('password'),
            email: $request->postString('email'),
            ip: $request->clientIp(),
        );

        return Response::text($result);
    }

    public function login(Request $request): Response
    {
        $result = $this->service->login(
            username: $request->postString('userName'),
            password: $request->postString('password'),
            gjp2: $request->postString('gjp2'),
            ip: $request->clientIp(),
            udid: $request->postString('udid'),
        );

        return Response::text($result);
    }
}
