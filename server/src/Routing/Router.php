<?php

declare(strict_types=1);

namespace MuchoCore\Routing;

use Closure;
use InvalidArgumentException;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

final class Router
{
    /** @var array<string, Closure> */
    private array $routes = [];

    public function add(string $method, string $path, mixed $handler): void
    {
        if (!is_callable($handler)) {
            throw new InvalidArgumentException(
                sprintf('Invalid route handler for %s %s', $method, $path)
            );
        }

        $key = $this->routeKey($method, $path);
        $this->routes[$key] = Closure::fromCallable($handler);
    }

    /**
     * Register a third-party plugin route without allowing it to shadow
     * an existing core or plugin route.
     */
    public function addPlugin(string $method, string $path, mixed $handler): void
    {
        if (!is_callable($handler)) {
            throw new InvalidArgumentException(
                sprintf('Invalid plugin route handler for %s %s', $method, $path)
            );
        }

        $method = strtoupper(trim($method));
        $path = $this->normalizePath(trim($path));

        if ($path === '') {
            throw new InvalidArgumentException('Plugin route path cannot be empty.');
        }

        $key = $this->routeKey($method, $path);

        if (isset($this->routes[$key])) {
            throw new InvalidArgumentException(
                sprintf('Plugin route collision: %s %s', $method, $path)
            );
        }

        if (
            $method !== 'ANY' &&
            isset($this->routes[$this->routeKey('ANY', $path)])
        ) {
            throw new InvalidArgumentException(
                sprintf('Plugin route collides with ANY route: %s %s', $method, $path)
            );
        }

        if ($method === 'ANY') {
            foreach (array_keys($this->routes) as $existingKey) {
                if (str_ends_with($existingKey, ' ' . $path)) {
                    throw new InvalidArgumentException(
                        sprintf('Plugin ANY route collides with existing route: %s', $path)
                    );
                }
            }
        }

        $this->routes[$key] = Closure::fromCallable($handler);
    }

    public function dispatch(Request $request): Response
    {
        $method = strtoupper((string)($request->method ?? 'GET'));
        $path = $this->normalizePath((string)($request->path ?? '/'));

        if ($path === '/checkifserveronline') {
            return Response::text('1');
        }

        foreach ([
            $this->routeKey($method, $path),
            $this->routeKey('ANY', $path),
        ] as $key) {
            if (isset($this->routes[$key])) {
                return ($this->routes[$key])($request);
            }
        }

        return Response::text('-1');
    }

    public function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : '/';

