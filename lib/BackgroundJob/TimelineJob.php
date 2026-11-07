<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\BackgroundJob;

use OC\BackgroundJob\TimedJob;
use OCA\PhotosTimeline\AppInfo\Application;
use OCA\PhotosTimeline\Service\Worker;

/**
 * Every 5 minutes: brings each user's index up to date (cheap when nothing
 * changed) and makes thumbnails for their selected folders, within a time
 * budget that is larger at night.
 */
class TimelineJob extends TimedJob {
	public function __construct() {
		$this->setInterval(300);
	}

	protected function run($argument) {
		$app = new Application();
		/** @var Worker $worker */
		$worker = $app->getContainer()->query(Worker::class);
		$worker->runScheduled();
	}
}
