<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\AppInfo;

use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use OCP\AppFramework\App;
use OCP\Util;

class Application extends App {
	public const APP_ID = 'photos_timeline';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	/**
	 * Adds the "Photos Timeline" button to the Files app (next to Gallery's).
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
