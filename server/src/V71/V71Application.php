<?php
declare(strict_types=1);

namespace MuchoCore\V71;

use MuchoCore\LevelList\LevelListRepository;
use MuchoCore\LevelList\LevelListService;
use MuchoCore\LevelList\LevelListController;
use MuchoCore\Comment\CommentHistoryRepository;
use MuchoCore\Comment\CommentHistoryService;
use MuchoCore\Comment\CommentHistoryController;
use MuchoCore\Artist\TopArtistRepository;
use MuchoCore\Artist\TopArtistService;
use MuchoCore\Artist\TopArtistController;
use MuchoCore\Url\UrlController;
use Throwable;

/**
 * v7.1 transport/application adapter.
 *
 * Existing src/Core/Application.php is deliberately left untouched by this
 * additive installer. Public Geometry Dash endpoint files delegate here,
 * then Controller -> Service -> Repository.
 */
final class V71Application
{
    public static function handle(string $endpoint): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-MuchoCore: v7.1');

        try {
            /*
             * Standalone v7.1 entrypoints do not pass through public/index.php.
             * Apply the same protection and compatibility gates here so these
             * legacy endpoints cannot become an unthrottled alternate path.
             */
            $request = \MuchoCore\Http\Request::fromGlobals();
            $router = new \MuchoCore\Routing\Router();
            $path = $router->normalizePath($request->path);

            $protection = (new \MuchoCore\Security\MuchoProtect())->inspect(
                $request,
                $path
            );

            if ($protection['decision'] === 'block') {
                http_response_code(200);
                echo '-1';
                return;
            }

            $profile = \MuchoCore\Compatibility\CompatibilityProfile::fromEnvironment();

            if (!$profile->allows($request->clientVersion())) {
                http_response_code(200);
                echo '-1';
                return;
            }

            if ($endpoint === 'account-url' || $endpoint === 'custom-content-url') {
                $controller = new UrlController();
                echo $endpoint === 'account-url'
                    ? $controller->accountUrl()
                    : $controller->customContentUrl();
                return;
            }

            $db = DatabaseBridge::pdo();

            switch ($endpoint) {
                case 'level-lists-get':
                    $controller = new LevelListController(
                        new LevelListService(
                            new LevelListRepository($db),
                            new AuthService($db)
                        )
                    );
                    echo $controller->get();
                    return;

                case 'level-list-upload':
                    $controller = new LevelListController(
                        new LevelListService(
                            new LevelListRepository($db),
                            new AuthService($db)
                        )
                    );
                    echo $controller->upload();
                    return;

                case 'level-list-delete':
                    $controller = new LevelListController(
                        new LevelListService(
                            new LevelListRepository($db),
                            new AuthService($db)
                        )
                    );
                    echo $controller->delete();
                    return;

                case 'comment-history':
                    $controller = new CommentHistoryController(
                        new CommentHistoryService(
                            new CommentHistoryRepository($db)
                        )
                    );
                    echo $controller->get();
                    return;

                case 'top-artists':
                    $controller = new TopArtistController(
                        new TopArtistService(
                            new TopArtistRepository($db)
                        )
                    );
                    echo $controller->get();
                    return;

                default:
                    http_response_code(404);
                    echo '-1';
            }
        } catch (Throwable $e) {
            error_log('[MuchoCore v7.1] ' . $endpoint . ': ' . $e->getMessage());
            /*
             * Legacy Geometry Dash transports expect a successful HTTP
             * response carrying -1 for application-level failure.
             */
            http_response_code(200);
            echo '-1';
        }
    }
}
