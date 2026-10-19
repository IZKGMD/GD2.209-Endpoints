<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class RewardsController
{
    public function __construct(
        private RewardsService $service
    ) {}

    private function credential(Request $request): string
    {
        return $request->gdCredential();
    }

    public function getRewards(
        Request $request
    ): Response {
        try {
            return Response::text(
                $this->service->rewards(
                    $request->postInt(
                        'accountID'
                    ),
                    $request->postString(
                        'udid'
                    ),
                    $request->postString(
                        'chk'
                    ),
                    $this->credential($request),
                    $request->postInt(
                        'rewardType'
                    )
                )
            );

        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function getSecretReward(
        Request $request
    ): Response {
        try {
            return Response::text(
                $this->service->secretReward(
                    $request->postInt('accountID'),
                    $request->postString('udid'),
                    $request->postString('chk'),
                    $this->credential($request),
                    $request->postString('rewardKey'),
                    $request->postString('secret'),
                    $request->clientIp()
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function getChallenges(
        Request $request
    ): Response {
        try {
            return Response::text(
                $this->service->challenges(
                    $request->postInt(
                        'accountID'
                    ),
                    $request->postString(
                        'udid'
                    ),
                    $request->postString(
                        'chk'
                    ),
                    $this->credential($request)
                )
            );

        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
