<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/**
 * GD 2.209 follow family.
 * Accept alternate target field names until real 2.209 traces can pin down
 * the exact request schema. No mutation without account credential.
 */
final readonly class FollowingController
{
    public function __construct(private FollowingService $service) {}

    private function target(Request $r): int
    {
        foreach (['targetAccountID', 'toAccountID', 'followAccountID', 'userID'] as $field) {
            $value = $r->postInt($field);
            if ($value > 0) {
                return $value;
            }
        }
        return 0;
    }

    public function follow(Request $r): Response
    {
        try {
            return Response::text($this->service->follow(
                $r->postInt('accountID'), $r->gdCredential(), $this->target($r)
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function unfollow(Request $r): Response
    {
        try {
            return Response::text($this->service->unfollow(
                $r->postInt('accountID'), $r->gdCredential(), $this->target($r)
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function mute(Request $r): Response
    {
        try {
            $muted = $r->postInt('mute', $r->postInt('isMuted', 1)) !== 0;
            return Response::text($this->service->mute(
                $r->postInt('accountID'), $r->gdCredential(), $this->target($r), $muted
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function get(Request $r): Response
    {
        try {
            return Response::text($this->service->encodedList(
                $r->postInt('accountID'),
                $r->gdCredential(),
                $r->postInt('page'),
                $r->postInt('getFollowers') === 1
            ));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function sync(Request $r): Response
    {
        try {
            return Response::text($this->service->sync(
                $r->postInt('accountID'), $r->gdCredential()
            ));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
