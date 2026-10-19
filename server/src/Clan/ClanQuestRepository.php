<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use PDO;
use RuntimeException;

// Every write runs inside a transaction holding the clan row lock. The same
// lock is used by membership operations and authenticated score updates.
final readonly class ClanQuestRepository
{
    public function __construct(private PDO $pdo) {}

    public function lockForAccount(int $accountId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT clan_id FROM mucho_clan_members WHERE account_id=?');
        $stmt->execute([$accountId]);
        $id=$stmt->fetchColumn();
        if ($id===false) return null;
        $lock=$this->pdo->prepare('SELECT clan_id FROM mucho_clans WHERE clan_id=? FOR UPDATE');
        $lock->execute([(int)$id]);
        if ($lock->fetchColumn()===false) return null;
        $member=$this->pdo->prepare('SELECT clan_id,role FROM mucho_clan_members WHERE account_id=? AND clan_id=? FOR UPDATE');
        $member->execute([$accountId,(int)$id]);
        return $member->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function event(int $clanId,string $message): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO mucho_clan_events(clan_id,message) VALUES (?,?)');
        $stmt->execute([$clanId,substr($message,0,200)]);
    }

    public function player(int $accountId): string
    {
        $stmt=$this->pdo->prepare('SELECT username FROM accounts WHERE account_id=?');
        $stmt->execute([$accountId]);
        return (string)($stmt->fetchColumn() ?: 'Player');
    }

    // Joining/rejoining resets the baseline to the current score without
    // erasing earned contributions or allowing a score reset to be replayed.
    public function enroll(int $clanId,int $accountId): void
    {
        foreach (['stars','demons'] as $metric) {
            $stmt=$this->pdo->prepare("INSERT INTO mucho_clan_quest_members(quest_id,account_id,last_score)
                SELECT q.quest_id,m.account_id,GREATEST(COALESCE(p.$metric,0),0)
                FROM mucho_clan_quests q
                JOIN mucho_clan_members m ON m.clan_id=q.clan_id AND m.account_id=?
                LEFT JOIN profiles p ON p.account_id=m.account_id
                WHERE q.clan_id=? AND q.metric=? AND q.status='active' AND q.expires_at>UTC_TIMESTAMP()
                ON DUPLICATE KEY UPDATE last_score=GREATEST(last_score,VALUES(last_score))");
            $stmt->execute([$accountId,$clanId,$metric]);
        }
    }

    public function settle(int $clanId): void
    {
        $stmt=$this->pdo->prepare("SELECT * FROM mucho_clan_quests
            WHERE clan_id=? AND status='active' AND expires_at>UTC_TIMESTAMP() ORDER BY quest_id FOR UPDATE");
        $stmt->execute([$clanId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $quest) {
            $metric=$quest['metric'];
            if (!in_array($metric,['stars','demons'],true)) throw new RuntimeException('Invalid quest metric.');
            $scores=$this->pdo->prepare("SELECT s.account_id,s.last_score,GREATEST(COALESCE(p.$metric,0),0) AS score
                FROM mucho_clan_quest_members s
                JOIN mucho_clan_members m ON m.account_id=s.account_id AND m.clan_id=?
                JOIN accounts a ON a.account_id=s.account_id AND a.is_active=1 AND a.is_banned=0
                LEFT JOIN profiles p ON p.account_id=s.account_id WHERE s.quest_id=? FOR UPDATE");
            $scores->execute([$clanId,$quest['quest_id']]);
            $delta=0;
            $update=$this->pdo->prepare('UPDATE mucho_clan_quest_members SET last_score=?,contribution=contribution+? WHERE quest_id=? AND account_id=?');
            foreach ($scores->fetchAll(PDO::FETCH_ASSOC) as $score) {
                $gain=max(0,(int)$score['score']-(int)$score['last_score']);
                if ($gain===0) continue;
                $delta+=$gain;
                $update->execute([(int)$score['score'],$gain,$quest['quest_id'],$score['account_id']]);
            }
            if ($delta===0) continue;
            $progress=(int)$quest['progress']+$delta;
            $completed=$progress>=(int)$quest['target'];
            $save=$this->pdo->prepare("UPDATE mucho_clan_quests SET progress=?,status=?,completed_at=IF(?=1,UTC_TIMESTAMP(),NULL) WHERE quest_id=?");
            $save->execute([$progress,$completed ? 'completed' : 'active',$completed ? 1 : 0,$quest['quest_id']]);
            if ($completed) $this->event($clanId,'Quest completed: '.$quest['target'].' '.$metric);
        }
    }

    public function create(int $clanId,int $actor,string $metric,int $target): array
    {
        $minimum=$metric==='stars' ? 100 : 5;
        $maximum=$metric==='stars' ? 100000 : 1000;
        if (!in_array($metric,['stars','demons'],true) || $target<$minimum || $target>$maximum) {
            throw new RuntimeException('Quest goal: 100-100000 stars or 5-1000 demons.');
        }
        $this->settle($clanId);
        $active=$this->pdo->prepare("SELECT quest_id FROM mucho_clan_quests WHERE clan_id=? AND metric=? AND status='active' AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE");
        $active->execute([$clanId,$metric]);
        if ($active->fetchColumn()!==false) throw new RuntimeException('An active quest for this metric already exists.');
        $insert=$this->pdo->prepare("INSERT INTO mucho_clan_quests(clan_id,metric,target,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))");
        $insert->execute([$clanId,$metric,$target]);
        $questId=(int)$this->pdo->lastInsertId();
        $snapshot=$this->pdo->prepare("INSERT INTO mucho_clan_quest_members(quest_id,account_id,last_score)
            SELECT ?,m.account_id,GREATEST(COALESCE(p.$metric,0),0) FROM mucho_clan_members m
            LEFT JOIN profiles p ON p.account_id=m.account_id WHERE m.clan_id=?");
        $snapshot->execute([$questId,$clanId]);
        $this->event($clanId,$this->player($actor).' started a quest: '.$target.' '.$metric);
        return ['quest_id'=>$questId];
    }

    public function cancel(int $clanId,int $actor,int $questId): array
    {
        $this->settle($clanId);
        $stmt=$this->pdo->prepare("UPDATE mucho_clan_quests SET status='cancelled' WHERE quest_id=? AND clan_id=? AND status='active' AND expires_at>UTC_TIMESTAMP()");
        $stmt->execute([$questId,$clanId]);
        if ($stmt->rowCount()!==1) throw new RuntimeException('Active quest not found.');
        $this->event($clanId,$this->player($actor).' cancelled quest #'.$questId);
        return ['cancelled'=>true];
    }

    public function quests(int $clanId): array
    {
        $this->settle($clanId);
        $stmt=$this->pdo->prepare("SELECT q.*,IF(q.status='active' AND q.expires_at<=UTC_TIMESTAMP(),'expired',q.status) AS state
            FROM mucho_clan_quests q WHERE clan_id=?
            ORDER BY (q.status='active' AND q.expires_at>UTC_TIMESTAMP()) DESC,q.quest_id DESC LIMIT 20 FOR UPDATE");
        $stmt->execute([$clanId]);
        $quests=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($quests as &$quest) {
            foreach (['quest_id','clan_id','target','progress'] as $key) $quest[$key]=(int)$quest[$key];
        }
        return ['quests'=>$quests];
    }

    public function contributions(int $clanId,int $questId,int $offset,int $limit): array
    {
        $this->settle($clanId);
        $quest=$this->pdo->prepare("SELECT q.*,IF(q.status='active' AND q.expires_at<=UTC_TIMESTAMP(),'expired',q.status) AS state
            FROM mucho_clan_quests q WHERE quest_id=? AND clan_id=? FOR UPDATE");
        $quest->execute([$questId,$clanId]);
        $row=$quest->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Quest not found.');
        $stmt=$this->pdo->prepare("SELECT s.account_id,a.username,s.contribution,COALESCE(u.nickname_color,'') AS nickname_color,
                (m.account_id IS NOT NULL) AS is_member
            FROM mucho_clan_quest_members s JOIN accounts a ON a.account_id=s.account_id
            LEFT JOIN mucho_clan_members m ON m.account_id=s.account_id AND m.clan_id=?
            LEFT JOIN mucho_user_appearance u ON u.account_id=s.account_id
            WHERE s.quest_id=? ORDER BY s.contribution DESC,s.account_id ASC LIMIT ".($limit+1).' OFFSET '.$offset.' FOR UPDATE');
        $stmt->execute([$clanId,$questId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$member) {
            $member['account_id']=(int)$member['account_id'];
            $member['contribution']=(int)$member['contribution'];
            $member['is_member']=(bool)$member['is_member'];
        }
        foreach (['quest_id','clan_id','target','progress'] as $key) $row[$key]=(int)$row[$key];
        return ['quest_id'=>$questId,'metric'=>$row['metric'],'quest'=>$row,'members'=>array_slice($rows,0,$limit),'has_more'=>count($rows)>$limit,'offset'=>$offset];
    }

    public function activity(int $clanId,int $offset,int $limit): array
    {
        $stmt=$this->pdo->prepare('SELECT event_id,message,created_at FROM mucho_clan_events WHERE clan_id=? ORDER BY event_id DESC LIMIT '.($limit+1).' OFFSET '.$offset.' FOR UPDATE');
        $stmt->execute([$clanId]);
        $events=$stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['events'=>array_slice($events,0,$limit),'has_more'=>count($events)>$limit,'offset'=>$offset];
    }
}
