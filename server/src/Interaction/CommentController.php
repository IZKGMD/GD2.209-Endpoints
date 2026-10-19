<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class CommentController
{
    public function __construct(
        private CommentService $service
    ) {}

    private function getPostParam(Request $request, string $key): string
    {
        return (string)($request->postString($key) ?: ($_POST[$key] ?? ''));
    }

    public function uploadLevelComment(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $request->gdCredential();
        $version = $request->clientVersion();
        $udid = $request->postString("udid");
        $ip = $request->clientIp();
        $levelId = $request->postInt("levelID", 0) ?: (int)($_POST["levelID"] ?? 0);
        $content = $this->getPostParam($request, "comment");
        $percent = $request->postInt("percent", 0) ?: (int)($_POST["percent"] ?? 0);

        $legacyUdidUpload =
            $accountId <= 0 &&
            $gjp === '' &&
            $version->effectiveGameVersion() > 0 &&
            $version->effectiveGameVersion() < 19 &&
            trim($udid) !== '';

        if (
            ($accountId <= 0 && !$legacyUdidUpload) ||
            $levelId <= 0 ||
            $content === ""
        ) {
            $reason = $content === ''
                ? 'empty_comment'
                : ($levelId <= 0
                    ? 'invalid_level_id'
                    : 'missing_legacy_identity');

            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_comment_rejected account_id=%d level_id=%d version=%d family=%s has_udid=%d has_gjp=%d reason=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId,
                $levelId,
                $version->effectiveGameVersion(),
                $version->family(),
                trim($udid) !== '' ? 1 : 0,
                $gjp !== '' ? 1 : 0,
                $reason
            ));

            return Response::text("-1");
        }

        try {
            $result = $this->service->uploadLevelComment(
                $levelId,
                $accountId,
                $gjp,
                $content,
                $percent,
                $version->effectiveGameVersion(),
                $udid,
                $ip
            );

            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_comment_accepted level_id=%d version=%d result=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $levelId,
                $version->effectiveGameVersion(),
                $result
            ));

            return Response::text($result);
        } catch (Throwable $e) {
            $command = '';

            if (str_starts_with(trim($content), '!')) {
                $command = strtolower(
                    (string)(preg_split("/\\s+/", trim($content))[0] ?? '')
                );
            }

            error_log(sprintf(
                '[MuchoCore] request_id=%s upload_comment_failed account_id=%d level_id=%d version=%d family=%s has_udid=%d has_gjp=%d percent=%d command=%s exception=%s message=%s',
                (string)($_SERVER['MUCHO_REQUEST_ID'] ?? '-'),
                $accountId,
                $levelId,
                $version->effectiveGameVersion(),
                $version->family(),
                trim($udid) !== '' ? 1 : 0,
                $gjp !== '' ? 1 : 0,
                $percent,
                $command,
                $e::class,
                $e->getMessage()
            ));

            return Response::text("-1");
        }
    }

    public function getLevelComments(Request $request): Response
    {
        $levelId = $request->postInt("levelID", 0) ?: (int)($_POST["levelID"] ?? 0);
        $userId = $request->postInt("userID", 0) ?: (int)($_POST["userID"] ?? 0);
        $page = $request->postInt("page", 0) ?: (int)($_POST["page"] ?? 0);
        $count = $request->postInt("count", 10) ?: (int)($_POST["count"] ?? 10);
        $mode = $request->postInt("mode", 0) ?: (int)($_POST["mode"] ?? 0);
        $version = $request->clientVersion();

        try {
            if ($levelId <= 0 && $userId > 0) {
                return Response::text(
                    $this->service->getUserComments(
                        $userId,
                        $page,
                        $count,
                        $mode,
                        $version->effectiveGameVersion(),
                        $version->binaryVersion
                    )
                );
            }

            return Response::text(
                $this->service->getLevelComments(
                    $levelId,
                    $page,
                    $version->effectiveGameVersion(),
                    $version->binaryVersion,
                    $count,
                    $mode
                )
            );
        } catch (Throwable) {
            return Response::text("#0:0:10");
        }
    }

    public function uploadAccountComment(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $request->gdCredential();
        $version = $request->clientVersion();
        $udid = $request->postString("udid");
        $ip = $request->clientIp();
        $content = $this->getPostParam($request, "comment");

        if ($accountId <= 0 || $content === "") {
            return Response::text("-1");
        }

        try {
            $this->service->uploadAccountComment(
                $accountId,
                $gjp,
                $content,
                $version->effectiveGameVersion(),
                $udid,
                $ip
            );

            return Response::text("1");
        } catch (Throwable $e) {
            return Response::text("-1");
        }
    }

    public function getAccountComments(Request $request): Response
    {
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $page = $request->postInt("page", 0) ?: (int)($_POST["page"] ?? 0);

        try {
            return Response::text(
                $this->service->getAccountComments(
                    $accountId,
                    $page,
                    $request->clientVersion()->effectiveGameVersion(),
                    $request->clientVersion()->binaryVersion
                )
            );
        } catch (Throwable) {
            return Response::text("#0:0:10");
        }
    }

    public function deleteComment(Request $request): Response
    {
        $commentId = $request->postInt("commentID", 0) ?: (int)($_POST["commentID"] ?? 0);
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $request->gdCredential();
        $version = $request->clientVersion();
        $udid = $request->postString("udid");
        $ip = $request->clientIp();

        try {
            return Response::text(
                $this->service->deleteComment(
                    $commentId,
                    $accountId,
                    $gjp,
                    $version->effectiveGameVersion(),
                    $udid,
                    $ip
                ) ? "1" : "-1"
            );
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function deleteAccountComment(Request $request): Response
    {
        $commentId = $request->postInt("commentID", 0) ?: (int)($_POST["commentID"] ?? 0);
        $accountId = $request->postInt("accountID", 0) ?: (int)($_POST["accountID"] ?? 0);
        $gjp = $request->gdCredential();
        $version = $request->clientVersion();
        $udid = $request->postString("udid");
        $ip = $request->clientIp();

        try {
            return Response::text(
                $this->service->deleteAccountComment(
                    $commentId,
                    $accountId,
                    $gjp,
                    $version->effectiveGameVersion(),
                    $udid,
                    $ip
                ) ? "1" : "-1"
            );
        } catch (Throwable) {
            return Response::text("-1");
        }
    }
}
