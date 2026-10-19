<?php

declare(strict_types=1);

namespace MuchoCore\CloudSave;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/*
 * MuchoCore Secure Cloud Save Controller
 * Copyright (C) 2026 IZK
 */
final readonly class CloudSaveController
{
    public function __construct(
        private CloudSaveService $service
    ) {}


    public function backup(
        Request $request
    ): Response {

        $data=!empty($request->post)
            ? $request->post
            : $_POST;


        try {

            return Response::text(
                $this->service->backup(
                    $data
                )
            );

        } catch(Throwable $e) {

            error_log(sprintf(
                '[MuchoCore] request_id=%s cloud_backup_failed account_id=%d exception=%s',
                (string)(
                    $_SERVER['MUCHO_REQUEST_ID']
                    ?? '-'
                ),
                (int)($data['accountID'] ?? 0),
                $e::class
            ));

            return Response::text('-1');
        }
    }


    public function sync(
        Request $request
    ): Response {

        $data=!empty($request->post)
            ? $request->post
            : $_POST;


        try {

            return Response::text(
                $this->service->sync(
                    $data
                )
            );

        } catch(Throwable $e) {

            error_log(sprintf(
                '[MuchoCore] request_id=%s cloud_sync_failed account_id=%d exception=%s',
                (string)(
                    $_SERVER['MUCHO_REQUEST_ID']
                    ?? '-'
                ),
                (int)($data['accountID'] ?? 0),
                $e::class
            ));

            return Response::text('-1');
        }
    }
}
