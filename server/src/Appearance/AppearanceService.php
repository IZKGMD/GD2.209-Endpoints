<?php

declare(strict_types=1);

namespace MuchoCore\Appearance;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

final readonly class AppearanceService
{
    public function __construct(private PDO $pdo, private AccountAuthenticator $auth) {}

    private function color(string $value, bool $allowDefault): ?string
    {
        $value=strtoupper(trim($value));
        if ($allowDefault && ($value==='' || $value==='DEFAULT')) return null;
        if (str_starts_with($value,'#')) $value=substr($value,1);
        if (preg_match('/^[0-9A-F]{6}$/D',$value)!==1) {
            throw new RuntimeException('Use a six-digit HEX color, for example #66CCFF.');
        }
        return $value;
    }

    public function batch(string $ids): array
    {
        $parts=explode(',',$ids);
        if (count($parts)>50) throw new RuntimeException('Request at most 50 accounts at once.');
        $accounts=[];
        foreach ($parts as $part) {
            $part=trim($part);
            if (preg_match('/^[1-9][0-9]{0,9}$/D',$part)!==1 || (int)$part>2147483647) {
                throw new RuntimeException('Invalid account ID.');
            }
            $accounts[]=(int)$part;
        }
        return $this->fetch(array_values(array_unique($accounts)));
    }

    public function mine(int $accountId,string $credential): array
    {
        $this->auth->authenticate($accountId,$credential);
        return $this->fetch([$accountId])[0] ?? throw new RuntimeException('Account not found.');
    }

    public function save(int $accountId,string $credential,string $nicknameColor,?string $prefixColor): array
    {
        $this->auth->authenticate($accountId,$credential);
        $nickname=$this->color($nicknameColor,true);
        $prefix=$prefixColor===null ? null : $this->color($prefixColor,false);
        $this->pdo->beginTransaction();
        try {
            if ($prefix!==null) {
                // Lock the same clan row as ownership transfer and disband.
                $clan=$this->pdo->prepare('SELECT c.clan_id,c.owner_account_id
                    FROM mucho_clans c INNER JOIN mucho_clan_members m ON m.clan_id=c.clan_id
                    WHERE m.account_id=? LIMIT 1 FOR UPDATE');
                $clan->execute([$accountId]);
                $row=$clan->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['owner_account_id']!==$accountId) {
                    throw new RuntimeException('Only the clan owner can change its prefix color.');
                }
                $stmt=$this->pdo->prepare('INSERT INTO mucho_clan_appearance (clan_id,prefix_color)
                    VALUES (?,?) ON DUPLICATE KEY UPDATE prefix_color=VALUES(prefix_color)');
                $stmt->execute([(int)$row['clan_id'],$prefix]);
            }
            $stmt=$this->pdo->prepare('INSERT INTO mucho_user_appearance (account_id,nickname_color)
                VALUES (?,?) ON DUPLICATE KEY UPDATE nickname_color=VALUES(nickname_color)');
            $stmt->execute([$accountId,$nickname]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->fetch([$accountId])[0] ?? throw new RuntimeException('Account not found.');
    }

    private function fetch(array $accounts): array
    {
        $stmt=$this->pdo->prepare('SELECT a.account_id,a.username,
            COALESCE(u.nickname_color,\'\') AS nickname_color,
            COALESCE(c.clan_id,0) AS clan_id,COALESCE(c.tag,\'\') AS clan_tag,
            COALESCE(s.prefix_color,\'FFD166\') AS prefix_color,
            CASE WHEN c.owner_account_id=a.account_id THEN 1 ELSE 0 END AS can_edit_prefix
            FROM accounts a
            LEFT JOIN mucho_user_appearance u ON u.account_id=a.account_id
            LEFT JOIN mucho_clan_members m ON m.account_id=a.account_id
            LEFT JOIN mucho_clans c ON c.clan_id=m.clan_id
            LEFT JOIN mucho_clan_appearance s ON s.clan_id=c.clan_id
            WHERE a.account_id IN ('.implode(',',array_fill(0,count($accounts),'?')).')
              AND a.is_active=1 AND a.is_banned=0 ORDER BY a.account_id');
        $stmt->execute($accounts);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['account_id']=(int)$row['account_id'];
            $row['clan_id']=(int)$row['clan_id'];
            $row['can_edit_prefix']=(bool)$row['can_edit_prefix'];
        }
        return $rows;
    }
}
