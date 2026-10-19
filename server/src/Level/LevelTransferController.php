<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class LevelTransferController
{
    public function __construct(
        private LevelTransferService $service
    ) {}

    private function credential(Request $request): string
    {
        return $request->gdCredential();
    }

    public function upload(Request $request): Response
    {
        $accountId = $request->postInt('accountID');
        $credential = $this->credential($request);
        $version = $request->clientVersion();
        $udid = $request->postString('udid');

        if (
            $accountId <= 0 &&
            !(
                $version->effectiveGameVersion() > 0 &&
                $version->effectiveGameVersion() < 19 &&
                trim($udid) !== ''
            )
        ) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_level_rejected account_id=%d reason=missing_account_id',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId
            ));

            return Response::text('-1');
        }

        if (
            $credential === '' &&
            !(
                $version->effectiveGameVersion() > 0 &&
                $version->effectiveGameVersion() < 20
            )
        ) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_level_rejected account_id=%d reason=missing_credentials has_gjp=%d has_gjp2=%d family=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId,
                $request->postString('gjp') !== '' ? 1 : 0,
                $request->postString('gjp2') !== '' ? 1 : 0,
                $version->family()
            ));

            return Response::text('-1');
        }

        try {
            $data = $request->post;

            /*
             * Genuine GD 1.9 update/upload requests can omit version fields.
             * Preserve the controller-level version inference for the service.
             */
            if (
                $request->postInt('gameVersion', 0) === 0 &&
                $version->effectiveGameVersion() > 0
            ) {
                $data['gameVersion'] = $version->effectiveGameVersion();
            }

            $levelId = $this->service->upload(
                $accountId,
                $credential,
                $data,
                $udid,
                $request->clientIp()
            );

            return Response::text((string)$levelId);
        } catch (Throwable $e) {
            /* Do not log credentials or level payloads. */
            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_level_failed account_id=%d exception=%s message=%s file=%s line=%d',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return Response::text('-1');
        }
    }

    public function checkUpdate(Request $request): Response
    {
        $levelId = $request->postInt('levelID');
        $clientLevelVersion = $request->postInt('levelVersion', 1);

        if ($levelId <= 0 || $clientLevelVersion <= 0) {
            return Response::text('-1');
        }

        try {
            return Response::text(
                $this->service->checkUpdate(
                    $levelId,
                    $clientLevelVersion
                )
            );
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s check_level_update_failed level_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function download(Request $request): Response
    {
        $levelId = $request->postInt('levelID');
        $version = $request->clientVersion();
        $gameVersion = $version->effectiveGameVersion() ?: 22;
        $binaryVersion = $version->binaryVersion;
        $extras = $request->postInt('extras', 0) === 1;
        $incrementDownloads = $request->postInt('inc', 0) === 1;

        if (
            $levelId === 0 ||
            $levelId < -3
        ) {
            return Response::text('-1');
        }

        try {
            $result = $this->service->download(
                $levelId,
                $gameVersion,
                $binaryVersion,
                $extras,
                $incrementDownloads,
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->clientIp()
            );

            return Response::text($result);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s download_level_failed level_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function updateDescription(
        Request $request
    ): Response {
        $levelId = $request->postInt('levelID');
        $accountId = $request->postInt('accountID');
        $credential = $this->credential($request);
        $version = $request->clientVersion();
        $description = $request->postString('levelDesc');

        if (
            $levelId <= 0 ||
            $accountId <= 0 ||
            $credential === ''
        ) {
            return Response::text('-1');
        }

        try {
            $success = $this->service->updateDescription(
                $levelId,
                $accountId,
                $credential,
                $description,
                $version->effectiveGameVersion() ?: 22
            );

            return Response::text(
                $success ? '1' : '-1'
            );

        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s update_level_desc_failed level_id=%d account_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $accountId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }

    public function delete(Request $request): Response
    {
        $levelId = $request->postInt('levelID');
        $accountId = $request->postInt('accountID');
        $credential = $this->credential($request);

        if (
            $levelId <= 0 ||
            $accountId <= 0 ||
            $credential === ''
        ) {
            return Response::text('-1');
        }

        try {
            $success = $this->service->delete(
                $levelId,
                $accountId,
                $credential
            );

            return Response::text($success ? '1' : '-1');
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] request_id=%s delete_level_failed level_id=%d account_id=%d exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $accountId,
                $e::class,
                $e->getMessage()
            ));

            return Response::text('-1');
        }
    }
}
