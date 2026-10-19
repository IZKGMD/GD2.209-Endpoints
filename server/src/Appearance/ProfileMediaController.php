<?php

declare(strict_types=1);

namespace MuchoCore\Appearance;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDOException;
use Throwable;

final readonly class ProfileMediaController
{
    public function __construct(private ProfileMediaService $service) {}

    public function profile(Request $request): Response
    {
        return $this->run($request,fn(): Response => Response::json(['ok'=>true,'data'=>$this->service->profile($request->postInt('accountID'))]));
    }

    public function upload(Request $request): Response
    {
        return $this->run($request,fn(): Response => Response::json(['ok'=>true,'data'=>$this->service->upload(
            $request->postInt('accountID'),$request->gdCredential(),$request->postString('kind'),$request->postString('imageData'),
            $this->cropInt($request,'cropX',500),$this->cropInt($request,'cropY',500),$this->cropInt($request,'zoom',100),$this->cropInt($request,'rotation',0)
        )]));
    }

    private function cropInt(Request $request,string $key,int $default): int
    {
        if (!array_key_exists($key,$request->post)) return $default;
        $value=$request->post[$key];
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[0-9]{1,4}$/D',(string)$value)!==1) {
            throw new \RuntimeException('Invalid crop.');
        }
        return (int)$value;
    }

    public function remove(Request $request): Response
    {
        return $this->run($request,fn(): Response => Response::json(['ok'=>true,'data'=>$this->service->remove(
            $request->postInt('accountID'),$request->gdCredential(),$request->postString('kind')
        )]));
    }

    public function image(Request $request): Response
    {
        return $this->run($request,function() use ($request): Response {
            $jpeg=$this->service->image($request->postInt('accountID'),$request->postString('kind'),$request->postString('version'));
            return $jpeg===null ? Response::json(['ok'=>false,'error'=>'Image not found.'],404) : new Response($jpeg,200,'image/jpeg');
        });
    }

    private function run(Request $request,callable $action): Response
    {
        if (strtoupper($request->method)!=='POST') return Response::json(['ok'=>false,'error'=>'Use POST.'],405);
        try { return $action(); }
        catch (Throwable $error) {
            error_log('[MuchoCore][Profile] '.$error::class.': '.$error->getMessage());
            return Response::json(['ok'=>false,'error'=>$error instanceof PDOException
                ? 'Profile storage is unavailable. Update MuchoCore and check the server logs.' : $error->getMessage()],400);
        }
    }
}
