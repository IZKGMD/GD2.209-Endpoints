<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use PDO;
use RuntimeException;
use Throwable;

final readonly class ClanRepository
{
    public const LEADERBOARD_METRICS = ['stars','demons','moons','diamonds','user_coins','secret_coins','creator_points'];

    public function __construct(private PDO $pdo, private ?string $activityActor = null) {}

    public function leaderboard(string $metric,int $offset,int $limit,int $accountId=0): array
    {
        if (!in_array($metric,self::LEADERBOARD_METRICS,true)) {
            throw new RuntimeException('Invalid clan leaderboard metric.');
        }
        $offset=max(0,min(100000,$offset));
        $limit=max(1,min(50,$limit));
        $sums=[];
        foreach (self::LEADERBOARD_METRICS as $column) {
            $sums[]="COALESCE(SUM(CASE WHEN a.is_active=1 AND a.is_banned=0
                THEN GREATEST(COALESCE(p.$column,0),0) ELSE 0 END),0) AS $column";
        }
        // Rank once, before selecting the requested page and the caller's clan.
        // The account ID only identifies a public ranking; it grants no permissions.
        $stmt=$this->pdo->prepare(
            'WITH totals AS (
                SELECT c.clan_id,c.name,c.tag,COALESCE(MAX(s.prefix_color),\'FFD166\') AS prefix_color,COUNT(m.account_id) AS member_count,
                       MAX(CASE WHEN m.account_id=:account_id THEN 1 ELSE 0 END) AS is_own,
                       '.implode(',',$sums).'
                FROM mucho_clans c
                LEFT JOIN mucho_clan_members m ON m.clan_id=c.clan_id
                LEFT JOIN accounts a ON a.account_id=m.account_id
                LEFT JOIN profiles p ON p.account_id=m.account_id
                LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id
                GROUP BY c.clan_id,c.name,c.tag
             ), ranked AS (
                SELECT totals.*,ROW_NUMBER() OVER (ORDER BY '.$metric.' DESC,clan_id ASC) AS `rank`,
                       COUNT(*) OVER () AS total_clans
                FROM totals
             )
             SELECT * FROM ranked
             WHERE (`rank`>'.(int)$offset.' AND `rank`<='.(int)($offset+$limit+1).') OR is_own=1
             ORDER BY `rank`'
        );
        $stmt->execute(['account_id'=>max(0,$accountId)]);
        $clans=[]; $own=null; $total=0; $hasMore=false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total=(int)$row['total_clans'];
            unset($row['total_clans']);
            foreach (array_merge(self::LEADERBOARD_METRICS,['clan_id','rank','member_count']) as $column) {
                $row[$column]=(int)$row[$column];
            }
            $row['is_own']=(bool)$row['is_own'];
            $row['score']=$row[$metric];
            if ($row['is_own']) $own=$row;
            if ($row['rank']>$offset && $row['rank']<=$offset+$limit) $clans[]=$row;
            if ($row['rank']===$offset+$limit+1) $hasMore=true;
        }
        if ($total===0) $total=(int)$this->pdo->query('SELECT COUNT(*) FROM mucho_clans')->fetchColumn();
        return ['metric'=>$metric,'offset'=>$offset,'limit'=>$limit,'total_clans'=>$total,
                'has_more'=>$hasMore,'clans'=>$clans,'own_clan'=>$own];
    }

    public function create(
        int $ownerAccountId,
        string $name,
        string $tag,
        string $description,
        bool $isOpen,
        int $maxMembers
    ): array {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mucho_clans
                    (name, tag, description, owner_account_id, is_open, max_members)
                 VALUES
                    (:name, :tag, :description, :owner_account_id, :is_open, :max_members)'
            );
            $stmt->execute([
                'name'=>$name,
                'tag'=>$tag,
                'description'=>$description,
                'owner_account_id'=>$ownerAccountId,
                'is_open'=>$isOpen ? 1 : 0,
                'max_members'=>$maxMembers,
            ]);

            $clanId=(int)$this->pdo->lastInsertId();

            $member=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'owner')"
            );
            $member->execute([
                'clan_id'=>$clanId,
                'account_id'=>$ownerAccountId,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            $quests->event($clanId,($this->activityActor ?? $quests->player($ownerAccountId)).' created the clan.');

            $this->pdo->commit();

            return $this->getById($clanId)
                ?? throw new RuntimeException('Created clan could not be loaded.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function getById(int $clanId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*, COALESCE(s.prefix_color,\'FFD166\') AS prefix_color, a.username AS owner_username,
                    (SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count
             FROM mucho_clans c
             INNER JOIN accounts a ON a.account_id=c.owner_account_id
             LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id
             WHERE c.clan_id=:clan_id
             LIMIT 1'
        );
        $stmt->execute(['clan_id'=>$clanId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getForAccount(int $accountId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*, COALESCE(s.prefix_color,\'FFD166\') AS prefix_color, m.role,
                    (SELECT COUNT(*) FROM mucho_clan_members mc WHERE mc.clan_id=c.clan_id) AS member_count
             FROM mucho_clan_members m
             INNER JOIN mucho_clans c ON c.clan_id=m.clan_id
             LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id
             WHERE m.account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute(['account_id'=>$accountId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function search(string $query, int $offset=0, int $limit=20): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*, COALESCE(s.prefix_color,\'FFD166\') AS prefix_color, a.username AS owner_username,
                    (SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count
             FROM mucho_clans c
             INNER JOIN accounts a ON a.account_id=c.owner_account_id
             LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id
             WHERE c.name LIKE :name OR c.tag LIKE :tag
             ORDER BY c.created_at DESC, c.clan_id DESC
             LIMIT '.(int)$limit.' OFFSET '.(int)$offset
        );
        $stmt->execute([
            'name'=>'%'.$query.'%',
            'tag'=>'%'.$query.'%',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function members(int $clanId): array
    {
        $stmt=$this->pdo->prepare(
            "SELECT m.account_id, m.role, m.joined_at,
                    a.username, p.user_id, COALESCE(u.nickname_color,'') AS nickname_color
             FROM mucho_clan_members m
             INNER JOIN accounts a ON a.account_id=m.account_id
             LEFT JOIN profiles p ON p.account_id=a.account_id
             LEFT JOIN mucho_user_appearance u ON u.account_id=a.account_id
             WHERE m.clan_id=:clan_id
             ORDER BY CASE m.role
                    WHEN 'owner' THEN 0
                    WHEN 'officer' THEN 1
                    ELSE 2
                  END,
                  a.username ASC"
        );
        $stmt->execute(['clan_id'=>$clanId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function member(int $clanId, int $accountId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT m.*, a.username
             FROM mucho_clan_members m
             INNER JOIN accounts a ON a.account_id=m.account_id
             WHERE m.clan_id=:clan_id AND m.account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function join(int $clanId, int $accountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clanStmt=$this->pdo->prepare(
                'SELECT c.clan_id, c.is_open, c.max_members,
                        (SELECT COUNT(*)
                         FROM mucho_clan_members m
                         WHERE m.clan_id=c.clan_id) AS member_count
                 FROM mucho_clans c
                 WHERE c.clan_id=:clan_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $clanStmt->execute(['clan_id'=>$clanId]);
            $clan=$clanStmt->fetch(PDO::FETCH_ASSOC);

            if (!$clan) {
                throw new RuntimeException('Clan not found.');
            }

            if ((int)$clan['is_open'] !== 1) {
                throw new RuntimeException('Clan is not open for joining.');
            }

            if ($this->isBanned($clanId,$accountId)) {
                throw new RuntimeException('You are banned from this clan.');
            }

            if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
                throw new RuntimeException('Clan is full.');
            }

            $existing=$this->pdo->prepare(
                'SELECT 1 FROM mucho_clan_members
                 WHERE account_id=:account_id
                 LIMIT 1'
            );
            $existing->execute(['account_id'=>$accountId]);

            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException('Account is already in a clan.');
            }

            $quests=new ClanQuestRepository($this->pdo);
            $quests->settle($clanId);

            $stmt=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'member')"
            );
            $stmt->execute([
                'clan_id'=>$clanId,
                'account_id'=>$accountId,
            ]);

            $invite=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $invite->execute([
                'clan_id'=>$clanId,
                'account_id'=>$accountId,
            ]);

            $quests->enroll($clanId,$accountId);
            $quests->event($clanId,$quests->player($accountId).' joined the clan.');
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function acceptInvite(int $inviteId, int $accountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            /*
             * Lock order is clan -> invitation. This matches clan-wide
             * management writes and avoids the invite -> clan inversion.
             */
            $lookup=$this->pdo->prepare(
                'SELECT clan_id
                 FROM mucho_clan_invites
                 WHERE invite_id=:invite_id
                   AND account_id=:account_id
                   AND expires_at>UTC_TIMESTAMP()
                 LIMIT 1'
            );
            $lookup->execute([
                'invite_id'=>$inviteId,
                'account_id'=>$accountId,
            ]);
            $clanId=(int)($lookup->fetchColumn() ?: 0);

            if ($clanId<=0) {
                throw new RuntimeException(
                    'Clan invitation not found or expired.'
                );
            }

            $clanStmt=$this->pdo->prepare(
                'SELECT c.clan_id, c.max_members,
                        (SELECT COUNT(*)
                         FROM mucho_clan_members m
                         WHERE m.clan_id=c.clan_id) AS member_count
                 FROM mucho_clans c
                 WHERE c.clan_id=:clan_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $clanStmt->execute(['clan_id'=>$clanId]);
            $clan=$clanStmt->fetch(PDO::FETCH_ASSOC);

            if (!$clan || (int)$clan['member_count'] >= (int)$clan['max_members']) {
                throw new RuntimeException('Clan is full or unavailable.');
            }

            $inviteStmt=$this->pdo->prepare(
                'SELECT i.invite_id, i.clan_id, i.account_id
                 FROM mucho_clan_invites i
                 WHERE i.invite_id=:invite_id
                   AND i.account_id=:account_id
                   AND i.clan_id=:clan_id
                   AND i.expires_at>UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE'
            );
            $inviteStmt->execute([
                'invite_id'=>$inviteId,
                'account_id'=>$accountId,
                'clan_id'=>$clanId,
            ]);
            $invite=$inviteStmt->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                throw new RuntimeException(
                    'Clan invitation not found or expired.'
                );
            }

            if ($this->isBanned($clanId,$accountId)) {
                throw new RuntimeException('You are banned from this clan.');
            }

            $membership=$this->pdo->prepare(
                'SELECT 1 FROM mucho_clan_members
                 WHERE account_id=:account_id
                 LIMIT 1'
            );
            $membership->execute(['account_id'=>$accountId]);

            if ($membership->fetchColumn() !== false) {
                throw new RuntimeException('Account is already in a clan.');
            }

            $quests=new ClanQuestRepository($this->pdo);
            $quests->settle($clanId);

            $insert=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'member')"
            );
            $insert->execute([
                'clan_id'=>$clanId,
                'account_id'=>$accountId,
            ]);

            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE invite_id=:invite_id AND account_id=:account_id'
            );
            $delete->execute([
                'invite_id'=>$inviteId,
                'account_id'=>$accountId,
            ]);

            $quests->enroll($clanId,$accountId);
            $quests->event($clanId,$quests->player($accountId).' joined the clan.');
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function updateSettings(
        int $clanId,
        int $ownerAccountId,
        string $name,
        string $tag,
        string $description,
        bool $isOpen,
        int $maxMembers
    ): array {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan) {
                throw new RuntimeException('Clan not found.');
            }

            if ((int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            if ((int)$clan['member_count'] > $maxMembers) {
                throw new RuntimeException('Maximum members cannot be below current membership.');
            }

            $stmt=$this->pdo->prepare(
                'UPDATE mucho_clans
                 SET name=:name,
                     tag=:tag,
                     description=:description,
                     is_open=:is_open,
                     max_members=:max_members
                 WHERE clan_id=:clan_id'
            );
            $stmt->execute([
                'name'=>$name,
                'tag'=>$tag,
                'description'=>$description,
                'is_open'=>$isOpen ? 1 : 0,
                'max_members'=>$maxMembers,
                'clan_id'=>$clanId,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            $quests->event($clanId,($this->activityActor ?? $quests->player($ownerAccountId)).' updated clan settings.');

            $this->pdo->commit();

            return $this->getById($clanId)
                ?? throw new RuntimeException('Clan not found.');
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function transferOwnership(
        int $clanId,
        int $ownerAccountId,
        int $targetAccountId
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan || (int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            if ($targetAccountId === $ownerAccountId) {
                throw new RuntimeException('Target is already the clan owner.');
            }

            $target=$this->member($clanId,$targetAccountId);

            if (!$target) {
                throw new RuntimeException('Target is not a member of the clan.');
            }

            $promote=$this->pdo->prepare(
                "UPDATE mucho_clan_members
                 SET role='owner'
                 WHERE clan_id=:clan_id AND account_id=:account_id"
            );
            $promote->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $demote=$this->pdo->prepare(
                "UPDATE mucho_clan_members
                 SET role='officer'
                 WHERE clan_id=:clan_id AND account_id=:account_id"
            );
            $demote->execute([
                'clan_id'=>$clanId,
                'account_id'=>$ownerAccountId,
            ]);

            $owner=$this->pdo->prepare(
                'UPDATE mucho_clans
                 SET owner_account_id=:account_id
                 WHERE clan_id=:clan_id'
            );
            $owner->execute([
                'account_id'=>$targetAccountId,
                'clan_id'=>$clanId,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            $quests->event($clanId,($this->activityActor ?? $quests->player($ownerAccountId)).' transferred ownership to '.$quests->player($targetAccountId).'.');

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function disband(int $clanId, int $ownerAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan || (int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            $stmt=$this->pdo->prepare(
                'DELETE FROM mucho_clans WHERE clan_id=:clan_id'
            );
            $stmt->execute(['clan_id'=>$clanId]);

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function revokeInvite(int $inviteId, int $actorAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $lookup=$this->pdo->prepare(
                'SELECT clan_id
                 FROM mucho_clan_invites
                 WHERE invite_id=:invite_id
                 LIMIT 1'
            );
            $lookup->execute(['invite_id'=>$inviteId]);
            $clanId=(int)($lookup->fetchColumn() ?: 0);

            if ($clanId<=0) {
                throw new RuntimeException('Clan invitation not found.');
            }

            $clan=$this->lockedClan($clanId);

            if (!$clan) {
                throw new RuntimeException('Clan not found.');
            }

            $actor=$this->member($clanId,$actorAccountId);

            if (!$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE invite_id=:invite_id AND clan_id=:clan_id'
            );
            $delete->execute([
                'invite_id'=>$inviteId,
                'clan_id'=>$clanId,
            ]);

            $this->pdo->commit();
            return $delete->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function kick(int $clanId, int $actorAccountId, int $targetAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$actorAccountId);
            $target=$this->member($clanId,$targetAccountId);

            if (!$clan || !$actor || !$target || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            if ((string)$target['role']==='owner') {
                throw new RuntimeException('The clan owner cannot be kicked.');
            }

            if ((string)$actor['role']==='officer' && (string)$target['role']!=='member') {
                throw new RuntimeException('Officers cannot remove other officers.');
            }

            (new ClanQuestRepository($this->pdo))->settle($clanId);
            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_members
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $delete->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            $quests->event($clanId,($this->activityActor ?? $quests->player($actorAccountId)).' kicked '.$quests->player($targetAccountId).'.');

            $this->pdo->commit();
            return $delete->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function setRole(
        int $clanId,
        int $ownerAccountId,
        int $targetAccountId,
        string $role
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan || (int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            if (!in_array($role,['officer','member'],true)) {
                throw new RuntimeException('Invalid clan role.');
            }

            $target=$this->member($clanId,$targetAccountId);

            if (!$target || (string)$target['role']==='owner') {
                throw new RuntimeException('Target is not eligible for role changes.');
            }

            $stmt=$this->pdo->prepare(
                'UPDATE mucho_clan_members
                 SET role=:role
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $stmt->execute([
                'role'=>$role,
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            if ($stmt->rowCount()>0) $quests->event($clanId,($this->activityActor ?? $quests->player($ownerAccountId)).' changed '.$quests->player($targetAccountId).' to '.$role.'.');

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function ban(
        int $clanId,
        int $actorAccountId,
        int $targetAccountId,
        string $reason=''
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$actorAccountId);

            if (!$clan || !$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            if ($targetAccountId<=0 || $targetAccountId===$actorAccountId) {
                throw new RuntimeException('Invalid clan ban target.');
            }

            $account=$this->pdo->prepare(
                'SELECT 1 FROM accounts
                 WHERE account_id=:account_id
                 LIMIT 1'
            );
            $account->execute(['account_id'=>$targetAccountId]);

            if ($account->fetchColumn() === false) {
                throw new RuntimeException('Target account not found.');
            }

            $target=$this->member($clanId,$targetAccountId);

            if ($target) {
                if ((string)$target['role']==='owner') {
                    throw new RuntimeException('The clan owner cannot be banned.');
                }

                if (
                    (string)$actor['role']==='officer' &&
                    (string)$target['role']!=='member'
                ) {
                    throw new RuntimeException('Officers cannot ban other officers.');
                }

                (new ClanQuestRepository($this->pdo))->settle($clanId);
                $delete=$this->pdo->prepare(
                    'DELETE FROM mucho_clan_members
                     WHERE clan_id=:clan_id AND account_id=:account_id'
                );
                $delete->execute([
                    'clan_id'=>$clanId,
                    'account_id'=>$targetAccountId,
                ]);
            }

            $invite=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $invite->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $ban=$this->pdo->prepare(
                "INSERT INTO mucho_clan_bans
                    (clan_id, account_id, banned_by_account_id, reason)
                 VALUES
                    (:clan_id, :account_id, :banned_by, :reason)
                 ON DUPLICATE KEY UPDATE
                    banned_by_account_id=VALUES(banned_by_account_id),
                    reason=VALUES(reason),
                    created_at=CURRENT_TIMESTAMP,
                    expires_at=NULL"
            );
            $ban->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
                'banned_by'=>$actorAccountId,
                'reason'=>$reason,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            $quests->event($clanId,($this->activityActor ?? $quests->player($actorAccountId)).' banned '.$quests->player($targetAccountId).'.');

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function unban(int $clanId, int $actorAccountId, int $targetAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$actorAccountId);

            if (!$clan || !$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            $stmt=$this->pdo->prepare(
                'DELETE FROM mucho_clan_bans
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $stmt->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $quests=new ClanQuestRepository($this->pdo);
            if ($stmt->rowCount()>0) $quests->event($clanId,($this->activityActor ?? $quests->player($actorAccountId)).' unbanned '.$quests->player($targetAccountId).'.');

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function bans(int $clanId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT b.ban_id, b.account_id, b.banned_by_account_id,
                    b.reason, b.created_at, b.expires_at,
                    a.username,
                    ab.username AS banned_by_username
             FROM mucho_clan_bans b
             INNER JOIN accounts a ON a.account_id=b.account_id
             INNER JOIN accounts ab ON ab.account_id=b.banned_by_account_id
             WHERE b.clan_id=:clan_id
               AND (b.expires_at IS NULL OR b.expires_at>UTC_TIMESTAMP())
             ORDER BY b.created_at DESC'
        );
        $stmt->execute(['clan_id'=>$clanId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isBanned(int $clanId, int $accountId): bool
    {
        $stmt=$this->pdo->prepare(
            'SELECT 1
             FROM mucho_clan_bans
             WHERE clan_id=:clan_id
               AND account_id=:account_id
               AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())
             LIMIT 1'
        );
        $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function lockedClan(int $clanId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*)
                     FROM mucho_clan_members m
                     WHERE m.clan_id=c.clan_id) AS member_count
             FROM mucho_clans c
             WHERE c.clan_id=:clan_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['clan_id'=>$clanId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function removeMember(int $clanId, int $accountId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $clan=$this->lockedClan($clanId);
            $member=$this->member($clanId,$accountId);
            if (!$clan || !$member) {
                $this->pdo->commit();
                return false;
            }
            // Recheck after the clan lock: ownership may have changed since
            // the service's initial membership read.
            if ((string)$member['role']==='owner') {
                throw new RuntimeException('Clan owner cannot leave the clan.');
            }
            (new ClanQuestRepository($this->pdo))->settle($clanId);
            $stmt=$this->pdo->prepare(
                'DELETE FROM mucho_clan_members
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $stmt->execute(['clan_id'=>$clanId,'account_id'=>$accountId]);
            $quests=new ClanQuestRepository($this->pdo);
            $quests->event($clanId,$quests->player($accountId).' left the clan.');

            $this->pdo->commit();
            return $stmt->rowCount()>0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }


    public function invite(int $clanId, int $accountId, int $invitedBy): bool
    {
        $this->pdo->beginTransaction();
        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$invitedBy);
            if (!$clan || !$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }
            if ($accountId<=0 || $accountId===$invitedBy) throw new RuntimeException('Invalid invite target.');
            $account=$this->pdo->prepare('SELECT 1 FROM accounts WHERE account_id=:account_id LIMIT 1');
            $account->execute(['account_id'=>$accountId]);
            if ($account->fetchColumn()===false) throw new RuntimeException('Target account not found.');
            if ($this->getForAccount($accountId)!==null) throw new RuntimeException('Target account is already in a clan.');
            if ((int)$clan['member_count']>=(int)$clan['max_members']) throw new RuntimeException('Clan is full.');
            if ($this->isBanned($clanId,$accountId)) throw new RuntimeException('Target account is banned from this clan.');
            $stmt=$this->pdo->prepare(
            "INSERT INTO mucho_clan_invites
                (clan_id, account_id, invited_by_account_id, expires_at)
             VALUES
                (:clan_id, :account_id, :invited_by, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))
             ON DUPLICATE KEY UPDATE
                invited_by_account_id=VALUES(invited_by_account_id),
                created_at=CURRENT_TIMESTAMP,
                expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );

            $result=$stmt->execute([
                'clan_id'=>$clanId,
                'account_id'=>$accountId,
                'invited_by'=>$invitedBy,
            ]);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getInvite(int $inviteId, int $accountId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT i.*, c.name, c.tag
             FROM mucho_clan_invites i
             INNER JOIN mucho_clans c ON c.clan_id=i.clan_id
             WHERE i.invite_id=:invite_id
               AND i.account_id=:account_id
               AND i.expires_at>UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([
            'invite_id'=>$inviteId,
            'account_id'=>$accountId,
        ]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function deleteInvite(int $inviteId, int $accountId): bool
    {
        $stmt=$this->pdo->prepare(
            'DELETE FROM mucho_clan_invites
             WHERE invite_id=:invite_id AND account_id=:account_id'
        );
        $stmt->execute([
            'invite_id'=>$inviteId,
            'account_id'=>$accountId,
        ]);

        return $stmt->rowCount()>0;
    }

    public function sentInvitations(int $clanId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT i.invite_id, i.account_id, i.created_at, i.expires_at,
                    a.username, sender.username AS invited_by_username
             FROM mucho_clan_invites i
             INNER JOIN accounts a ON a.account_id=i.account_id
             INNER JOIN accounts sender ON sender.account_id=i.invited_by_account_id
             WHERE i.clan_id=:clan_id AND i.expires_at>UTC_TIMESTAMP()
             ORDER BY i.created_at DESC, i.invite_id DESC'
        );
        $stmt->execute(['clan_id'=>$clanId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function invitations(int $accountId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT i.invite_id, i.clan_id, i.created_at, i.expires_at,
                    c.name, c.tag, a.username AS invited_by_username
             FROM mucho_clan_invites i
             INNER JOIN mucho_clans c ON c.clan_id=i.clan_id
             INNER JOIN accounts a ON a.account_id=i.invited_by_account_id
             WHERE i.account_id=:account_id
               AND i.expires_at>UTC_TIMESTAMP()
             ORDER BY i.created_at DESC'
        );
        $stmt->execute(['account_id'=>$accountId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
