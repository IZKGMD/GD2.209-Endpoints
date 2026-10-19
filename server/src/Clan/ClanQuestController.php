<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final readonly class ClanQuestController
{
    public function __construct(private PDO $pdo,private AccountAuthenticator $auth,private ClanQuestRepository $repository) {}

    public function quests(Request $request): Response { return $this->run($request,'quests'); }
    public function create(Request $request): Response { return $this->run($request,'create'); }
    public function cancel(Request $request): Response { return $this->run($request,'cancel'); }
    public function contributions(Request $request): Response { return $this->run($request,'contributions'); }
    public function activity(Request $request): Response { return $this->run($request,'activity'); }

    private function run(Request $request,string $action): Response
    {
        if ($request->method!=='POST') return Response::json(['ok'=>false,'error'=>'POST required.'],405);
        try {
            $account=$request->postInt('accountID');
            $this->auth->authenticate($account,$request->gdCredential());
            $this->pdo->beginTransaction();
            $member=$this->repository->lockForAccount($account);
            if (!$member) throw new RuntimeException('You are not in a clan.');
            if (in_array($action,['create','cancel'],true) && !in_array($member['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }
            $clan=(int)$member['clan_id'];
            $offset=max(0,min(100000,$request->postInt('offset')));
            $limit=max(1,min(50,$request->postInt('limit',20)));
            $data=match ($action) {
                'create'=>$this->repository->create($clan,$account,$request->postString('metric'),$request->postInt('target')),
                'cancel'=>$this->repository->cancel($clan,$account,$request->postInt('questID')),
                'contributions'=>$this->repository->contributions($clan,$request->postInt('questID'),$offset,$limit),
                'activity'=>$this->repository->activity($clan,$offset,$limit),
                default=>$this->repository->quests($clan),
            };
            $data['can_manage']=in_array($member['role'],['owner','officer'],true);
            $this->pdo->commit();
            return Response::json(['ok'=>true,'data'=>$data]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[MuchoCore][ClanQuest] '.$e::class.': '.$e->getMessage());
            return Response::json(['ok'=>false,'error'=>$e instanceof PDOException
                ? 'Clan quests are unavailable. Update MuchoCore and run migrations.' : $e->getMessage()],400);
        }
    }
}
