<?php
declare(strict_types=1);

namespace MuchoCore\Core;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountController;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Account\AccountService;
use MuchoCore\Cache\CacheManager;
use MuchoCore\Client\ClientBridgeController;
use MuchoCore\Artist\TopArtistController;
use MuchoCore\Artist\TopArtistRepository;
use MuchoCore\Artist\TopArtistService;
use MuchoCore\Comment\CommentHistoryController;
use MuchoCore\Comment\CommentHistoryRepository;
use MuchoCore\Comment\CommentHistoryService;
use MuchoCore\LevelList\LevelListController;
use MuchoCore\LevelList\LevelListRepository;
use MuchoCore\LevelList\LevelListService;
use MuchoCore\Url\UrlController;
use MuchoCore\Compatibility\DiscoveryController;
use MuchoCore\Compatibility\Legacy10IdentityController;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Compatibility\Gd2209UtilityController;
use MuchoCore\Compatibility\Gd2209UtilityService;
use MuchoCore\CloudSave\CloudSaveService;
use MuchoCore\Clan\ClanController;
use MuchoCore\Clan\ClanRepository;
use MuchoCore\Clan\ClanService;
use MuchoCore\Appearance\AppearanceController;
use MuchoCore\Appearance\AppearanceService;
use MuchoCore\Compatibility\CompatibilityProfile;
use MuchoCore\CloudSave\CloudSaveRepository;
use MuchoCore\CloudSave\CloudSaveController;
use MuchoCore\Database\Database;
use MuchoCore\Diagnostics\ClientTrace;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Interaction\CommentController;
use MuchoCore\Job\JobQueue;
use MuchoCore\Interaction\CommentRepository;
use MuchoCore\Interaction\CommentService;
use MuchoCore\Interaction\LikeController;
use MuchoCore\Interaction\LikeRepository;
use MuchoCore\Interaction\LikeService;
use MuchoCore\Interaction\RewardsController;
use MuchoCore\Level\LevelController;
use MuchoCore\Level\LevelDownloadTracker;
use MuchoCore\Level\LevelRepository;
use MuchoCore\Level\LevelService;
use MuchoCore\Level\LevelTransferController;
use MuchoCore\Level\LevelTransferRepository;
use MuchoCore\Level\LevelTransferService;
use MuchoCore\Level\LevelTelemetryController;
use MuchoCore\Level\LevelTelemetryService;
use MuchoCore\Level\LevelRevisionService;
use MuchoCore\Level\LevelValidator;
use MuchoCore\Moderation\ModerationController;
use MuchoCore\Search\LevelSearchIndexer;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Moderation\ModerationService;
use MuchoCore\Moderation\ReportQueueController;
use MuchoCore\Moderation\ReportQueueRepository;
use MuchoCore\Moderation\ReportQueueService;
use MuchoCore\Moderation\Gd2209CaseController;
use MuchoCore\Moderation\Gd2209CaseService;
use MuchoCore\Moderation\Gd2209ContentActionController;
use MuchoCore\Moderation\Gd2209ContentActionService;
use MuchoCore\Moderation\Gd2209SanctionController;
use MuchoCore\Moderation\Gd2209SanctionService;
use MuchoCore\Music\SongController;
use MuchoCore\Music\SongRepository;
use MuchoCore\Music\SongService;
use MuchoCore\Music\SongUsageController;
use MuchoCore\Music\SongUsageService;
use MuchoCore\Music\EditorSoundController;
use MuchoCore\Music\EditorSoundService;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use MuchoCore\Protocol\GdLevelListEncoder;
use MuchoCore\Protocol\GdMessageEncoder;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Protocol\GdUserEncoder;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;
use MuchoCore\News\NewsService;
use MuchoCore\News\NativeNewsFormat;
use MuchoCore\Score\LevelScoreController;
use MuchoCore\Score\PlatformerScoreController;
use MuchoCore\Security\MuchoProtect;
use MuchoCore\Social\MessageController;
use MuchoCore\Social\MessageRepository;
use MuchoCore\Social\MessageService;
use MuchoCore\Social\RelationshipController;
use MuchoCore\Social\RelationshipRepository;
use MuchoCore\Social\RelationshipService;
use MuchoCore\Social\FollowingController;
use MuchoCore\Social\FollowingService;
use MuchoCore\Social\NotificationController;
use MuchoCore\Social\NotificationService;
use MuchoCore\Social\ThreadController;
use MuchoCore\Social\ThreadService;
use MuchoCore\Social\Gd2209NativeThreadOperations;
use MuchoCore\Social\Gd2209ThreadActionService;
use MuchoCore\User\UserController;
use MuchoCore\User\UserRepository;
use MuchoCore\User\UserService;
use MuchoCore\V71\AuthService;
use PDO;
use Throwable;

