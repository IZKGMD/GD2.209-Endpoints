<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

final readonly class ClanService
{
    public function __construct(
        private PDO $pdo,
        private AccountAuthenticator $auth,
        private ClanRepository $repository
    ) {}

    public function create(
        int $accountId,
        string $credential,
        string $name,
        string $tag,
        string $description='',
        bool $isOpen=true,
        int $maxMembers=50
    ): array {
        $this->auth->authenticate($accountId,$credential);

        if ($this->repository->getForAccount($accountId)!==null) {
            throw new RuntimeException('Account is already in a clan.');
        }

        $name=$this->normalizeName($name);
        $tag=$this->normalizeTag($tag);
        $description=$this->normalizeDescription($description);
        $maxMembers=max(2,min(500,$maxMembers));

        if ($name==='' || $tag==='') {
            throw new RuntimeException('Invalid clan name or tag.');
        }

        try {
            return $this->repository->create(
                $accountId,
                $name,
                $tag,
                $description,
                $isOpen,
                $maxMembers
            );
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0)===1062) {
                throw new RuntimeException('Clan name or tag already exists.');
            }

            throw $e;
        }
    }

    public function get(int $accountId,string $credential,int $clanId): array {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getById($clanId);

        if ($clan===null) {
            throw new RuntimeException('Clan not found.');
        }

        $clan['members']=$this->repository->members($clanId);
        return $clan;
    }

    public function myClan(int $accountId,string $credential): ?array {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);

        if ($clan!==null) {
            $clan['members']=$this->repository->members((int)$clan['clan_id']);
        }

        return $clan;
    }

    public function search(int $accountId,string $credential,string $query,int $offset=0,int $limit=20): array {
        $this->auth->authenticate($accountId,$credential);
        return $this->repository->search(substr(trim($query),0,48),max(0,min(100000,$offset)),max(1,min(51,$limit)));
    }

    public function leaderboard(string $metric,int $offset=0,int $limit=20,int $accountId=0): array {
        return $this->repository->leaderboard($metric,$offset,$limit,$accountId);
    }

    public function join(int $accountId,string $credential,int $clanId): bool {
        $this->auth->authenticate($accountId,$credential);

        if ($this->repository->getForAccount($accountId)!==null) {
            throw new RuntimeException('Account is already in a clan.');
        }

        $clan=$this->repository->getById($clanId);
        if ($clan===null || (int)$clan['is_open']!==1) {
            throw new RuntimeException('Clan is not open for joining.');
        }

        if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
            throw new RuntimeException('Clan is full.');
        }

        try {
            return $this->repository->join($clanId,$accountId);
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0)===1062) {
                throw new RuntimeException('Account is already in a clan.');
            }

            throw $e;
        }
    }

    public function leave(int $accountId,string $credential): bool {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);

        if ($clan===null) {
            return false;
        }

        if ((string)$clan['role']==='owner') {
            throw new RuntimeException('Clan owner cannot leave the clan.');
        }

        return $this->repository->removeMember((int)$clan['clan_id'],$accountId);
    }

    public function invite(int $accountId,string $credential,int $targetAccountId): bool {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null || !in_array((string)$clan['role'],['owner','officer'],true)) {
            throw new RuntimeException('Clan officer permission required.');
        }

        if ($targetAccountId<=0 || $targetAccountId===$accountId) {
            throw new RuntimeException('Invalid invite target.');
        }

        if ($this->repository->getForAccount($targetAccountId)!==null) {
            throw new RuntimeException('Target account is already in a clan.');
        }

        if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
            throw new RuntimeException('Clan is full.');
        }

        return $this->repository->invite((int)$clan['clan_id'],$targetAccountId,$accountId);
    }

    public function acceptInvite(int $accountId,string $credential,int $inviteId): bool {
        $this->auth->authenticate($accountId,$credential);
        $accepted=$this->repository->acceptInvite($inviteId,$accountId);

        if ($accepted) {
            $this->audit($accountId,'clan.invite.accepted','clan_invite',$inviteId);
        }

        return $accepted;
    }

    public function declineInvite(int $accountId,string $credential,int $inviteId): bool {
        $this->auth->authenticate($accountId,$credential);
        return $this->repository->deleteInvite($inviteId,$accountId);
    }

    public function kick(int $accountId,string $credential,int $targetAccountId): bool {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        $target=$this->repository->getForAccount($targetAccountId);

        if ($clan===null || $target===null || (int)$clan['clan_id']!==(int)$target['clan_id']) {
            throw new RuntimeException('Target is not in your clan.');
        }

        $kicked=$this->repository->kick(
            (int)$clan['clan_id'],
            $accountId,
            $targetAccountId
        );

        if ($kicked) {
            $this->audit(
                $accountId,
                'clan.member.kicked',
                'account',
                $targetAccountId,
                ['clan_id'=>(int)$clan['clan_id']]
            );
        }

        return $kicked;
    }

    public function setRole(int $accountId,string $credential,int $targetAccountId,string $role): bool {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null) {
            throw new RuntimeException('You are not in a clan.');
        }

        $updated=$this->repository->setRole(
            (int)$clan['clan_id'],
            $accountId,
            $targetAccountId,
            $role
        );

        if ($updated) {
            $this->audit(
                $accountId,
                'clan.member.role_changed',
                'account',
                $targetAccountId,
                [
                    'clan_id'=>(int)$clan['clan_id'],
                    'role'=>$role,
                ]
            );
        }

        return $updated;
    }

    public function updateSettings(
        int $accountId,
        string $credential,
        int $clanId,
        string $name,
        string $tag,
        string $description='',
        bool $isOpen=true,
        int $maxMembers=50
    ): array {
        $this->auth->authenticate($accountId,$credential);

        $name=$this->normalizeName($name);
        $tag=$this->normalizeTag($tag);
        $description=$this->normalizeDescription($description);
        $maxMembers=max(2,min(500,$maxMembers));

        if ($name==='' || $tag==='') {
            throw new RuntimeException('Invalid clan name or tag.');
        }

        $result=$this->repository->updateSettings(
            $clanId,
            $accountId,
            $name,
            $tag,
            $description,
            $isOpen,
            $maxMembers
        );

        $this->audit(
            $accountId,
            'clan.settings.updated',
            'clan',
            $clanId,
            ['is_open'=>$isOpen,'max_members'=>$maxMembers]
        );

        return $result;
    }

    public function transferOwnership(
        int $accountId,
        string $credential,
        int $targetAccountId
    ): bool {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);

        if ($clan===null) {
            throw new RuntimeException('You are not in a clan.');
        }

        $transferred=$this->repository->transferOwnership(
            (int)$clan['clan_id'],
            $accountId,
            $targetAccountId
        );

        if ($transferred) {
            $this->audit(
                $accountId,
                'clan.ownership.transferred',
                'clan',
                (int)$clan['clan_id'],
                ['new_owner_account_id'=>$targetAccountId]
            );
        }

        return $transferred;
    }

    public function disband(
        int $accountId,
        string $credential
    ): bool {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);

        if ($clan===null) {
            throw new RuntimeException('You are not in a clan.');
        }

        $clanId=(int)$clan['clan_id'];
        $disbanded=$this->repository->disband($clanId,$accountId);

        if ($disbanded) {
            $this->audit($accountId,'clan.disbanded','clan',$clanId);
        }

        return $disbanded;
    }

    public function revokeInvite(
        int $accountId,
        string $credential,
        int $inviteId
    ): bool {
        $this->auth->authenticate($accountId,$credential);

        $revoked=$this->repository->revokeInvite($inviteId,$accountId);

        if ($revoked) {
            $this->audit(
                $accountId,
                'clan.invite.revoked',
                'clan_invite',
                $inviteId
            );
        }

        return $revoked;
    }

    public function ban(
        int $accountId,
        string $credential,
        int $targetAccountId,
        string $reason=''
    ): bool {
        $this->auth->authenticate($accountId,$credential);

        if ($targetAccountId<=0 || $targetAccountId===$accountId) {
            throw new RuntimeException('Invalid ban target.');
        }

        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null) {
            throw new RuntimeException('You are not in a clan.');
        }

        $reason=$this->normalizeDescription($reason);
        $banned=$this->repository->ban(
            (int)$clan['clan_id'],
            $accountId,
            $targetAccountId,
            $reason
        );

        if ($banned) {
            $this->audit(
                $accountId,
                'clan.member.banned',
                'account',
                $targetAccountId,
                ['clan_id'=>(int)$clan['clan_id']]
            );
        }

        return $banned;
    }

    public function unban(
        int $accountId,
        string $credential,
        int $targetAccountId
    ): bool {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null) {
            throw new RuntimeException('You are not in a clan.');
        }

        $unbanned=$this->repository->unban(
            (int)$clan['clan_id'],
            $accountId,
            $targetAccountId
        );

        if ($unbanned) {
            $this->audit(
                $accountId,
                'clan.member.unbanned',
                'account',
                $targetAccountId,
                ['clan_id'=>(int)$clan['clan_id']]
            );
        }

        return $unbanned;
    }

    public function bans(int $accountId,string $credential): array {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null || !in_array((string)$clan['role'],['owner','officer'],true)) {
            throw new RuntimeException('Clan officer permission required.');
        }

        return $this->repository->bans((int)$clan['clan_id']);
    }

    public function invites(int $accountId,string $credential): array {
        $this->auth->authenticate($accountId,$credential);
        return $this->repository->invitations($accountId);
    }

    public function sentInvites(int $accountId,string $credential): array {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null || !in_array((string)$clan['role'],['owner','officer'],true)) {
            throw new RuntimeException('Clan officer permission required.');
        }
        return $this->repository->sentInvitations((int)$clan['clan_id']);
    }

    private function audit(
        int $accountId,
        string $action,
        ?string $targetType=null,
        ?int $targetId=null,
        array $metadata=[]
    ): void {
        try {
            $stmt=$this->pdo->prepare(
                'INSERT INTO audit_logs
                    (account_id, action, target_type, target_id, metadata)
                 VALUES
                    (:account_id, :action, :target_type, :target_id, :metadata)'
            );
            $stmt->execute([
                'account_id'=>$accountId,
                'action'=>$action,
                'target_type'=>$targetType,
                'target_id'=>$targetId,
                'metadata'=>json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
                ),
            ]);
        } catch (Throwable) {
            // Auditing must never make a valid clan operation fail.
        }
    }

    private function normalizeName(string $name): string {
        $name=trim(preg_replace('/\s+/',' ',$name) ?? '');

        return strlen($name)<=24 &&
            preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{1,23}$/D',$name)===1
            ? $name
            : '';
    }

    private function normalizeTag(string $tag): string {
        $tag=strtoupper(trim($tag));

        return strlen($tag)<=6 &&
            preg_match('/^[A-Z0-9]{2,6}$/D',$tag)===1
            ? $tag
            : '';
    }

    private function normalizeDescription(string $description): string {
        $description=trim(preg_replace('/\s+/u',' ',$description) ?? '');
        preg_match('/^.{0,160}/us',$description,$match);
        return $match[0] ?? '';
    }
}
