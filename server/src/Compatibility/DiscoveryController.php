<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Protocol\GdUserEncoder;
use MuchoCore\User\UserRepository;
use PDO;

/*
 * MuchoCore GD Discovery Compatibility
 *
 * Copyright (C) 2026 IZK
 */
final readonly class DiscoveryController
{
    private const HASH_SALT =
        'xI25fpAapCQg';


    public function __construct(
        private PDO $db
    ) {
    }


    /*
     * getGJCreators / getGJCreators19
     */
    public function creators(
        Request $request
    ): Response {

        $rows=
            (new UserRepository($this->db))
                ->leaderboardCreators(100);

        if(!$rows){
            return Response::text('-1');
        }

        return Response::text(
            (new GdUserEncoder())
                ->leaderboard($rows)
        );
    }


    /*
     * getGJDailyLevel
     *
     * Current clients:
     * type=0 daily
     * type=1 weekly
     *
     * Older clients:
     * weekly=0/1
     *
     * Event level type=2 will be implemented
     * separately.
     */
    public function daily(
        Request $request
    ): Response {

        if(isset($_POST['type'])){

            $type=(int)$_POST['type'];

        }else{

            $type=
                (int)($_POST['weekly'] ?? 0)===1
                    ? 1
                    : 0;
        }


        if(!in_array($type,[0,1,2],true)){
            return Response::text('-1');
        }

        $kind=match($type){
            1 => 'weekly',
            2 => 'event',
            default => 'daily',
        };


        $q=$this->db->prepare("
            SELECT
                id,
                level_id,

                GREATEST(
                    0,
                    TIMESTAMPDIFF(
                        SECOND,
                        UTC_TIMESTAMP(),
                        ends_at
                    )
                ) AS remaining

            FROM mucho_daily_rotation

            WHERE kind=:kind
              AND enabled=1
              AND starts_at<=UTC_TIMESTAMP()
              AND ends_at>UTC_TIMESTAMP()

            ORDER BY
                starts_at DESC,
                id DESC

            LIMIT 1
        ");


        $q->execute([
            'kind'=>$kind
        ]);


        $row=$q->fetch(
            PDO::FETCH_ASSOC
        );


        if(!$row){
            return Response::text('-1');
        }


        /*
         * GD response:
         * daily index | seconds remaining
         */
        $index=(int)$row['id'];

        if($type===1){
            $index+=100001;
        }elseif($type===2){
            $index+=200001;
        }

        return Response::text(
            $index.
            '|'.
            max(
                0,
                (int)$row['remaining']
            )
        );
    }


    /*
     * getGJGauntlets / getGJGauntlets21
     */
    public function gauntlets(
        Request $request
    ): Response {

        $rows=$this->db
            ->query("
                SELECT
                    id,
                    level1,
                    level2,
                    level3,
                    level4,
                    level5

                FROM mucho_gauntlets

                WHERE enabled=1
                  AND level1>0
                  AND level2>0
                  AND level3>0
                  AND level4>0
                  AND level5>0

                ORDER BY
                    sort_order ASC,
                    id ASC
            ")
            ->fetchAll(
                PDO::FETCH_ASSOC
            );


        if(!$rows){
            return Response::text('-1');
        }


        $parts=[];
        $hashInput='';


        foreach($rows as $g){

            $levels=implode(
                ',',
                [
                    (int)$g['level1'],
                    (int)$g['level2'],
                    (int)$g['level3'],
                    (int)$g['level4'],
                    (int)$g['level5'],
                ]
            );


            $parts[]=
                '1:'.
                (int)$g['id'].
                ':3:'.
                $levels;


            /*
             * GD hash:
             * gauntlet ID + comma-separated level IDs
             */
            $hashInput.=
                (int)$g['id'].
                $levels;
        }


        $hash=sha1(
            $hashInput.
            self::HASH_SALT
        );


        return Response::text(
            implode('|',$parts).
            '#'.
            $hash
        );
    }


    /*
     * getGJMapPacks / 20 / 21
     */
    public function mapPacks(
        Request $request
    ): Response {

        $page=min(
            1000,
            max(
                0,
                (int)($_POST['page'] ?? 0)
            )
        );

        $limit=10;
        $offset=$page*$limit;


        $total=(int)$this->db
            ->query("
                SELECT COUNT(*)
                FROM mucho_map_packs
                WHERE enabled=1
            ")
            ->fetchColumn();


        if($total===0){
            return Response::text('-1');
        }


        $q=$this->db->prepare("
            SELECT
                id,
                name,
                levels,
                stars,
                coins,
                difficulty,
                color1,
                color2

            FROM mucho_map_packs

            WHERE enabled=1

            ORDER BY
                sort_order ASC,
                id ASC

            LIMIT :limit
            OFFSET :offset
        ");


        $q->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $q->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $q->execute();


        $rows=$q->fetchAll(
            PDO::FETCH_ASSOC
        );


        if(!$rows){
            return Response::text('-1');
        }


        $parts=[];
        $hashInput='';


        foreach($rows as $m){

            $id=(int)$m['id'];
            $stars=(int)$m['stars'];
            $coins=(int)$m['coins'];


            $name=str_replace(
                [
                    ':',
                    '|',
                    '#',
                    '~'
                ],
                '',
                (string)$m['name']
            );


            $parts[]=
                '1:'.$id.
                ':2:'.$name.
                ':3:'.
                (string)$m['levels'].
                ':4:'.$stars.
                ':5:'.$coins.
                ':6:'.
                (int)$m['difficulty'].
                ':7:'.
                (string)$m['color1'].
                ':8:'.
                (string)$m['color2'];


            /*
             * Map-pack hash:
             * first digit ID
             * last digit ID
             * stars
             * coins
             */

            $idString=(string)$id;

            $hashInput.=
                $idString[0].
                $idString[
                    strlen($idString)-1
                ].
                $stars.
                $coins;
        }


        $hash=sha1(
            $hashInput.
            self::HASH_SALT
        );


        return Response::text(
            implode('|',$parts).
            '#'.
            $total.
            ':'.
            $offset.
            ':'.
            $limit.
            '#'.
            $hash
        );
    }
}
