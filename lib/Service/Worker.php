<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCA\PhotosTimeline\AppInfo\Application;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;

/**
 * The background work shared by the cron job and the occ commands.
 *
 * App settings (occ config:app:set photos_timeline <key> --value=...):
 *   night_start / night_end   hours of the "night" window (default 1 and 6)
 *   budget_night / budget_day seconds of thumbnail work per run (default 240 and 30)
 */
class Worker {
	/** @var IndexService */
	private $index;
	/** @var ThumbnailService */
	private $thumbs;
	/** @var SettingsService */
	private $settings;
	/** @var IUserManager */
	private $userManager;
	/** @var IConfig */
	private $config;
	/** @var ILogger */
	private $logger;

	public function __construct(IndexService $index, ThumbnailService $thumbs, SettingsService $settings,
								IUserManager $userManager, IConfig $config, ILogger $logger) {
		$this->index = $index;
		$this->thumbs = $thumbs;
		$this->settings = $settings;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
	}

	public function runScheduled(): void {
		if (!$this->thumbs->acquireLock()) {
			return; // another run (or an occ command) is busy
		}
		try {
			$hour = (int)\date('G');
			$start = (int)$this->config->getAppValue(Application::APP_ID, 'night_start', '1');
			$end = (int)$this->config->getAppValue(Application::APP_ID, 'night_end', '6');
			$night = $start <= $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
			$budget = (int)$this->config->getAppValue(Application::APP_ID, $night ? 'budget_night' : 'budget_day', $night ? '240' : '30');
			$deadline = \microtime(true) + \max(5, $budget);

			foreach ($this->userIds() as $uid) {
				try {
					$this->index->syncUser($uid);
				} catch (\Throwable $e) {
					$this->logger->logException($e, ['app' => Application::APP_ID]);
				}
			}
			foreach ($this->userIds() as $uid) {
				if (\microtime(true) >= $deadline) {
					break;
				}
				try {
					$this->thumbnailsForUser($uid, $deadline, false);
				} catch (\Throwable $e) {
					$this->logger->logException($e, ['app' => Application::APP_ID]);
				}
			}
		} finally {
			$this->thumbs->releaseLock();
		}
	}

	/**
	 * @return array{done:int, failed:int, more:bool}|null null if the user does not exist
	 */
	public function thumbnailsForUser(string $uid, float $deadline, bool $allFolders, ?callable $progress = null): ?array {
		$folder = $this->index->userFolder($uid);
		if ($folder === null) {
			return null;
		}
		$prefixes = $allFolders ? null : $this->settings->get($uid, $folder)['folders'];
		return $this->thumbs->processBacklog($uid, $folder, $prefixes, $deadline, $progress);
	}

	/**
	 * @return string[]
	 */
	public function userIds(): array {
		$ids = [];
		$this->userManager->callForSeenUsers(function (IUser $u) use (&$ids) {
			if ($u->isEnabled()) {
				$ids[] = $u->getUID();
			}
		});
		return $ids;
	}
}