        $path = preg_replace('#/+#', '/', $path) ?? '/';

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        while (
            preg_match(
                '#^/(?:database|accounts|api|a)(?:/|$)#i',
                $path
            ) === 1
        ) {
            $path = preg_replace(
                '#^/(?:database|accounts|api|a)(?:/|$)#i',
                '/',
                $path,
                1
            ) ?? $path;
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if ($path === '') {
            $path = '/';
        }

        $path = preg_replace('#\.php$#i', '', $path) ?? $path;

        if ($path === '') {
            $path = '/';
        }

        $path = strtolower($path);

        static $compatAliases = [
            // Account / cloud save.
            '/logingjaccount19' => '/logingjaccount',
            '/logingjaccount20' => '/logingjaccount',
            '/logingjaccount21' => '/logingjaccount',
            '/logingjaccount22' => '/logingjaccount',
            '/registergjaccount19' => '/registergjaccount',
            '/registergjaccount20' => '/registergjaccount',
            '/registergjaccount21' => '/registergjaccount',
            '/registergjaccount22' => '/registergjaccount',
            '/backupgjaccountnew' => '/backupgjaccount',
            '/backupgjaccount19' => '/backupgjaccount',
            '/backupgjaccount20' => '/backupgjaccount',
            '/syncgjaccountnew' => '/syncgjaccount',
            '/syncgjaccount19' => '/syncgjaccount',
            '/syncgjaccount20' => '/syncgjaccount',

            // Level discovery / transfer.
            '/getgjlevels' => '/getgjlevels21',
            '/getgjlevels19' => '/getgjlevels21',
            '/getgjlevels20' => '/getgjlevels21',
            '/getgjlevels22' => '/getgjlevels21',
            '/uploadgjlevel' => '/uploadgjlevel21',
            '/uploadgjlevel19' => '/uploadgjlevel21',
            '/uploadgjlevel20' => '/uploadgjlevel21',
            '/updategjlevel' => '/updategjlevel',
            '/updategjlevel19' => '/updategjlevel',
            '/updategjlevel20' => '/updategjlevel',
            '/downloadgjlevel' => '/downloadgjlevel21',
            '/downloadgjlevel19' => '/downloadgjlevel21',
            '/downloadgjlevel20' => '/downloadgjlevel21',
            '/deletegjleveluser' => '/deletegjleveluser20',
            '/deletegjleveluser19' => '/deletegjleveluser20',
            '/updatedesc' => '/updategjleveldesc20',
            '/updategjdesc' => '/updategjleveldesc20',
            '/updategjdesc20' => '/updategjleveldesc20',
            '/getgjlevelscores19' => '/getgjlevelscores',
            '/getgjlevelscores20' => '/getgjlevelscores',
            '/getgjlevelscores21' => '/getgjlevelscores211',
            '/getgjlevelscores22' => '/getgjlevelscores',
            '/getgjmappacks19' => '/getgjmappacks21',
            '/getgjmappacks20' => '/getgjmappacks21',
            '/getgjgauntlets19' => '/getgjgauntlets21',
            '/getgjgauntlets20' => '/getgjgauntlets21',

            // Comments.
            '/getgjcomments' => '/getgjcomments21',
            '/getgjcomments15' => '/getgjcomments21',
            '/getgjcomments19' => '/getgjcomments21',
            '/getgjcomments20' => '/getgjcomments21',
            '/uploadgjcomment' => '/uploadgjcomment20',
            '/uploadgjcomment15' => '/uploadgjcomment20',
            '/uploadgjcomment19' => '/uploadgjcomment20',
            '/deletegjcomment19' => '/deletegjcomment20',
            '/deletegjcomment15' => '/deletegjcomment20',
            '/deletegjcomment' => '/deletegjcomment20',
            '/getgjaccountcomments' => '/getgjaccountcomments20',
            '/uploadgjacccomment' => '/uploadgjacccomment20',
            '/deletegjacccomment' => '/deletegjacccomment20',

            // Users / leaderboards.
            '/getgjuserinfo' => '/getgjuserinfo20',
            '/getgjuserinfo19' => '/getgjuserinfo20',
            '/getgjuserinfo21' => '/getgjuserinfo20',
            '/getgjuserinfo22' => '/getgjuserinfo20',
            '/getgjusers' => '/getgjusers20',
            '/getgjusers19' => '/getgjusers20',
            '/getgjusers21' => '/getgjusers20',
            '/getgjusers22' => '/getgjusers20',
            '/getgjscores' => '/getgjscores20',
            '/getgjscores19' => '/getgjscores20',
            '/getgjscores21' => '/getgjscores20',
            '/getgjscores22' => '/getgjscores20',
            '/updategjaccsettings19' => '/updategjaccsettings20',
            '/updategjaccsettings21' => '/updategjaccsettings20',
            '/updategjaccsettings22' => '/updategjaccsettings20',
            '/updategjuserscore19' => '/updategjuserscore',
            '/updategjuserscore20' => '/updategjuserscore',
            '/updategjuserscore21' => '/updategjuserscore',
            '/getgjuserlist' => '/getgjuserlist20',

            // Messages / relationships.
            '/getgjmessages' => '/getgjmessages20',
            '/downloadgjmessage' => '/downloadgjmessage20',
            '/uploadgjmessage' => '/uploadgjmessage20',
            '/deletegjmessages' => '/deletegjmessages20',
            '/uploadfriendrequest' => '/uploadfriendrequest20',
            '/getgjfriendrequests' => '/getgjfriendrequests20',
            '/readgjfriendrequest' => '/readgjfriendrequest20',
            '/acceptgjfriendrequest' => '/acceptgjfriendrequest20',
            '/deletegjfriendrequests' => '/deletegjfriendrequests20',
            '/removegjfriend' => '/removegjfriend20',
            '/blockgjuser' => '/blockgjuser20',
            '/unblockgjuser' => '/unblockgjuser20',

            // Likes / ratings.
            '/likegjitem' => '/likegjitem21',
            '/likegjitem19' => '/likegjitem21',
            '/likegjitem20' => '/likegjitem21',
            '/likegjlevel' => '/likegjitem21',
            '/suggestgjstars' => '/suggestgjstars20',
            '/rategjstars' => '/rategjstars20',
            '/rategjdemon' => '/rategjdemon21',
        ];

        return $compatAliases[$path] ?? $path;
    }

    private function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $this->normalizePath($path);
    }
}
