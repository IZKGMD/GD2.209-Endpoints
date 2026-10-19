<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDOException;
use Throwable;

final readonly class ClanController
{
    public function __construct(private ClanService $service) {}

    public function create(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->create(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postString('clanName'),
                $request->postString('clanTag'),
                $request->postString('clanDescription'),
                $request->postInt('clanOpen',1)===1,
                $request->postInt('clanMaxMembers',50)
            );
        });
    }

    public function myClan(Request $request): Response
    {
        return $this->run(function() use ($request): ?array {
            return $this->service->myClan(
                $request->postInt('accountID'),
                $request->gdCredential()
            );
        });
    }

    public function get(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->get(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postInt('clanID')
            );
        });
    }

    public function search(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            $offset=max(0,min(100000,$request->postInt('offset')));
            $limit=max(1,min(50,$request->postInt('limit',20)));
            $clans=$this->service->search(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postString('query'),
                $offset,
                $limit+1
            );
            return [
                'clans'=>array_slice($clans,0,$limit),
                'has_more'=>count($clans)>$limit,
                'offset'=>$offset,
            ];
        });
    }

    public function leaderboard(Request $request): Response
    {
        return $this->run(fn(): array => $this->service->leaderboard(
            $request->postString('metric') ?: 'stars',
            $request->postInt('offset'),
            $request->postInt('limit',20),
            $request->postInt('accountID')
        ));
    }

    public function join(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'joined'=>$this->service->join(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('clanID')
                ),
            ];
        });
    }

    public function leave(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'left'=>$this->service->leave(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function invite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'invited'=>$this->service->invite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function acceptInvite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'accepted'=>$this->service->acceptInvite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('inviteID')
                ),
            ];
        });
    }

    public function declineInvite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'declined'=>$this->service->declineInvite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('inviteID')
                ),
            ];
        });
    }

    public function kick(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'kicked'=>$this->service->kick(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function setRole(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'updated'=>$this->service->setRole(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID'),
                    $request->postString('role')
                ),
            ];
        });
    }

    public function updateSettings(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return $this->service->updateSettings(
                $request->postInt('accountID'),
                $request->gdCredential(),
                $request->postInt('clanID'),
                $request->postString('clanName'),
                $request->postString('clanTag'),
                $request->postString('clanDescription'),
                $request->postInt('clanOpen',1)===1,
                $request->postInt('clanMaxMembers',50)
            );
        });
    }

    public function transferOwnership(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'transferred'=>$this->service->transferOwnership(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function disband(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'disbanded'=>$this->service->disband(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function revokeInvite(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'revoked'=>$this->service->revokeInvite(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('inviteID')
                ),
            ];
        });
    }

    public function ban(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'banned'=>$this->service->ban(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID'),
                    $request->postString('reason')
                ),
            ];
        });
    }

    public function unban(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'unbanned'=>$this->service->unban(
                    $request->postInt('accountID'),
                    $request->gdCredential(),
                    $request->postInt('targetAccountID')
                ),
            ];
        });
    }

    public function bans(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'bans'=>$this->service->bans(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function invites(Request $request): Response
    {
        return $this->run(function() use ($request): array {
            return [
                'invites'=>$this->service->invites(
                    $request->postInt('accountID'),
                    $request->gdCredential()
                ),
            ];
        });
    }

    public function sentInvites(Request $request): Response
    {
        return $this->run(fn(): array => [
            'invites'=>$this->service->sentInvites(
                $request->postInt('accountID'),
                $request->gdCredential()
            ),
        ]);
    }

    private function run(callable $action): Response
    {
        try {
            return Response::json([
                'ok'=>true,
                'data'=>$action(),
            ]);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore][Clan] %s: %s',
                $e::class,
                $e->getMessage()
            ));

            return Response::json([
                'ok'=>false,
                'error'=>$e instanceof PDOException
                    ? ((int)($e->errorInfo[1] ?? 0)===1062
                        ? 'Clan name or tag already exists.'
                        : 'Clan storage is unavailable. Check the server logs.')
                    : $e->getMessage(),
            ],400);
        }
    }
}
