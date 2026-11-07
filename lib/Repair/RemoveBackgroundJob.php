<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Repair;

use OCA\PhotosTimeline\AppInfo\Application;
use OCA\PhotosTimeline\BackgroundJob\TimelineJob;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Runs when the app is disabled: removes its background job, so nothing is
 * left in ownCloud's job list. The index and thumbnails are kept, so enabling
 * the app again is instant; Application::ensureBackgroundJob() adds the job back.
 * `occ photos-timeline:purge --confirm` removes everything.
 */
class RemoveBackgroundJob implements IRepairStep {
	/** @var IJobList */
	private $jobList;
	/** @var IConfig */
	private $config;

	public function __construct(?IJobList $jobList = null, ?IConfig $config = null) {
		$this->jobList = $jobList ?? \OC::$server->getJobList();
		$this->config = $config ?? \OC::$server->getConfig();
	}

	public function getName() {
		return 'Remove the Photos Timeline background job';
	}

	public function run(IOutput $output) {
		$this->jobList->remove(TimelineJob::class);
		$this->config->setAppValue(Application::APP_ID, Application::JOB_FLAG, '0');
	}
	// (Application::removeBackgroundJob() does the same for "occ app:disable",
	// which does not run uninstall steps.)
}
