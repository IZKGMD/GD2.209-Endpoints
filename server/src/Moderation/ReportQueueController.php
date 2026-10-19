<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/**
 * GD 2.209 adminGetReportQueue; observed native POST keys include:
 * accountID, gjp2, flags, mode, page, sort, binaryVersion and gameVersion.
 * The original *response* still needs a captured successful example.
 */
final readonly class ReportQueueController
{
    public function __construct(private ReportQueueService $service) {}

    public function queue(Request $request): Response
    {
        $accountId = $request->postInt('accountID');
        $credential = $request->gdCredential();
        if ($accountId <= 0 || $credential === '') {
            return Response::text('-1');
        }
        try {
            return Response::text($this->service->getQueue(
                $accountId,
                $credential,
                $request->postInt('page'),
                $request->postInt('sort'),
                $request->postInt('mode'),
                $request->postInt('flags')
            ));
        } catch (Throwable $e) {
            // Log class only: no credentials, account IDs or untrusted details.
            error_log('[MuchoCore] adminGetReportQueue failed: ' . $e::class);
            return Response::text('-1');
        }
    }

    /**
     * Level-only report adapter. Report type identifiers for the other
     * 2.209 content families remain unverified.
     */
    public function submitLevel(Request $request): Response
    {
        $accountId = $request->postInt('accountID');
        $credential = $request->gdCredential();
        $levelId = $request->postInt('levelID');
        if ($accountId <= 0 || $credential === '' || $levelId <= 0) {
            return Response::text('-1');
        }
        try {
            return Response::text($this->service->reportLevel(
                $accountId,
                $credential,
                $levelId,
                $request->postInt('reason'),
                $request->postString('details')
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
