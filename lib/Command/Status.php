<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Command;

use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	/** @var MediaMapper */
	private $mapper;
	/** @var ThumbnailStore */
	private $store;

	public function __construct(MediaMapper $mapper, ThumbnailStore $store) {
		parent::__construct();
		$this->mapper = $mapper;
		$this->store = $store;
	}

	protected function configure() {
		$this->setName('photos-timeline:status')
			->setDescription('Show index and thumbnail counts per user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		foreach ($this->mapper->getUsers() as $uid) {
			$s = $this->mapper->stats($uid);
			$u = $this->store->usage($uid);
			$output->writeln(\sprintf('%s: %d items (%d photos, %d videos); thumbnails %d ready, %d pending, %d not possible; cache %.1f MB',
				$uid, $s['total'], $s['photos'], $s['videos'], $s['ready'], $s['pending'], $s['failed'], $u['bytes'] / 1048576));
		}
		return 0;
	}
}
