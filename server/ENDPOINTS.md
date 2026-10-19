# GD 2.209 endpoint implementations

Source: MuchoCore commit `856a864745462ceac687d8963ebb1a7ed82e4002`. All 52 routes are registered in [Application.php](src/Core/Application.php).

| HTTP entrypoint | Actual handler |
| --- | --- |
| [/database/getGJEditorSounds.php](public/database/getGJEditorSounds.php) | [Music\EditorSoundController::get](src/Music/EditorSoundController.php) |
| [/database/uploadGJEditorSound.php](public/database/uploadGJEditorSound.php) | [Music\EditorSoundController::upload](src/Music/EditorSoundController.php) |
| [/database/downloadGJEditorSound.php](public/database/downloadGJEditorSound.php) | [Music\EditorSoundController::download](src/Music/EditorSoundController.php) |
| [/database/deleteGJEditorSound.php](public/database/deleteGJEditorSound.php) | [Music\EditorSoundController::delete](src/Music/EditorSoundController.php) |
| [/database/updateGJSoundDesc.php](public/database/updateGJSoundDesc.php) | [Music\EditorSoundController::updateDescription](src/Music/EditorSoundController.php) |
| [/database/getGJLevelControls.php](public/database/getGJLevelControls.php) | [Level\LevelTelemetryController::getControls](src/Level/LevelTelemetryController.php) |
| [/database/uploadGJLevelControls.php](public/database/uploadGJLevelControls.php) | [Level\LevelTelemetryController::uploadControls](src/Level/LevelTelemetryController.php) |
| [/database/deleteGJLevelControls.php](public/database/deleteGJLevelControls.php) | [Level\LevelTelemetryController::deleteControls](src/Level/LevelTelemetryController.php) |
| [/database/getGJDeathData.php](public/database/getGJDeathData.php) | [Level\LevelTelemetryController::getDeaths](src/Level/LevelTelemetryController.php) |
| [/database/uploadGJDeathData.php](public/database/uploadGJDeathData.php) | [Level\LevelTelemetryController::uploadDeath](src/Level/LevelTelemetryController.php) |
| [/database/getGJThreads.php](public/database/getGJThreads.php) | [Social\ThreadController::getThreads](src/Social/ThreadController.php) |
| [/database/getGJThreadMessages.php](public/database/getGJThreadMessages.php) | [Social\ThreadController::getMessages](src/Social/ThreadController.php) |
| [/database/getGJThreadMembers.php](public/database/getGJThreadMembers.php) | [Social\ThreadController::getMembers](src/Social/ThreadController.php) |
| [/database/getGJThreadInvites.php](public/database/getGJThreadInvites.php) | [Social\ThreadController::getInvites](src/Social/ThreadController.php) |
| [/database/sendGJThreadMessage.php](public/database/sendGJThreadMessage.php) | [Social\ThreadController::send](src/Social/ThreadController.php) |
| [/database/updateGJThread.php](public/database/updateGJThread.php) | [Social\ThreadController::update](src/Social/ThreadController.php) |
| [/database/searchGJThreadMessages.php](public/database/searchGJThreadMessages.php) | [Social\ThreadController::search](src/Social/ThreadController.php) |
| [/database/claimGJNotificationReward.php](public/database/claimGJNotificationReward.php) | [Social\NotificationController::claim](src/Social/NotificationController.php) |
| [/database/getGJNotifications.php](public/database/getGJNotifications.php) | [Social\NotificationController::get](src/Social/NotificationController.php) |
| [/database/muteGJUser.php](public/database/muteGJUser.php) | [Social\FollowingController::mute](src/Social/FollowingController.php) |
| [/database/reportGJContent.php](public/database/reportGJContent.php) | [Moderation\Gd2209CaseController::reportContent](src/Moderation/Gd2209CaseController.php) |
| [/database/followGJUser.php](public/database/followGJUser.php) | [Social\FollowingController::follow](src/Social/FollowingController.php) |
| [/database/unfollowGJUser.php](public/database/unfollowGJUser.php) | [Social\FollowingController::unfollow](src/Social/FollowingController.php) |
| [/database/getGJFollowing.php](public/database/getGJFollowing.php) | [Social\FollowingController::get](src/Social/FollowingController.php) |
| [/database/syncGJFollowing.php](public/database/syncGJFollowing.php) | [Social\FollowingController::sync](src/Social/FollowingController.php) |
| [/database/getGJSongUsage.php](public/database/getGJSongUsage.php) | [Music\SongUsageController::get](src/Music/SongUsageController.php) |
| [/database/getGJUserSearch.php](public/database/getGJUserSearch.php) | [User\UserController::search209](src/User/UserController.php) |
| [/database/getGJAudioFilesize.php](public/database/getGJAudioFilesize.php) | [Compatibility\Gd2209UtilityController::audioSize](src/Compatibility/Gd2209UtilityController.php) |
| [/database/getGJContentStatus.php](public/database/getGJContentStatus.php) | [Compatibility\Gd2209UtilityController::contentStatus](src/Compatibility/Gd2209UtilityController.php) |
| [/database/openedRegister.php](public/database/openedRegister.php) | [Compatibility\Gd2209UtilityController::openedRegister](src/Compatibility/Gd2209UtilityController.php) |
| [/database/adminGetUserHistory.php](public/database/adminGetUserHistory.php) | [Moderation\Gd2209CaseController::userHistory](src/Moderation/Gd2209CaseController.php) |
| [/database/adminGetReportedContent.php](public/database/adminGetReportedContent.php) | [Moderation\Gd2209CaseController::reportedContent](src/Moderation/Gd2209CaseController.php) |
| [/database/adminClearReportedContent.php](public/database/adminClearReportedContent.php) | [Moderation\Gd2209CaseController::clearReportedContent](src/Moderation/Gd2209CaseController.php) |
| [/database/adminGetReportQueue.php](public/database/adminGetReportQueue.php) | [Moderation\ReportQueueController::queue](src/Moderation/ReportQueueController.php) |
| [/database/adminGetAbuseInfo.php](public/database/adminGetAbuseInfo.php) | [Moderation\Gd2209CaseController::abuseInfo](src/Moderation/Gd2209CaseController.php) |
| [/database/adminGetAccountReview.php](public/database/adminGetAccountReview.php) | [Moderation\Gd2209CaseController::accountReview](src/Moderation/Gd2209CaseController.php) |
| [/database/adminThreadAction.php](public/database/adminThreadAction.php) | [Moderation\Gd2209ContentActionController::threadAction](src/Moderation/Gd2209ContentActionController.php) |
| [/database/adminDeleteUserComments.php](public/database/adminDeleteUserComments.php) | [Moderation\Gd2209ContentActionController::deleteUser](src/Moderation/Gd2209ContentActionController.php) |
| [/database/adminCommentBan.php](public/database/adminCommentBan.php) | [Moderation\Gd2209SanctionController::apply (comment, true)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminUploadBan.php](public/database/adminUploadBan.php) | [Moderation\Gd2209SanctionController::apply (upload, true)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminMessageBan.php](public/database/adminMessageBan.php) | [Moderation\Gd2209SanctionController::apply (message, true)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminFreezeAccount.php](public/database/adminFreezeAccount.php) | [Moderation\Gd2209SanctionController::apply (freeze, true)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminTrendingBan.php](public/database/adminTrendingBan.php) | [Moderation\Gd2209SanctionController::apply (trending, true)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminNameLock.php](public/database/adminNameLock.php) | [Moderation\Gd2209SanctionController::apply (name, true)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminCommentUnban.php](public/database/adminCommentUnban.php) | [Moderation\Gd2209SanctionController::apply (comment, false)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminUploadUnban.php](public/database/adminUploadUnban.php) | [Moderation\Gd2209SanctionController::apply (upload, false)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminCloseCase.php](public/database/adminCloseCase.php) | [Moderation\Gd2209CaseController::closeCase](src/Moderation/Gd2209CaseController.php) |
| [/database/adminMessageUnban.php](public/database/adminMessageUnban.php) | [Moderation\Gd2209SanctionController::apply (message, false)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminUnfreezeAccount.php](public/database/adminUnfreezeAccount.php) | [Moderation\Gd2209SanctionController::apply (freeze, false)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminTrendingUnban.php](public/database/adminTrendingUnban.php) | [Moderation\Gd2209SanctionController::apply (trending, false)](src/Moderation/Gd2209SanctionController.php) |
| [/database/adminNameUnlock.php](public/database/adminNameUnlock.php) | [Moderation\Gd2209SanctionController::apply (name, false)](src/Moderation/Gd2209SanctionController.php) |
| [/database/deleteGJComments.php](public/database/deleteGJComments.php) | [Moderation\Gd2209ContentActionController::deleteOwn](src/Moderation/Gd2209ContentActionController.php) |
