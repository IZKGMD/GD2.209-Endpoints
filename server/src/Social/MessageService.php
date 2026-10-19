<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdMessageEncoder;

final readonly class MessageService
{
    public function __construct(
        private MessageRepository $repository,
        private AccountAuthenticator $auth,
        private GdMessageEncoder $encoder,
        private RelationshipRepository $relationships
    ) {}

    public function getMessages(
        int $id,
        string $gjp,
        int $page,
        bool $sent
    ): string {
        $this->auth->authenticate($id, $gjp);
        $page = min(1000, max(0, $page));

        return $this->encoder->encodeList(
            $this->repository->list($id, $sent, $page),
            $this->repository->count($id, $sent),
            max(0, $page) * 10,
            10,
            $sent
        );
    }

    public function readMessage(
        int $id,
        string $gjp,
        int $messageId
    ): string {
        $this->auth->authenticate($id, $gjp);

        $m = $this->repository->read($messageId, $id);

        if (!$m) {
            return '-1';
        }

        return $this->encoder->encodeMessage(
            $m,
            (int)$m['account_id'] === $id
        );
    }

    public function sendMessage(
        int $id,
        string $gjp,
        int $to,
        string $subject,
        string $body
    ): bool {
        $this->auth->authenticate($id, $gjp);

        if ($this->repository->isMessageBanned($id)) {
            return false;
        }

        if (
            $to <= 0 ||
            $subject === '' ||
            $body === '' ||
            strlen($subject) > 255 ||
            strlen($body) > 65535 ||
            !$this->relationships->canMessage($id, $to)
        ) {
            return false;
        }

        return $this->repository->send(
            $id,
            $to,
            $subject,
            $body
        );
    }

    public function deleteMessage(
        int $id,
        string $gjp,
        int $messageId,
        bool $sent
    ): bool {
        $this->auth->authenticate($id, $gjp);

        return $messageId > 0
            && $this->repository->delete(
                $messageId,
                $id,
                $sent
            );
    }
}
