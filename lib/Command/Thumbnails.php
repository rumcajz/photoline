<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Command;

use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailService;
use OCA\PhotosTimeline\Service\Worker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Thumbnails extends Command {
	/** @var ThumbnailService */
	private $thumbs;
	/** @var Worker */
	private $worker;
	/** @var MediaMapper */
	private $mapper;

	public function __construct(ThumbnailService $thumbs, Worker $worker, MediaMapper $mapper) {
		parent::__construct();
		$this->thumbs = $thumbs;
		$this->worker = $worker;
		$this->mapper = $mapper;
	}

	protected function configure() {
		$this->setName('photos-timeline:thumbnails')
			->setDescription('Make missing thumbnails now (by default for each user\'s selected folders)')
			->addArgument('user', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'User ids (default: all users)')
			->addOption('all-folders', null, InputOption::VALUE_NONE, 'All indexed photos, not only the selected folders')
			->addOption('time', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (default: no limit)')
			->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Try photos that failed before again');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->thumbs->acquireLock()) {
			$output->writeln('<error>Another Photos Timeline run is busy, try again later.</error>');
			return 1;
		}
		try {
			$time = $input->getOption('time');
			$deadline = $time ? \microtime(true) + (int)$time : \PHP_INT_MAX;
			$users = $input->getArgument('user') ?: $this->worker->userIds();
			foreach ($users as $uid) {
				if ($input->getOption('retry-failed')) {
					$this->mapper->resetFailedThumbs($uid);
				}
				$t = \microtime(true);
				$r = $this->worker->thumbnailsForUser($uid, (float)$deadline, (bool)$input->getOption('all-folders'), function ($msg) use ($output) {
					$output->writeln('  ' . $msg);
				});
				if ($r === null) {
					$output->writeln("$uid: unknown user, skipped");
					continue;
				}
				$output->writeln(\sprintf('%s: %d made, %d failed%s (%.1fs)', $uid, $r['done'], $r['failed'],
					$r['more'] ? ', more left' : '', \microtime(true) - $t));
			}
			return 0;
		} finally {
			$this->thumbs->releaseLock();
		}
	}
}
