<?php

declare(strict_types=1);

namespace MuchoCore\Appearance;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;

final readonly class ProfileMediaService
{
    public function __construct(private PDO $pdo,private AccountAuthenticator $auth) {}

    public function profile(int $accountId): array
    {
        if ($accountId<=0 || $accountId>2147483647) throw new RuntimeException('Invalid account ID.');
        $player=(new AppearanceService($this->pdo,$this->auth))->batch((string)$accountId)[0] ?? null;
        if (!$player) throw new RuntimeException('Profile not found.');
        $query=$this->pdo->prepare('SELECT stars,moons,diamonds,demons,secret_coins AS coins,user_coins,creator_points
            FROM profiles WHERE account_id=? LIMIT 1');
        $query->execute([$accountId]);
        $stats=$query->fetch(PDO::FETCH_ASSOC) ?: [];
        $player['stats']=array_map('intval',$stats);
        $query=$this->pdo->prepare('SELECT kind,version,OCTET_LENGTH(image_data) AS bytes
            FROM mucho_profile_media WHERE account_id=?');
        $query->execute([$accountId]);
        $player['media']=['avatar'=>null,'banner'=>null];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$width,$height]=ProfileImage::dimensions($row['kind']);
            $player['media'][$row['kind']]=['version'=>$row['version'],'bytes'=>(int)$row['bytes'],'width'=>$width,'height'=>$height];
        }
        return $player;
    }

    public function upload(int $accountId,string $credential,string $kind,string $encoded,int $x,int $y,int $zoom,int $rotation): array
    {
        // Authentication runs before expensive image parsing/decoding.
        $this->auth->authenticate($accountId,$credential);
        $jpeg=ProfileImage::render($kind,$encoded,$x,$y,$zoom,$rotation);
        $query=$this->pdo->prepare('INSERT INTO mucho_profile_media (account_id,kind,version,image_data)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),image_data=VALUES(image_data)');
        $query->execute([$accountId,$kind,hash('sha256',$jpeg),$jpeg]);
        return $this->profile($accountId);
    }

    public function remove(int $accountId,string $credential,string $kind): array
    {
        $this->auth->authenticate($accountId,$credential);
        ProfileImage::dimensions($kind);
        $query=$this->pdo->prepare('DELETE FROM mucho_profile_media WHERE account_id=? AND kind=?');
        $query->execute([$accountId,$kind]);
        return $this->profile($accountId);
    }

    public function image(int $accountId,string $kind,string $version): ?string
    {
        ProfileImage::dimensions($kind);
        if ($accountId<=0 || $accountId>2147483647 || preg_match('/^[a-f0-9]{64}$/D',$version)!==1) {
            throw new RuntimeException('Invalid profile image.');
        }
        $query=$this->pdo->prepare('SELECT m.image_data FROM mucho_profile_media m
            INNER JOIN accounts a ON a.account_id=m.account_id
            WHERE m.account_id=? AND m.kind=? AND m.version=? AND a.is_active=1 AND a.is_banned=0');
        $query->execute([$accountId,$kind,$version]);
        $bytes=$query->fetchColumn();
        return is_string($bytes) ? $bytes : null;
    }
}
