<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdRelationshipEncoder;

final readonly class RelationshipService
{
    public function __construct(
        private RelationshipRepository $repo,
        private AccountAuthenticator $auth,
        private GdRelationshipEncoder $encoder
    ) {}

    private function auth(int $id, string $gjp): void
    {
        $this->auth->authenticate($id, $gjp);
    }

    public function send(
        int $id,
        string $gjp,
        int $target,
        string $comment
    ): bool {
        $this->auth($id, $gjp);

        return $target > 0
            && strlen($comment) <= 140
            && $this->repo->createRequest($id, $target, $comment);
    }

    public function get(
        int $id,
        string $gjp,
        int $page,
        bool $sent
    ): string {
        $this->auth($id, $gjp);
        $page = min(1000, max(0, $page));

        return $this->encoder->requests(
            $this->repo->requests($id, $sent, $page),
            $this->repo->requestCount($id, $sent),
            max(0, $page) * 10,
            $sent
        );
    }

    public function read(
        int $id,
        string $gjp,
        int $requestId
    ): bool {
        $this->auth($id, $gjp);
        return $requestId > 0
            && $this->repo->readRequest($id, $requestId);
    }

    public function accept(
        int $id,
        string $gjp,
        int $requestId
    ): bool {
        $this->auth($id, $gjp);
        return $requestId > 0
            && $this->repo->accept($id, $requestId);
    }

    public function delete(
        int $id,
        string $gjp,
        int $target,
        bool $sent
    ): bool {
        $this->auth($id, $gjp);
        return $target > 0
            && $this->repo->deleteRequest($id, $target, $sent);
    }

    public function remove(
        int $id,
        string $gjp,
        int $target
    ): bool {
        $this->auth($id, $gjp);
        return $target > 0
            && $this->repo->removeFriend($id, $target);
    }

    public function block(
        int $id,
        string $gjp,
        int $target
    ): bool {
        $this->auth($id, $gjp);
        return $target > 0
            && $this->repo->block($id, $target);
    }

    public function unblock(
        int $id,
        string $gjp,
        int $target
    ): bool {
        $this->auth($id, $gjp);
        return $target > 0
            && $this->repo->unblock($id, $target);
    }

    public function userList(
        int $id,
        string $gjp,
        int $type,
        bool $include209Stats = false
    ): string {
        $this->auth($id, $gjp);
        return $this->encoder->users(
            $this->repo->userList($id, $type),
            $include209Stats
        );
    }
}
