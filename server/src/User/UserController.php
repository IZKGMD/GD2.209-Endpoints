<?php

declare(strict_types=1);

namespace MuchoCore\User;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class UserController
{
    public function __construct(
        private UserService $service
    ) {}

    public function requestAccess(Request $request): Response
    {
        $id=$request->postInt('accountID');
        $gjp=$request->gdCredential();

        if ($id<=0 || $gjp==='') {
            return Response::text('-1');
        }

        try {
            return Response::text(
                $this->service->requestAccess($id,$gjp)
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function updateScore(Request $request): Response
    {
        $id=$request->postInt('accountID');
        $gjp=$request->gdCredential();
        $version=$request->clientVersion();
        $udid=$request->postString('udid');
        $username=$request->postString('userName');

        $legacyUdidUpdate =
            $id <= 0 &&
            $gjp === '' &&
            $version->effectiveGameVersion() > 0 &&
            $version->effectiveGameVersion() < 19 &&
            trim($udid) !== '';

        if (($id <= 0 || $gjp === '') && !$legacyUdidUpdate) {
            return Response::text('-1');
        }

        try {
            $data = !empty($request->post)
                ? $request->post
                : $_POST;

            if ($request->postInt('gameVersion', 0) === 0) {
                $data['gameVersion'] = $version->effectiveGameVersion() ?: 1;
            }

            return Response::text(
                $this->service->updateScore(
                    $id,
                    $gjp,
                    $data,
                    $udid,
                    $username,
                    $request->clientIp()
                )
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s update_user_score_failed account_id=%d version=%d family=%s has_udid=%d has_gjp=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $id,
                $version->effectiveGameVersion(),
                $version->family(),
                trim($udid) !== '' ? 1 : 0,
                $gjp !== '' ? 1 : 0,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function updateSettings(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = (string)($request->gdCredential() ?: ($_POST["gjp"] ?? $_POST["gjp2"] ?? ""));
        $mS = (int)($request->postInt("mS", 0) ?: ($_POST["mS"] ?? 0));
        $frS = (int)($request->postInt("frS", 0) ?: ($_POST["frS"] ?? 0));
        $cS = (int)($request->postInt("cS", 0) ?: ($_POST["cS"] ?? 0));
        $yt = (string)($request->postString("yt") ?: ($_POST["yt"] ?? ""));
        $twitter = (string)($request->postString("twitter") ?: ($_POST["twitter"] ?? ""));
        $twitch = (string)($request->postString("twitch") ?: ($_POST["twitch"] ?? ""));
        $instagram = (string)($request->postString("instagram") ?: ($_POST["instagram"] ?? ""));
        $discord = (string)($request->postString("discord") ?: ($_POST["discord"] ?? ""));
        $tiktok = (string)($request->postString("tiktok") ?: ($_POST["tiktok"] ?? ""));
        $custom = (string)($request->postString("custom") ?: ($_POST["custom"] ?? ""));

        if ($accountId <= 0 || $gjp === "") {
            return Response::text("-1");
        }

        try {
            $success = $this->service->updateSettings(
                $accountId,
                $gjp,
                $mS,
                $frS,
                $cS,
                $yt,
                $twitter,
                $twitch,
                $instagram,
                $discord,
                $tiktok,
                $custom
            );
            return Response::text($success ? "1" : "-1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function search(Request $request): Response
    {
        $query = (string)($request->postString("str") ?: ($_POST["str"] ?? ""));
        $page = (int)($request->postInt("page", 0) ?: ($_POST["page"] ?? 0));

        if ($query === "") {
            return Response::text("-1");
        }

        try {
            $result = $this->service->search($query, $page);
            return Response::text($result);
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    /**
     * GD 2.209 user-search endpoint, backed by the existing profile search.
     * Request key aliases are provisional until native captures exist.
     */
    public function search209(Request $request): Response
    {
        $query = $request->postString('str')
            ?: $request->postString('search')
            ?: $request->postString('query');
        $page = $request->postInt('page', 0);

        if ($query === '' || strlen($query) > 100 || $page < 0 || $page > 1000) {
            return Response::text('-1');
        }

        try {
            return Response::text($this->service->search($query, $page));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function profile(Request $request): Response
    {
        $targetAccountId = $request->postInt('targetAccountID');

        /* Some clients use accountID for the first self-profile request. */
        if ($targetAccountId <= 0) {
            $targetAccountId = $request->postInt('accountID');
        }

        $targetUserId = $request->postInt('targetUserID');

        if ($targetUserId <= 0) {
            $targetUserId = $request->postInt('userID');
        }

        try {
            if ($targetAccountId <= 0 && $targetUserId > 0) {
                $targetAccountId =
                    $this->service->resolveAccountIdByUserId(
                        $targetUserId
                    );
            }

            if ($targetAccountId <= 0) {
                return Response::text('-1');
            }

            $viewerAccountId = $request->postInt('accountID', 0);

            /*
             * Legacy GD clients authenticate profile requests when they
             * include accountID. Keep public profile lookup available when
             * no viewer account context is supplied.
             */
            if ($viewerAccountId > 0) {
                $credential = $request->gdCredential();

                if ($credential === '') {
                    return Response::text('-1');
                }

                $this->service->authenticate(
                    $viewerAccountId,
                    $credential
                );
            }

            return Response::text(
                $this->service->getProfile(
                    $targetAccountId,
                    $viewerAccountId,
                    $request->clientVersion()->effectiveGameVersion(),
                    $request->clientVersion()->binaryVersion
                )
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore][profile] account=%d user=%d | %s: %s',
                $targetAccountId,
                $targetUserId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function scores(Request $request): Response
    {
        $type=$request->postString('type');
        $accountId=$request->postInt('accountID');

        try {
            return Response::text(
                $this->service->getLeaderboard(
                    $type,
                    $accountId,
                    $request->clientVersion()->effectiveGameVersion(),
                    100,
                    $request->gdCredential()
                )
            );
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
