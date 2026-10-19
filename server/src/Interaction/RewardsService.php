<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdHash;

final readonly class RewardsService
{
    private const SECRET_REWARD_SECRET = 'Wmfd2893gb7';

    private const SMALL_WAIT = 3600;
    private const BIG_WAIT = 14400;

    private const SMALL_ITEMS = [
        1,2,3,4,5,6,10,11,12,13,14
    ];

    private const BIG_ITEMS = [
        1,2,3,4,5,6,10,11,12,13,14
    ];

    public function __construct(
        private RewardsRepository $repository,
        private AccountAuthenticator $auth
    ) {}

    public function rewards(
        int $accountId,
        string $udid,
        string $chk,
        string $credential,
        int $rewardType
    ): string {
        if (!in_array($rewardType,[0,1,2],true)) {
            return '-1';
        }

        if ($accountId <= 0) {
            if ($rewardType !== 0) {
                return '-1';
            }

            $userId=0;

            $state=[
                'small_last_at'=>0,
                'small_count'=>0,
                'big_last_at'=>0,
                'big_count'=>0
            ];

        } else {
            $this->auth->authenticate($accountId, $credential);

            $account=$this->repository->account($accountId);

            if (!$account) {
                return '-1';
            }

            $userId=(int)$account['user_id'];
            $state=$this->repository->state($accountId);
        }

        $now=time();

        $smallStuff=$this->rewardTuple(
            $accountId,
            1,
            (int)$state['small_count']+1
        );

        $bigStuff=$this->rewardTuple(
            $accountId,
            2,
            (int)$state['big_count']+1
        );

        if ($rewardType === 1) {
            if ($accountId <= 0) {
                return '-1';
            }

            $claimed=$this->repository->claim(
                $accountId,
                1,
                $now,
                self::SMALL_WAIT
            );

            if (!$claimed) {
                return '-1';
            }

            $state=$claimed;
        }

        if ($rewardType === 2) {
            if ($accountId <= 0) {
                return '-1';
            }

            $claimed=$this->repository->claim(
                $accountId,
                2,
                $now,
                self::BIG_WAIT
            );

            if (!$claimed) {
                return '-1';
            }

            $state=$claimed;
        }

        $smallLeft=$this->left(
            (int)$state['small_last_at'],
            self::SMALL_WAIT,
            $now
        );

        $bigLeft=$this->left(
            (int)$state['big_last_at'],
            self::BIG_WAIT,
            $now
        );

        $decodedChk=$this->decodeChk(
            $chk,
            '59182'
        );

        $plain=
            '1:'.
            $userId.':'.
            $decodedChk.':'.
            $udid.':'.
            $accountId.':'.
            $smallLeft.':'.
            $smallStuff.':'.
            (int)$state['small_count'].':'.
            $bigLeft.':'.
            $bigStuff.':'.
            (int)$state['big_count'].':'.
            $rewardType;

        return $this->pack(
            $plain,
            '59182',
            'pC26fpYaQCtg'
        );
    }

    public function secretReward(
        int $accountId,
        string $udid,
        string $chk,
        string $credential,
        string $rewardKey,
        string $secret,
        string $ip = ''
    ): string {
        if ($secret !== self::SECRET_REWARD_SECRET) {
            return '-1';
        }

        $rewardKey = trim($rewardKey);
        $ip = trim($ip);

        if ($rewardKey === '' || strlen($rewardKey) > 128) {
            return '-1';
        }

        if ($accountId > 0) {
            $this->auth->authenticate($accountId, $credential);

            if (!$this->repository->account($accountId)) {
                return '-1';
            }
        }

        $decodedChk = $this->decodeChk($chk, '59182');

        if ($decodedChk === '') {
            return '-1';
        }

        $reward = $this->repository->secretReward($rewardKey);

        if (!$reward) {
            return '-1';
        }

        if (
            $accountId <= 0 &&
            (
                $udid === '' ||
                filter_var($ip, FILTER_VALIDATE_IP) === false
            )
        ) {
            return '-1';
        }

        $claimKey = $accountId > 0
            ? 'a:' . $accountId
            : 'u:' . hash('sha256', $ip . '|' . $udid);

        $claimed = $this->repository->claimSecretReward(
            (int)$reward['reward_id'],
            $claimKey
        );

        if (!$claimed) {
            return '-1';
        }

        $plain =
            'Mucho:' .
            $decodedChk . ':' .
            (int)$claimed['reward_id'] . ':' .
            (int)$claimed['chest_type'] . ':' .
            (string)$claimed['rewards'];

        $encoded = base64_encode(
            $this->xorCipher($plain, '59182')
        );

        $encoded = strtr($encoded, '/+', '_-');

        return
            'Mucho' .
            $encoded .
            '|' .
            GdHash::rewards($encoded);
    }

    public function challenges(
        int $accountId,
        string $udid,
        string $chk,
        string $credential
    ): string {
        if ($accountId > 0) {
            $this->auth->authenticate($accountId, $credential);

            $account=$this->repository->account($accountId);

            if (!$account) {
                return '-1';
            }

            $userId=(int)$account['user_id'];

        } else {
            $userId=0;
        }

        $pool=$this->repository->challenges();

        if (count($pool) < 3) {
            return '-1';
        }

        $day=gmdate('Y-m-d');

        usort(
            $pool,
            static fn(array $a,array $b): int =>
                strcmp(
                    hash(
                        'sha256',
                        $day.'|'.$a['challenge_id']
                    ),
                    hash(
                        'sha256',
                        $day.'|'.$b['challenge_id']
                    )
                )
        );

        $baseDate=strtotime(
            '2000-12-17 00:00:00 UTC'
        );

        $questBase=(int)floor(
            (time()-$baseDate)/86400
        )*3;

        $timeLeft=max(
            0,
            strtotime('tomorrow UTC')-time()
        );

        $quests=[];

        for($i=0;$i<3;$i++){
            $q=$pool[$i];

            $quests[]=
                ($questBase+$i).','.
                (int)$q['type'].','.
                (int)$q['amount'].','.
                (int)$q['reward'].','.
                str_replace(
                    [':',','],
                    ' ',
                    (string)$q['name']
                );
        }

        $decodedChk=$this->decodeChk(
            $chk,
            '19847'
        );

        $plain=
            'SaKuJ:'.
            $userId.':'.
            $decodedChk.':'.
            $udid.':'.
            $accountId.':'.
            $timeLeft.':'.
            implode(':',$quests);

        return $this->pack(
            $plain,
            '19847',
            'oC36fpYaPtdg'
        );
    }

    private function rewardTuple(
        int $accountId,
        int $type,
        int $number
    ): string {
        $seed=hash(
            'sha256',
            'MUCHO_V75|'.
            $accountId.'|'.
            $type.'|'.
            $number
        );

        if ($type === 1) {
            $orbs=$this->range($seed,0,200,400);
            $diamonds=$this->range($seed,8,2,10);
            $keys=$this->range($seed,16,1,6);

            $items=self::SMALL_ITEMS;

            $item=$items[
                $this->range(
                    $seed,
                    24,
                    0,
                    count($items)-1
                )
            ];

        } else {
            $orbs=$this->range(
                $seed,
                0,
                2000,
                4000
            );

            $diamonds=$this->range(
                $seed,
                8,
                20,
                100
            );

            $keys=$this->range(
                $seed,
                16,
                1,
                6
            );

            $items=self::BIG_ITEMS;

            $item=$items[
                $this->range(
                    $seed,
                    24,
                    0,
                    count($items)-1
                )
            ];
        }

        return
            $orbs.','.
            $diamonds.','.
            $item.','.
            $keys;
    }

    private function range(
        string $hash,
        int $offset,
        int $min,
        int $max
    ): int {
        $n=hexdec(substr($hash,$offset,8));

        return $min+(
            $n % ($max-$min+1)
        );
    }

    private function left(
        int $last,
        int $wait,
        int $now
    ): int {
        if ($last <= 0) {
            return 0;
        }

        return max(
            0,
            $wait-($now-$last)
        );
    }

    private function decodeChk(
        string $chk,
        string $key
    ): string {
        if (strlen($chk) <= 5) {
            return '';
        }

        $encoded=substr($chk,5);

        $encoded=strtr(
            $encoded,
            '-_',
            '+/'
        );

        $padding=strlen($encoded)%4;

        if ($padding !== 0) {
            $encoded.=str_repeat(
                '=',
                4-$padding
            );
        }

        $raw=base64_decode(
            $encoded,
            true
        );

        if ($raw === false) {
            return '';
        }

        return $this->xorCipher(
            $raw,
            $key
        );
    }

    private function pack(
        string $plain,
        string $key,
        string $salt
    ): string {
        $encoded=base64_encode(
            $this->xorCipher(
                $plain,
                $key
            )
        );

        $encoded=strtr(
            $encoded,
            '/+',
            '_-'
        );

        $hash=sha1(
            $encoded.$salt
        );

        return 'SaKuJ'.$encoded.'|'.$hash;
    }

    private function xorCipher(
        string $value,
        string $key
    ): string {
        $out='';
        $keyLength=strlen($key);

        for(
            $i=0,$len=strlen($value);
            $i<$len;
            $i++
        ){
            $out.=
                $value[$i] ^
                $key[$i % $keyLength];
        }

        return $out;
    }
}
