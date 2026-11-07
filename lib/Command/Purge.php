<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Command;

use OCA\PhotosTimeline\AppInfo\Application;
use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes everything the app stored (index rows, thumbnail cache, user settings),
 * for example before removing the app. Users' files are never touched.
 */
class Purge extends Command {
	/** @var MediaMapper */
	private $mapper;
	/** @var ThumbnailStore */
	private $store;
	/** @var IConfig */
	private $config;

	public function __construct(MediaMapper $mapper, ThumbnailStore $store, IConfig $config) {
		parent::__construct();
		$this->mapper = $mapper;
		$this->store = $store;
		$this->config = $config;
	}

	protected function configure() {
		$this->setName('photos-timeline:purge')
			->setDescription('Delete the timeline index, thumbnail cache and settings of all users (files are not touched)')
			->addOption('confirm', null, InputOption::VALUE_NONE, 'Required: confirms the purge');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('confirm')) {
			$output->writeln('Nothing done. Run again with --confirm to delete the index, thumbnails and settings.');
			return 1;
		}
		$this->mapper->deleteAll();
		$this->store->deleteAll();
		$this->config->deleteAppFromAllUsers(Application::APP_ID);
		$output->writeln('Photos Timeline data removed. The now empty table <prefix>photos_timeline can be dropped after the app is removed (see README).');
		return 0;
	}
}
