<?php

declare(strict_types=1);

/*
 * MuchoCore v7.6 Moderation
 * Copyright (C) 2026 IZK
 */

namespace MuchoCore\Moderation;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class ModerationController
{
    public function __construct(
        private ModerationService $service
    ) {}

    public function suggest(
        Request $request
    ): Response {
        $accountId=$request->postInt(
            'accountID'
        );

        $gjp=$this->credential($request);

        $levelId=$request->postInt(
            'levelID'
        );

        $stars=$request->postInt(
            'stars'
        );

        $feature=$request->postInt(
            'feature',
            0
        );

        $coins=$request->postInt(
            'coins',
            0
        );

        if (
            $accountId <= 0 ||
            $gjp === '' ||
            $levelId <= 0
        ) {
            return Response::text('-1');
        }

        try {
            $this->service->suggestStars(
                $accountId,
                $gjp,
                $levelId,
                $stars,
                $feature,
                $coins
            );

            return Response::text('1');

        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function rateStars(
        Request $request
    ): Response {
        $accountId=$request->postInt(
            'accountID'
        );

        $gjp=$this->credential($request);

        $levelId=$request->postInt(
            'levelID'
        );

        $stars=$request->postInt(
            'stars'
        );

        if (
            $accountId <= 0 ||
            $gjp === '' ||
            $levelId <= 0
        ) {
            return Response::text('-1');
        }

        try {
            $this->service->rateStars(
                $accountId,
                $gjp,
                $levelId,
                $stars
            );

            return Response::text('1');

        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function rateDemon(
        Request $request
    ): Response {
        $accountId=$request->postInt(
            'accountID'
        );

        $gjp=$this->credential($request);

        $levelId=$request->postInt(
            'levelID'
        );

        $rating=$request->postInt(
            'rating'
        );

        if (
            $accountId <= 0 ||
            $gjp === '' ||
            $levelId <= 0
        ) {
            return Response::text('-1');
        }

        try {
            $this->service->rateDemon(
                $accountId,
                $gjp,
                $levelId,
                $rating
            );

            return Response::text(
                (string)$levelId
            );

        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function report(
        Request $request
    ): Response {
        $levelId=$request->postInt(
            'levelID'
        );

        if ($levelId <= 0) {
            return Response::text('-1');
        }

        try {
            $hash=hash(
                'sha256',
                'MUCHO_V76_REPORT|'.
                $request->clientIp()
            );

            $id=$this->service
                ->reportLevel(
                    $levelId,
                    $hash
                );

            return Response::text(
                $id > 0
                    ? (string)$id
                    : '-1'
            );

        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    private function credential(
        Request $request
    ): string {
        return $request->gdCredential();
    }
}
