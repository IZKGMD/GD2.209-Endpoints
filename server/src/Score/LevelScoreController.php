<?php

declare(strict_types=1);

namespace MuchoCore\Score;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\User\UserRepository;
use PDO;
use RuntimeException;
use Throwable;

/*
 * MuchoCore Geometry Dash Level Scores
 *
 * Copyright (C) 2026 IZK
 */
final readonly class LevelScoreController
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth
    ) {
    }


    public function regular(
        Request $request
    ): Response {

        $data=!empty($request->post)
            ? $request->post
            : $_POST;


        $accountId=
            $this->strictInt($data['accountID'] ?? null);

        $gjp = $request->gdCredential();

        $levelId=
            $this->strictInt($data['levelID'] ?? null);

        $percent=
            $this->strictInt($data['percent'] ?? null);

        $type=
            array_key_exists('type',$data)
                ? $this->strictInt($data['type'])
                : 1;


        if(
            $accountId<=0 ||
            $levelId<=0 ||
            $gjp==='' ||
            $percent<0 ||
            $percent>100 ||
            !in_array($type,[0,1,2],true)
        ){
            return Response::text('-1');
        }


        try {

            $this->auth->authenticate(
                $accountId,
                $gjp
            );


            if(!$this->levelExists($levelId)){
                return Response::text('-1');
            }


            $attempts=$this->decodedNumber($data,'s1',8354,0,1000000);
            if($attempts===null){
                return Response::text('-1');
            }

            $clicks=$this->decodedNumber($data,'s2',3991,0,10000000);
            if($clicks===null){
                return Response::text('-1');
            }

            $playTime=$this->decodedNumber($data,'s3',4085,0,86400);
            if($playTime===null){
                return Response::text('-1');
            }

            $coins=$this->decodedNumber($data,'s9',5819,0,3);
            if($coins===null){
                return Response::text('-1');
            }

            $dailyId=$this->strictInt(
                $data['s10'] ?? 0
            );

            if($dailyId<0){
                return Response::text('-1');
            }

            $isDaily=
                $dailyId>0
                    ? 1
                    : 0;


            $progresses=$this->decodeProgresses($data['s6'] ?? '');
            if($progresses===null){
                return Response::text('-1');
            }

            if(strlen($progresses)>100000){
                return Response::text('-1');
            }


            $scoreId=$this->saveScore(
                accountId:$accountId,
                levelId:$levelId,
                percent:$percent,
                coins:$coins,
                attempts:$attempts,
                clicks:$clicks,
                playTime:$playTime,
                progresses:$progresses,
                dailyId:$dailyId,
                isDaily:$isDaily
            );

            if($scoreId!==null){
                ScoreIntegrity::record(
                    $this->db,
                    $isDaily ? 'regular_daily' : 'regular',
                    $scoreId,
                    $accountId,
                    $levelId,
                    ScoreIntegrity::evaluateRegular(
                        $percent,
                        $attempts,
                        $clicks,
                        $playTime,
                        $progresses
                    )
                );
            }


            return Response::text(
                $this->leaderboard(
                    accountId:$accountId,
                    levelId:$levelId,
                    isDaily:$isDaily,
                    type:$type
                )
            );

        } catch(Throwable) {

            return Response::text('-1');
        }
    }


    private function levelExists(
        int $levelId
    ): bool {

        $q=$this->db->prepare("
            SELECT 1

            FROM levels

            WHERE level_id=:level
              AND is_deleted=0

            LIMIT 1
        ");

        $q->execute([
            'level'=>$levelId
        ]);

        return (bool)$q->fetchColumn();
    }


    private function saveScore(
        int $accountId,
        int $levelId,
        int $percent,
        int $coins,
        int $attempts,
        int $clicks,
        int $playTime,
        string $progresses,
        int $dailyId,
        int $isDaily
    ): ?int {

        $now=time();

        /*
         * The logical score key is unique in the database. Use one atomic
         * upsert so two first-time submissions cannot both observe "no row"
         * and race into a duplicate-key failure. Worse progress is a no-op.
         */
        $q=$this->db->prepare("
            INSERT INTO mucho_level_scores
            (
                account_id,
                level_id,
                is_daily,
                daily_id,
                percent,
                coins,
                attempts,
                clicks,
                play_time,
                progresses,
                created_at,
                updated_at
            )
            VALUES
            (
                :account,
                :level,
                :is_daily,
                :daily_id,
                :percent,
                :coins,
                :attempts,
                :clicks,
                :play_time,
                :progresses,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                score_id=LAST_INSERT_ID(score_id),
                daily_id=IF(VALUES(percent)>=percent,VALUES(daily_id),daily_id),
                coins=IF(VALUES(percent)>=percent,VALUES(coins),coins),
                attempts=IF(VALUES(percent)>=percent,VALUES(attempts),attempts),
                clicks=IF(VALUES(percent)>=percent,VALUES(clicks),clicks),
                play_time=IF(VALUES(percent)>=percent,VALUES(play_time),play_time),
                progresses=IF(VALUES(percent)>=percent,VALUES(progresses),progresses),
                updated_at=IF(VALUES(percent)>=percent,VALUES(updated_at),updated_at),
                percent=GREATEST(percent,VALUES(percent))
        ");

        $q->execute([
            'account'=>$accountId,
            'level'=>$levelId,
            'is_daily'=>$isDaily,
            'daily_id'=>$dailyId,
            'percent'=>$percent,
            'coins'=>$coins,
            'attempts'=>$attempts,
            'clicks'=>$clicks,
            'play_time'=>$playTime,
            'progresses'=>$progresses,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);

        if($q->rowCount()===0){
            return null;
        }

        $scoreId=(int)$this->db->lastInsertId();
        if($scoreId<=0){
            throw new RuntimeException('Score upsert failed.');
        }

        return $scoreId;
    }

    private function leaderboard(
        int $accountId,
        int $levelId,
        int $isDaily,
        int $type
    ): string {

        $params=[
            'level'=>$levelId,
            'daily'=>$isDaily,
        ];


        $where="
            level_id=:level
            AND is_daily=:daily
            AND EXISTS (
                SELECT 1
                FROM accounts a
                WHERE a.account_id=mucho_level_scores.account_id
                  AND a.is_active=1
                  AND a.is_banned=0
            )
        ";

        if (ScoreIntegrity::quarantineEnabled()) {
            $where .= "
                AND NOT EXISTS (
                    SELECT 1
                    FROM mucho_score_integrity_events si
                    WHERE si.score_type=:integrity_type
                      AND si.score_id=mucho_level_scores.score_id
                      AND si.status IN ('suspicious','quarantined')
                      AND si.risk_score>=70
                )
            ";
            $params['integrity_type']=
                $isDaily ? 'regular_daily' : 'regular';
        }


        /*
         * type 0 = friends
         * type 1 = global
         * type 2 = last 7 days
         */
        if($type===0){

            $friends=
                $this->friendAccountIds(
                    $accountId
                );

            $holders=[];

            foreach(
                array_values($friends)
                as $i=>$id
            ){
                $key='friend_'.$i;
                $holders[]=':'.$key;
                $params[$key]=$id;
            }

            if(!$holders){
                return '';
            }

            $where.="
                AND account_id IN (".
                implode(',',$holders).
                ")
            ";

        }elseif($type===2){

            $where.="
                AND updated_at>:recent
            ";

            $params['recent']=
                time()-604800;
        }


        $sql="
            SELECT
                account_id,
                percent,
                coins,
                updated_at

            FROM mucho_level_scores

            WHERE ".$where."

            ORDER BY
                percent DESC,
                updated_at ASC

            LIMIT 100
        ";


        $q=$this->db->prepare($sql);
        $q->execute($params);

        $rows=$q->fetchAll(
            PDO::FETCH_ASSOC
        );


        if(!$rows){
            return '';
        }


        $users=
            new UserRepository(
                $this->db
            );

        $out='';


        foreach($rows as $score){

            $profile=
                $users->getProfileByTarget(
                    (int)$score['account_id']
                );


            if(!$profile){
                continue;
            }


            if(
                (int)$this->value(
                    $profile,
                    [
                        'is_banned',
                        'isBanned'
                    ],
                    0
                )!==0
            ){
                continue;
            }


            $username=
                (string)$this->value(
                    $profile,
                    [
                        'username',
                        'user_name',
                        'userName'
                    ],
                    ''
                );


            if($username===''){
                continue;
            }

            $username=
                \MuchoCore\Protocol\ProtocolText::username(
                    $username
                );


            $userId=
                (int)$this->value(
                    $profile,
                    [
                        'user_id',
                        'userID',
                        'id'
                    ],
                    (int)$score['account_id']
                );


            if($userId<=0){
                $userId=
                    (int)$score['account_id'];
            }


            $icon=
                (int)$this->value(
                    $profile,
                    [
                        'active_icon',
                        'icon',
                        'cube'
                    ],
                    1
                );


            $color1=
                (int)$this->value(
                    $profile,
                    ['color1'],
                    0
                );


            $color2=
                (int)$this->value(
                    $profile,
                    ['color2'],
                    3
                );


            $color3=
                (int)$this->value(
                    $profile,
                    ['color3'],
                    0
                );


            $iconType=
                (int)$this->value(
                    $profile,
                    [
                        'icon_type',
                        'iconType'
                    ],
                    0
                );


            $special=
                (int)$this->value(
                    $profile,
                    [
                        'special',
                        'acc_special'
                    ],
                    0
                );


            $percent=
                (int)$score['percent'];


            $place=
                $percent===100
                    ? 1
                    : (
                        $percent>75
                            ? 2
                            : 3
                    );


            $date=gmdate(
                'd/m/Y G.i',
                (int)$score['updated_at']
            );


            $out.=
                '1:'.$username.
                ':2:'.$userId.
                ':9:'.$icon.
                ':10:'.$color1.
                ':11:'.$color2.
                ':51:'.$color3.
                ':14:'.$iconType.
                ':15:'.$special.
                ':16:'.
                (int)$score['account_id'].
                ':3:'.$percent.
                ':6:'.$place.
                ':13:'.
                (int)$score['coins'].
                ':42:'.$date.
                '|';
        }


        return rtrim($out, '|');
    }


    private function friendAccountIds(
        int $accountId
    ): array {

        $ids=[
            $accountId=>$accountId
        ];


        $pairs=[
            ['account_id','friend_account_id'],
            ['account_id','friend_id'],
            ['account_id_1','account_id_2'],
            ['account1_id','account2_id'],
            ['user_id','friend_id'],
            ['user_id_1','user_id_2'],
            ['user1','user2'],
            ['requester_id','addressee_id'],
        ];


        foreach(
            ['friends','friendships']
            as $table
        ){

            try {

                $cols=$this->db
                    ->query(
                        "SHOW COLUMNS FROM `".
                        $table.
                        "`"
                    )
                    ->fetchAll(
                        PDO::FETCH_ASSOC
                    );

            }catch(Throwable){

                continue;
            }


            if(!$cols){
                continue;
            }


            $names=[];

            foreach($cols as $col){
                $names[]=
                    (string)$col['Field'];
            }


            foreach($pairs as [$left,$right]){

                if(
                    !in_array(
                        $left,
                        $names,
                        true
                    ) ||
                    !in_array(
                        $right,
                        $names,
                        true
                    )
                ){
                    continue;
                }


                $q=$this->db->prepare(
                    "SELECT
                        `".$left."` AS a,
                        `".$right."` AS b

                     FROM `".$table."`

                     WHERE `".$left."`=:id_left
                        OR `".$right."`=:id_right"
                );


                $q->execute([
                    'id_left'=>$accountId,
                    'id_right'=>$accountId,
                ]);


                foreach(
                    $q->fetchAll(
                        PDO::FETCH_ASSOC
                    )
                    as $row
                ){

                    $a=(int)$row['a'];
                    $b=(int)$row['b'];

                    if($a===$accountId && $b>0){
                        $ids[$b]=$b;
                    }

                    if($b===$accountId && $a>0){
                        $ids[$a]=$a;
                    }
                }


                return array_values($ids);
            }
        }


        /*
         * Если схема друзей неизвестна —
         * friends leaderboard безопасно
         * покажет хотя бы самого игрока.
         */
        return array_values($ids);
    }


    private function decodedNumber(
        array $data,
        string $key,
        int $offset,
        int $min,
        int $max
    ): ?int {
        if(!array_key_exists($key,$data) || $data[$key]===''){
            return 0;
        }

        $raw=$data[$key];

        if(
            !(is_int($raw) || is_string($raw)) ||
            preg_match('/^-?\\d+$/D',(string)$raw)!==1
        ){
            return null;
        }

        $value=(int)$raw-$offset;

        if($value<$min || $value>$max){
            return null;
        }

        return $value;
    }

    private function strictInt(mixed $value): int
    {
        if(is_int($value)){
            return $value;
        }

        if(is_string($value) && preg_match('/^-?\\d+$/D',$value)===1){
            return (int)$value;
        }

        return 0;
    }


    private function decodeProgresses(
        mixed $value
    ): ?string {

        if(!is_string($value) || $value===''){
            return '';
        }


        $encoded=strtr(
            $value,
            '-_',
            '+/'
        );


        $mod=
            strlen($encoded)%4;

        if($mod!==0){
            $encoded.=
                str_repeat(
                    '=',
                    4-$mod
                );
        }


        $decoded=
            base64_decode(
                $encoded,
                true
            );


        if($decoded===false){
            return null;
        }


        $key='41274';
        $out='';
        $keyLength=strlen($key);


        for(
            $i=0,
            $length=strlen($decoded);
            $i<$length;
            $i++
        ){

            $out.=
                chr(
                    ord($decoded[$i]) ^
                    ord(
                        $key[
                            $i%$keyLength
                        ]
                    )
                );
        }


        return $out;
    }


    private function value(
        array $row,
        array $keys,
        mixed $default
    ): mixed {

        foreach($keys as $key){

            if(
                array_key_exists(
                    $key,
                    $row
                )
            ){
                return $row[$key];
            }
        }

        return $default;
    }
}
