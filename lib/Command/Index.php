<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Command;

use OCA\PhotosTimeline\Service\IndexService;
use OCA\PhotosTimeline\Service\ThumbnailService;
use OCA\PhotosTimeline\Service\Worker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Index extends Command {
	/** @var IndexService */
	private $index;
	/** @var ThumbnailService */
	private $thumbs;
	/** @var Worker */
	private $worker;

	public function __construct(IndexService $index, ThumbnailService $thumbs, Worker $worker) {
		parent::__construct();
		$this->index = $index;
		$this->thumbs = $thumbs;
		$this->worker = $worker;
	}

	protected function configure() {
		$this->setName('photoline:index')
			->setAliases(['photos-timeline:index'])
			->setDescription('Update the timeline index (only what changed, unless --force)')
			->addArgument('user', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'User ids (default: all users)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Check every file even if nothing seems to have changed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->thumbs->acquireLock()) {
			$output->writeln('<error>Another PhotoLine run is busy, try again later.</error>');
			return 1;
		}
		try {
			$users = $input->getArgument('user') ?: $this->worker->userIds();
			foreach ($users as $uid) {
				$t = \microtime(true);
				$r = $this->index->syncUser($uid, (bool)$input->getOption('force'), function ($msg) use ($output) {
					$output->writeln('  ' . $msg);
				});
				if ($r['skipped']) {
					$output->writeln(\sprintf('%s: unchanged or unknown user, skipped', $uid));
					continue;
				}
				$output->writeln(\sprintf('%s: %d items, %d new, %d changed, %d moved, %d removed, %d unreadable (%.1fs)',
					$uid, $r['total'], $r['added'], $r['updated'], $r['moved'], $r['removed'], $r['errors'], \microtime(true) - $t));
			}
			return 0;
		} finally {
			$this->thumbs->releaseLock();
		}
	}
}
