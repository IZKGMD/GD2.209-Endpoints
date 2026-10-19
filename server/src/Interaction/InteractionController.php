<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final class InteractionController
{
    public function __construct(
        private readonly CommentService $commentService
    ) {}

    public function uploadLevelComment(Request $request): Response
    {
        $levelId = $request->postInt("levelID");
        $accountId = $request->postInt("accountID");
        $gjp = $request->postString("gjp") ?: $request->postString("gjp2");
        $comment = $request->postString("comment");
        $percent = $request->postInt("percent", 0);

        if (!$levelId || !$accountId || $gjp === "" || $comment === "") {
            return Response::text("-1");
        }

        try {
            $this->commentService->uploadLevelComment($levelId, $accountId, $gjp, $comment, $percent);
            return Response::text("1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function uploadComment(Request $request): Response
    {
        return $this->uploadLevelComment($request);
    }

    public function getLevelComments(Request $request): Response
    {
        $levelId = $request->postInt("levelID");
        $page = $request->postInt("page", 0);

        if (!$levelId) {
            return Response::text("-1");
        }

        try {
            $response = $this->commentService->getLevelComments($levelId, $page);
            return Response::text($response);
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function getComments(Request $request): Response
    {
        return $this->getLevelComments($request);
    }

    public function uploadAccountComment(Request $request): Response
    {
        $accountId = $request->postInt("accountID");
        $gjp = $request->postString("gjp") ?: $request->postString("gjp2");
        $comment = $request->postString("comment");

        if (!$accountId || $gjp === "" || $comment === "") {
            return Response::text("-1");
        }

        try {
            $this->commentService->uploadAccountComment($accountId, $gjp, $comment);
            return Response::text("1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function getAccountComments(Request $request): Response
    {
        $accountId = $request->postInt("accountID");
        $page = $request->postInt("page", 0);

        if (!$accountId) {
            return Response::text("-1");
        }

        try {
            $response = $this->commentService->getAccountComments($accountId, $page);
            return Response::text($response);
        } catch (Throwable) {
            return Response::text("#0:0:10");
        }
    }

    public function deleteAccountComment(Request $request): Response
    {
        $commentId = $request->postInt("commentID");
        $accountId = $request->postInt("accountID");
        $gjp = $request->postString("gjp") ?: $request->postString("gjp2");

        if (!$commentId || !$accountId || $gjp === "") {
            return Response::text("-1");
        }

        try {
            $this->commentService->deleteComment($commentId, $accountId, $gjp);
            return Response::text("1");
        } catch (Throwable) {
            return Response::text("-1");
        }
    }

    public function deleteComment(Request $request): Response
    {
        return $this->deleteAccountComment($request);
    }

    public function deleteLevelComment(Request $request): Response
    {
        return $this->deleteAccountComment($request);
    }

    public function likeItem(Request $request): Response
    {
        return Response::text("1");
    }

    public function rateStars(Request $request): Response
    {
        return Response::text("1");
    }

    public function rateDemon(Request $request): Response
    {
        return Response::text("1");
    }
}
