<?php
declare(strict_types=1);

namespace MuchoCore\News;

use PDO;

final class NewsImageResponse
{
    public static function send(PDO $db,string $filename,bool $owner=false): never
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $method=strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method,['GET','HEAD'],true)) {
            http_response_code(405);header('Allow: GET, HEAD');exit;
        }
        try { $bytes=(new NewsMediaService($db))->image($filename,$owner); }
        catch (\Throwable $error) {
            error_log('[MuchoCore News Image] '.$error->getMessage());
            http_response_code(503);exit;
        }
        if ($bytes===null) { http_response_code(404);exit; }
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline; filename="'.$filename.'"');
        if (!$owner) {
            header('Cache-Control: public, max-age=0, must-revalidate');
            $etag='"'.hash('sha256',$bytes).'"';header('ETag: '.$etag);
            if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))===$etag) { http_response_code(304);exit; }
        }
        header('Content-Length: '.strlen($bytes));
        if ($method!=='HEAD') echo $bytes;
        exit;
    }
}
