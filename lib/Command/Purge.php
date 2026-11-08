<?php
/**
 * YearRoll
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Command;

use OCA\PhotosTimeline\AppInfo\Application;
use OCA\PhotosTimeline\BackgroundJob\TimelineJob;
use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailService;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes everything the app ever stored and leaves it disabled and
 * uninstalled, as if it had never been there: the thumbnail cache, the index
 * table, users' settings, the app's settings and its background job.
 * Users' files are never touched. Enabling the app again starts from scratch.
 */
class Purge extends Command {
	/** @var ThumbnailStore */
	private $store;
	/** @var ThumbnailService */
	private $thumbs;
	/** @var IConfig */
	private $config;
	/** @var IDBConnection */
	private $db;
	/** @var IJobList */
	private $jobList;
	/** @var IAppManager */
	private $appManager;

	public function __construct(ThumbnailStore $store, ThumbnailService $thumbs, IConfig $config, IDBConnection $db,
								IJobList $jobList, IAppManager $appManager) {
		parent::__construct();
		$this->store = $store;
		$this->thumbs = $thumbs;
		$this->config = $config;
		$this->db = $db;
		$this->jobList = $jobList;
		$this->appManager = $appManager;
	}

	protected function configure() {
		$this->setName('yearroll:purge')
			->setAliases(['photoline:purge', 'photos-timeline:purge'])
			->setDescription('Remove all YearRoll data (index table, thumbnails, settings, background job) and disable the app. Files are not touched.')
			->addOption('confirm', null, InputOption::VALUE_NONE, 'Required: confirms the purge');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('confirm')) {
			$output->writeln('Nothing done. Run again with --confirm to remove all YearRoll data and disable the app.');
			return 1;
		}
		if (!$this->thumbs->acquireLock()) {
			$output->writeln('A YearRoll run is busy. Try again in a few minutes.');
			return 1;
		}
		try {
			// 1. stop: no more background runs, app off for everyone
			$this->jobList->remove(TimelineJob::class);
			if ($this->appManager->isInstalled(Application::APP_ID)) {
				$this->appManager->disableApp(Application::APP_ID);
			}
			$output->writeln('App disabled, background job removed.');

			// 2. data
			$this->store->deleteAll();
			$output->writeln('Thumbnail cache deleted.');
			$this->config->deleteAppFromAllUsers(Application::APP_ID);
			$output->writeln('User settings deleted.');
			$table = MediaMapper::TABLE;
			if ($this->db->tableExists($table)) {
				$this->db->dropTable($table);
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)));
			$qb->execute();
			$output->writeln('Index table dropped.');

			// 3. the app's own settings, including "installed" and "enabled"
			$this->config->deleteAppValues(Application::APP_ID);
			$output->writeln('App settings deleted.');
		} finally {
			$this->thumbs->releaseLock();
		}
		$output->writeln('Done. Users\' files were not touched. Delete the app folder to remove the app,');
		$output->writeln('or enable it again to start from scratch.');
		return 0;
	}
}
