<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final readonly class LevelController
{
    public function __construct(
        private LevelService $service,
        private AccountAuthenticator $auth,
        private ModerationRepository $moderation
    ) {
    }

    public function list(Request $request): Response
    {
        $type = $request->postInt('type', 0);

        /*
         * GD 2.209: type 8 is the moderator-sent queue; type 27 is
         * the signed-in advisor's own sent levels. Neither should
         * leak moderation proposals to unauthenticated players.
         */
        if ($type === 8 || $type === 27) {
            $accountId = $request->postInt('accountID');
            $credential = $request->gdCredential();

            if ($accountId <= 0 || $credential === '') {
                return Response::text('-1');
            }

            try {
                $this->auth->authenticate($accountId, $credential);
                $role = $this->moderation->getAccountRole($accountId);
                if (!in_array($role, ['moderator', 'elder_moderator', 'owner'], true)) {
                    return Response::text('-1');
                }
            } catch (\Throwable) {
                return Response::text('-1');
            }
        }

        /* GD 2.1 Friends Levels (type=13) requires authenticated account context. */
        if ($type === 13) {
            $accountId = $request->postInt('accountID', 0);
            $credential = $request->gdCredential();

            if ($accountId <= 0 || $credential === '') {
                return Response::text('-1');
            }

            try {
                $this->auth->authenticate($accountId, $credential);
            } catch (\Throwable) {
                return Response::text('-1');
            }
        }

        try {
            return Response::text(
                $this->service->getLevels($request->post)
            );
        } catch (\Throwable) {
            return Response::text('-1');
        }
    }
}
