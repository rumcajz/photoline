<?php
/**
 * YearRoll
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\AppInfo;

use OCA\PhotosTimeline\BackgroundJob\TimelineJob;
use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use OCP\App\ManagerEvent;
use OCP\AppFramework\App;
use OCP\Util;

class Application extends App {
	public const APP_ID = 'photos_timeline';
	/** App value: '0' after the app was disabled (its background job was removed). */
	public const JOB_FLAG = 'job_registered';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	/**
	 * ownCloud adds an app's background jobs only when the app is installed,
	 * not when it is enabled again. Disabling removes the job (repair step
	 * RemoveBackgroundJob), so it is added back here on the first load after
	 * re-enabling. Costs nothing otherwise (app values are cached).
	 */
	public function ensureBackgroundJob(): void {
		// "occ app:disable" skips the uninstall repair steps, so the disable
		// event is used as well (both remove the job; doing it twice is harmless).
		\OC::$server->getEventDispatcher()->addListener(ManagerEvent::EVENT_APP_DISABLE, function ($event) {
			if ($event instanceof ManagerEvent && $event->getAppID() === self::APP_ID) {
				self::removeBackgroundJob();
			}
		});
		$config = \OC::$server->getConfig();
		if ($config->getAppValue(self::APP_ID, self::JOB_FLAG, '1') === '0') {
			\OC::$server->getJobList()->add(TimelineJob::class);
			$config->setAppValue(self::APP_ID, self::JOB_FLAG, '1');
		}
	}

	/**
	 * Called when the app is disabled. Its index and thumbnails stay.
	 */
	public static function removeBackgroundJob(): void {
		\OC::$server->getJobList()->remove(TimelineJob::class);
		\OC::$server->getConfig()->setAppValue(self::APP_ID, self::JOB_FLAG, '0');
	}

	/**
	 * Adds the "YearRoll" button to the Files app (next to Gallery's).
	 * The scripts are only loaded on the Files page itself.
	 */
	public function registerFilesIntegration(): void {
		$dispatcher = \OC::$server->getEventDispatcher();
		$dispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
			Util::addScript(self::APP_ID, 'files-button');
			Util::addStyle(self::APP_ID, 'files-button');
		});
	}

	/**
	 * When a user is deleted, their index rows and thumbnail cache go too.
	 * Failures are logged and never block the deletion itself.
	 */
	public function registerUserCleanup(): void {
		$container = $this->getContainer();
		\OC::$server->getUserManager()->listen('\OC\User', 'postDelete', function ($user) use ($container) {
			try {
				$uid = $user->getUID();
				$container->query(MediaMapper::class)->deleteUser($uid);
				$container->query(ThumbnailStore::class)->deleteUser($uid);
			} catch (\Throwable $e) {
				\OC::$server->getLogger()->logException($e, ['app' => self::APP_ID]);
			}
		});
	}
}