final readonly class Application
{
    private Router $router;
    private PDO $pdo;
    private MuchoProtect $protect;
    private PluginManager $plugins;

    public function __construct()
    {
        $this->pdo = (new Database())->connection();
        $this->router = new Router();
        $cache = CacheManager::fromEnvironment($this->pdo);
        $jobs = new JobQueue($this->pdo);
        $this->protect = new MuchoProtect();
        $this->plugins = PluginManager::fromEnvironment(
            $this->pdo,
            $this->router,
            dirname(__DIR__, 2)
        );

        $accountRepo = new AccountRepository($this->pdo);
        $accountService = new AccountService(
            $this->pdo,
            $accountRepo,
            $jobs
        );
        $accountController = new AccountController($accountService);
        $auth = new AccountAuthenticator($this->pdo);

        $v71Auth = new AuthService($this->pdo);
        $topArtistController = new TopArtistController(
            new TopArtistService(
                new TopArtistRepository($this->pdo)
            )
        );
        $levelListController = new LevelListController(
            new LevelListService(
                new LevelListRepository($this->pdo),
                $v71Auth
            )
        );
        $commentHistoryController = new CommentHistoryController(
            new CommentHistoryService(
                new CommentHistoryRepository($this->pdo)
            )
        );
        $urlController = new UrlController();

        $cloudSaveController = new CloudSaveController(
            new CloudSaveService(
                $this->pdo,
                $auth,
                new CloudSaveRepository($this->pdo)
            )
        );

        $legacy10Identity = new Legacy10IdentityService($this->pdo);

        $levelRepo = new LevelRepository($this->pdo);
        $levelService = new LevelService(
            $levelRepo,
            new GdLevelListEncoder(),
            $cache
        );
        $modRepo = new ModerationRepository($this->pdo);
        $levelController = new LevelController($levelService, $auth, $modRepo);

        $transferRepo = new LevelTransferRepository($this->pdo);
        $transferService = new LevelTransferService(
            $this->pdo,
            $auth,
            $legacy10Identity,
            $transferRepo,
            new LevelDownloadTracker($this->pdo),
            new GdLevelDownloadEncoder(),
            new LevelValidator(),
            new LevelRevisionService($this->pdo),
            new LevelSearchIndexer($this->pdo),
            $cache,
            $jobs
        );
        $transferController =
            new LevelTransferController($transferService);

        $levelTelemetry = new LevelTelemetryController(
            new LevelTelemetryService($this->pdo, $auth)
        );

        $legacy10IdentityController =
            new Legacy10IdentityController($legacy10Identity);

        $editorSounds = new EditorSoundController(
            new EditorSoundService($this->pdo, $auth)
        );

        $songRepo = new SongRepository($this->pdo);
        $songController = new SongController(
            new SongService($songRepo, new GdSongEncoder())
        );
        $songUsageController = new SongUsageController(new SongUsageService($this->pdo));

        $modController = new ModerationController(
            new ModerationService($auth, $modRepo)
        );
        $reportQueueController = new ReportQueueController(
            new ReportQueueService($auth, $modRepo,
                new ReportQueueRepository($this->pdo))
        );

        $gd2209Cases = new Gd2209CaseController(
            new Gd2209CaseService($this->pdo, $auth, $modRepo,
                new ReportQueueRepository($this->pdo))
        );

        $gd2209ContentActions = new Gd2209ContentActionController(
            new Gd2209ContentActionService($this->pdo, $auth, $modRepo)
        );

        $gd2209Sanctions = new Gd2209SanctionController(
            new Gd2209SanctionService($this->pdo, $auth, $modRepo)
        );

        $gd2209Utilities = new Gd2209UtilityController(
            new Gd2209UtilityService($this->pdo, $auth)
        );

        $userRepo = new UserRepository($this->pdo);
        $userController = new UserController(
            new UserService(
                $this->pdo,
                $auth,
                $accountRepo,
                $userRepo,
                new GdUserEncoder(),
                $legacy10Identity,
                new \MuchoCore\Clan\ClanQuestRepository($this->pdo)
            )
        );

        $commentRepo = new CommentRepository($this->pdo);
        $commentController = new CommentController(
            new CommentService(
                $commentRepo,
                $auth,
                new GdCommentEncoder(),
                $this->pdo,
                $legacy10Identity
            )
        );

        $likeController = new LikeController(
            new LikeService(
                new LikeRepository($this->pdo),
                $auth
            )
        );

        $relationshipRepo =
            new RelationshipRepository($this->pdo);

        $relationshipController = new RelationshipController(
            new RelationshipService(
                $relationshipRepo,
                $auth,
                new GdRelationshipEncoder()
            )
        );

        $threadController = new ThreadController(
            new ThreadService($this->pdo, $auth),
            new GdRelationshipEncoder(),
            new Gd2209NativeThreadOperations($this->pdo, $auth, $relationshipRepo),
            new Gd2209ThreadActionService($this->pdo, $auth)
        );

        $notificationController = new NotificationController(
            new NotificationService($this->pdo, $auth)
        );

        $followingController = new FollowingController(
            new FollowingService(
                $this->pdo,
                $auth,
                new GdRelationshipEncoder()
            )
        );

        $messageController = new MessageController(
            new MessageService(
                new MessageRepository($this->pdo),
                $auth,
                new GdMessageEncoder(),
                $relationshipRepo
            )
        );

        $rewardsController =
            new RewardsController(
                new \MuchoCore\Interaction\RewardsService(
                    new \MuchoCore\Interaction\RewardsRepository(
                        $this->pdo
                    ),
                    $auth
                )
            );

        $clanController = new ClanController(
            new ClanService(
                $this->pdo,
                $auth,
                new ClanRepository($this->pdo)
            )
        );

        $appearanceController = new AppearanceController(new AppearanceService($this->pdo,$auth));
        $profileMediaController = new \MuchoCore\Appearance\ProfileMediaController(new \MuchoCore\Appearance\ProfileMediaService($this->pdo,$auth));
        $questController = new \MuchoCore\Clan\ClanQuestController($this->pdo,$auth,new \MuchoCore\Clan\ClanQuestRepository($this->pdo));
        $discoveryController = new DiscoveryController($this->pdo);

        $levelScoreController = new LevelScoreController($this->pdo, $auth);
        $platformerScoreController = new PlatformerScoreController($this->pdo, $auth);

        $route = function (
            string $path,
            mixed $handler
        ): void {
            $this->router->add('ANY', $path, $handler);
        };

        $bridge = new ClientBridgeController(
            $this->plugins->clientFeatures(),
            trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION'))
        );
        $route('/muchoclient/manifest', [$bridge, 'manifest']);
        $route('/muchoclient/negotiate', [$bridge, 'negotiate']);

        $route('/health',
            static fn(Request $r): Response =>
                Response::text('1'));

        // JSON feed for custom clients and the web news board. This is NOT
        // the undocumented native GD 2.209 /news/news.dat format.
        $news = new NewsService($this->pdo);
        $route('/muchocore/news',
            static fn(Request $r): Response =>
                strtoupper((string)$r->method) === 'GET'
                    ? Response::json(['version' => 1, 'posts' => $news->published()])
                    : Response::json(['error' => 'Method not allowed'], 405));

        // GD 2.209 native news fallback. Ordinary Caddy/PHP installations
        // may route unknown non-PHP extensions to public/index.php before a
        // custom Caddyfile reload. Serve the *same* news payload here, too,
        // rather than returning the generic "-1" API not-found marker.
        $nativeNewsData = static function (Request $r) use ($news): Response {
            if (strtoupper((string)$r->method) !== 'GET') {
                return Response::text('', 405);
            }
            try {
                return Response::text(
                    NativeNewsFormat::encode($news->published(30))
                );
            } catch (\Throwable $e) {
                error_log('[MuchoCore Native News Data] ' . $e::class . ': ' . $e->getMessage());
                return Response::text('', 503);
            }
        };
        $nativeNewsVersion = static function (Request $r) use ($news): Response {
            if (strtoupper((string)$r->method) !== 'GET') {
                return Response::text('', 405);
            }
            try {
                return Response::text($news->nativeVersion());
            } catch (\Throwable $e) {
                error_log('[MuchoCore Native News Version] ' . $e::class . ': ' . $e->getMessage());
                return Response::text('', 503);
            }
        };

        // Paths used by the native client after getCustomContentURL.
        $route('/gd-content/news/news.dat', $nativeNewsData);
        $route('/gd-content/news/news_version.txt', $nativeNewsVersion);
        // Direct diagnostic aliases, also handled in the Caddyfile.
        $route('/news/news.dat', $nativeNewsData);
        $route('/news/news_version.txt', $nativeNewsVersion);

        // Legacy/compatibility endpoints implemented by dedicated services.
        $route('/updateGJUserName',
            [$legacy10IdentityController, 'updateUsername']);

        $route('/getAccountURL',
            fn(Request $r): Response =>
                Response::text($urlController->accountUrl()));
        $route('/getCustomContentURL',
            fn(Request $r): Response =>
                Response::text($urlController->customContentUrl()));
        $route('/getGJCommentHistory',
            fn(Request $r): Response =>
                Response::text($commentHistoryController->get()));
        $route('/getGJLevelLists',
            fn(Request $r): Response =>
                Response::text($levelListController->get()));
        $route('/uploadGJLevelList',
            fn(Request $r): Response =>
                Response::text($levelListController->upload()));
        $route('/deleteGJLevelList',
            fn(Request $r): Response =>
                Response::text($levelListController->delete()));
        $route('/getGJTopArtists',
            fn(Request $r): Response =>
                Response::text($topArtistController->get()));

        $route('/loginGJAccount',
            [$accountController,'login']);
        $route('/registerGJAccount',
            [$accountController,'register']);

        /*
         * MuchoCore Secure Cloud Save v7
         * Copyright (C) 2026 IZK
         */

        $route('/backupGJAccount',
            [$cloudSaveController,'backup']);

        $route('/backupGJAccount20',
            [$cloudSaveController,'backup']);

        $route('/syncGJAccount',
            [$cloudSaveController,'sync']);

        $route('/syncGJAccount20',
            [$cloudSaveController,'sync']);

        $route('/getGJLevels21',
            [$levelController,'list']);
        $route('/updateGJLevel',
            [$transferController,'checkUpdate']);
        $route('/uploadGJLevel21',
            [$transferController,'upload']);
        $route('/uploadGJLevel22',
            [$transferController,'upload']);
        $route('/downloadGJLevel21',
            [$transferController,'download']);
        $route('/downloadGJLevel22',
            [$transferController,'download']);
        $route('/deleteGJLevelUser20',
            [$transferController,'delete']);

        $route('/updateGJLevelDesc20',
            [$transferController,'updateDescription']);

        $route('/getGJSongInfo',
            [$songController,'info']);
        // 2.209 documented comma/hash/pipe song-usage statistics.
        $route('/getGJSongUsage', [$songUsageController, 'get']);

        $route('/suggestGJStars20',
            [$modController,'suggest']);

        $route('/rateGJStars20',
            [$modController,'rateStars']);

        $route('/rateGJStars211',
            [$modController,'rateStars']);

        $route('/rateGJDemon21',
            [$modController,'rateDemon']);

        $route('/reportGJLevel',
            [$modController,'report']);
        // GD 2.209 moderator abuse report queue. Native reply framing TBD.
        $route('/adminGetReportQueue', [$reportQueueController, 'queue']);
        // GD 2.209 staged account-abuse service: authenticated and persisted;
        // wire formats other than queue remain provisional until native captures.
        $route('/reportGJContent', [$gd2209Cases, 'reportContent']);
        $route('/adminGetUserHistory', [$gd2209Cases, 'userHistory']);
        $route('/adminGetReportedContent', [$gd2209Cases, 'reportedContent']);
        $route('/adminGetAbuseInfo', [$gd2209Cases, 'abuseInfo']);
        $route('/adminGetAccountReview', [$gd2209Cases, 'accountReview']);
        $route('/adminClearReportedContent', [$gd2209Cases, 'clearReportedContent']);
        $route('/adminCloseCase', [$gd2209Cases, 'closeCase']);
        $route('/deleteGJComments', [$gd2209ContentActions, 'deleteOwn']);
        $route('/adminDeleteUserComments', [$gd2209ContentActions, 'deleteUser']);
        $route('/adminThreadAction', [$gd2209ContentActions, 'threadAction']);

        // 2.209 account restrictions: only roles from the database authorize
        // actions. The enforcement is shared with legacy upload/send endpoints.
        $route('/adminCommentBan', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'comment', true));
        $route('/adminCommentUnban', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'comment', false));
        $route('/adminUploadBan', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'upload', true));
        $route('/adminUploadUnban', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'upload', false));
        $route('/adminMessageBan', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'message', true));
        $route('/adminMessageUnban', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'message', false));
        $route('/adminFreezeAccount', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'freeze', true));
        $route('/adminUnfreezeAccount', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'freeze', false));
        $route('/adminTrendingBan', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'trending', true));
        $route('/adminTrendingUnban', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'trending', false));
        $route('/adminNameLock', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'name', true));
        $route('/adminNameUnlock', fn(Request $r): Response => $gd2209Sanctions->apply($r, 'name', false));
        // Remaining native 2.209 URLs; payload parsers are provisional.
        $route('/getGJAudioFilesize', [$gd2209Utilities, 'audioSize']);
        $route('/getGJContentStatus', [$gd2209Utilities, 'contentStatus']);
        $route('/openedRegister', [$gd2209Utilities, 'openedRegister']);
        $route('/accounts/openedRegister', [$gd2209Utilities, 'openedRegister']);
        $route('/requestUserAccess',
            [$userController,'requestAccess']);

        $route('/getGJUserInfo20',
            [$userController,'profile']);
        $route('/getGJUsers20',
            [$userController,'search']);
        // GD 2.209: provisional alias to the existing read-only user search.
        $route('/getGJUserSearch',
            [$userController,'search209']);
        $route('/getGJScores20',
            [$userController,'scores']);
        $route('/updateGJAccSettings20',
            [$userController,'updateSettings']);
        $route('/updateGJUserScore',
            [$userController,'updateScore']);
        $route('/updateGJUserScore22',
            [$userController,'updateScore']);

        $route('/getGJComments21',
            [$commentController,'getLevelComments']);
        $route('/uploadGJComment20',
            [$commentController,'uploadLevelComment']);
        $route('/uploadGJComment21',
            [$commentController,'uploadLevelComment']);
        $route('/deleteGJComment20',
            [$commentController,'deleteComment']);
        $route('/getGJAccountComments20',
            [$commentController,'getAccountComments']);
        $route('/uploadGJAccComment20',
            [$commentController,'uploadAccountComment']);
        $route('/deleteGJAccComment20',
            [$commentController,'deleteAccountComment']);

        $route('/likeGJItem21',
            [$likeController,'like']);
        $route('/likeGJItem211',
            [$likeController,'like']);

        $route('/getGJMessages20',
            [$messageController,'getMessages']);
        $route('/downloadGJMessage20',
            [$messageController,'readMessage']);
        $route('/uploadGJMessage20',
            [$messageController,'sendMessage']);
        $route('/deleteGJMessages20',
            [$messageController,'deleteMessage']);

        $route('/uploadFriendRequest20',
            [$relationshipController,'send']);
        $route('/getGJFriendRequests20',
            [$relationshipController,'get']);
        $route('/readGJFriendRequest20',
            [$relationshipController,'read']);
        $route('/acceptGJFriendRequest20',
            [$relationshipController,'accept']);
        $route('/deleteGJFriendRequests20',
            [$relationshipController,'delete']);
        $route('/removeGJFriend20',
            [$relationshipController,'remove']);
        $route('/blockGJUser20',
            [$relationshipController,'block']);
        $route('/unblockGJUser20',
            [$relationshipController,'unblock']);
        $route('/getGJUserList20',
            [$relationshipController,'userList']);

        /*
         * MuchoCore Compatibility v6
         * Copyright (C) 2026 IZK
         */

        /*
         * MuchoCore Clans
         * Custom JSON API for clan management. Clan membership is also
         * reflected in standard Geometry Dash user-name response fields.
         */
        $route('/api/clans/create',
            [$clanController,'create']);
        $route('/api/clans/my',
            [$clanController,'myClan']);
        $route('/api/clans/get',
            [$clanController,'get']);
        $route('/api/clans/search',
            [$clanController,'search']);
        $route('/api/clans/leaderboard',
            [$clanController,'leaderboard']);
        $route('/api/clans/join',
            [$clanController,'join']);
        $route('/api/clans/leave',
            [$clanController,'leave']);
        $route('/api/clans/invite',
            [$clanController,'invite']);
        $route('/api/clans/invite/accept',
            [$clanController,'acceptInvite']);
        $route('/api/clans/invite/decline',
            [$clanController,'declineInvite']);
        $route('/api/clans/kick',
            [$clanController,'kick']);
        $route('/api/clans/role',
            [$clanController,'setRole']);
        $route('/api/clans/invites',
            [$clanController,'invites']);
        $route('/api/clans/invites/sent',
            [$clanController,'sentInvites']);

        $route('/api/clans/settings',
            [$clanController,'updateSettings']);
        $route('/api/clans/transfer',
            [$clanController,'transferOwnership']);
        $route('/api/clans/disband',
            [$clanController,'disband']);
        $route('/api/clans/invite/revoke',
            [$clanController,'revokeInvite']);
        $route('/api/clans/ban',
            [$clanController,'ban']);
        $route('/api/clans/unban',
            [$clanController,'unban']);
        $route('/api/clans/bans',
            [$clanController,'bans']);

        $route('/api/appearance/batch',[$appearanceController,'batch']);
        $route('/api/clans/quests',[$questController,'quests']);
        $route('/api/clans/quests/create',[$questController,'create']);
        $route('/api/clans/quests/cancel',[$questController,'cancel']);
        $route('/api/clans/quests/contributions',[$questController,'contributions']);
        $route('/api/clans/activity',[$questController,'activity']);
        $route('/api/appearance/my',[$appearanceController,'mine']);
        $route('/api/appearance/save',[$appearanceController,'save']);
        $route('/api/profile/get',[$profileMediaController,'profile']);
        $route('/api/profile/upload',[$profileMediaController,'upload']);
        $route('/api/profile/remove',[$profileMediaController,'remove']);
        $route('/api/profile/image',[$profileMediaController,'image']);

        // GD 2.209: editor audio content with authenticated uploader ownership.
        $route('/getGJEditorSounds', [$editorSounds, 'get']);
        $route('/uploadGJEditorSound', [$editorSounds, 'upload']);
        $route('/downloadGJEditorSound', [$editorSounds, 'download']);
        $route('/deleteGJEditorSound', [$editorSounds, 'delete']);
        $route('/updateGJSoundDesc', [$editorSounds, 'updateDescription']);

        // GD 2.209: per-level death-marker positions and custom controls.
        $route('/getGJDeathData', [$levelTelemetry, 'getDeaths']);
        $route('/uploadGJDeathData', [$levelTelemetry, 'uploadDeath']);
        $route('/getGJLevelControls', [$levelTelemetry, 'getControls']);
        $route('/uploadGJLevelControls', [$levelTelemetry, 'uploadControls']);
        $route('/deleteGJLevelControls', [$levelTelemetry, 'deleteControls']);

        // GD 2.209: private group threads, messages and invitations.
        $route('/getGJThreads', [$threadController, 'getThreads']);
        $route('/getGJThreadMessages', [$threadController, 'getMessages']);
        $route('/getGJThreadMembers', [$threadController, 'getMembers']);
        $route('/getGJThreadInvites', [$threadController, 'getInvites']);
        $route('/sendGJThreadMessage', [$threadController, 'send']);
        $route('/searchGJThreadMessages', [$threadController, 'search']);
        $route('/updateGJThread', [$threadController, 'update']);

        // GD 2.209: authenticated following and mute operations.
        $route('/getGJNotifications', [$notificationController, 'get']);
        $route('/claimGJNotificationReward', [$notificationController, 'claim']);
        $route('/getGJFollowing', [$followingController, 'get']);
        $route('/syncGJFollowing', [$followingController, 'sync']);
        $route('/followGJUser', [$followingController, 'follow']);
        $route('/unfollowGJUser', [$followingController, 'unfollow']);
        $route('/muteGJUser', [$followingController, 'mute']);

        $route('/getGJCreators',
            [$discoveryController,'creators']);
        $route('/getGJCreators19',
            [$discoveryController,'creators']);

        $route('/getGJDailyLevel',
            [$discoveryController,'daily']);

        $route('/getGJGauntlets',
            [$discoveryController,'gauntlets']);
        $route('/getGJGauntlets21',
            [$discoveryController,'gauntlets']);

        $route('/getGJMapPacks',
            [$discoveryController,'mapPacks']);
        $route('/getGJMapPacks20',
            [$discoveryController,'mapPacks']);
        $route('/getGJMapPacks21',
            [$discoveryController,'mapPacks']);

        /*
         * MuchoCore Level Scores v6.1
         * Copyright (C) 2026 IZK
         */

        $route('/getGJLevelScores',
            [$levelScoreController,'regular']);

        $route('/getGJLevelScores211',
            [$levelScoreController,'regular']);

        $route('/getGJLevelScoresPlat',
            [$platformerScoreController,'handle']);

        $route('/getGJRewards',
            [$rewardsController,'getRewards']);
        $route('/getGJSecretReward',
            [$rewardsController,'getSecretReward']);
        $route('/getGJChallenges',
            [$rewardsController,'getChallenges']);

        // Core routes are registered before third-party plugins so plugin
        // routes can never shadow a core protocol endpoint.
        $this->plugins->load();
        $this->plugins->boot();
    }

    public function handle(Request $request): Response
    {
        ClientTrace::captureRequest($request);
        $pluginRequest = $request->forPluginEvent();

        $this->plugins->emit('request.received', [
            'request' => $pluginRequest,
        ]);

        if (($_SERVER['MUCHO_PROTECT_PRECHECKED'] ?? '') !== '1') {
            $protection = $this->protect->inspect(
                $request,
                $this->router->normalizePath($request->path)
            );

            if ($protection['decision'] === 'block') {
                // Keep a successful HTTP transport response for Geometry Dash
                // clients; the legacy protocol uses "-1" as its failure signal.
                $response = Response::text('-1');
                $this->plugins->emit('request.completed', [
                    'request' => $pluginRequest,
                    'response' => $response->forPluginEvent(),
                    'blocked' => true,
                ]);
                ClientTrace::captureResponse($response);
                return $response;
            }
        }

        try {
            $compatibilityProfile = CompatibilityProfile::fromEnvironment();

            if (!$compatibilityProfile->allows($request->clientVersion())) {
                $response = Response::text('-1');
                $this->plugins->emit('request.completed', [
                    'request' => $pluginRequest,
                    'response' => $response->forPluginEvent(),
                    'compatible' => false,
                ]);
                ClientTrace::captureResponse($response);
                return $response;
            }

            $response = $this->router->dispatch($request);
            $this->plugins->emit('request.completed', [
                'request' => $pluginRequest,
                'response' => $response->forPluginEvent(),
                'compatible' => true,
            ]);
            ClientTrace::captureResponse($response);
            return $response;
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore] %s %s | %s: %s | %s:%d',
                (string)($request->method ?? '?'),
                (string)($request->path ?? '?'),
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $response = Response::text('-1');
            $this->plugins->emit('request.failed', [
                'request' => $pluginRequest,
                'error' => $e::class,
                'response' => $response->forPluginEvent(),
            ]);
            ClientTrace::captureResponse($response);
            return $response;
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
