<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Command;

use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use OCA\PhotosTimeline\Service\VideoFrames;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	/** @var MediaMapper */
	private $mapper;
	/** @var ThumbnailStore */
	private $store;

	/** @var VideoFrames */
	private $frames;
	/** @var IConfig */
	private $config;

	public function __construct(MediaMapper $mapper, ThumbnailStore $store, VideoFrames $frames, IConfig $config) {
		parent::__construct();
		$this->frames = $frames;
		$this->config = $config;
		$this->mapper = $mapper;
		$this->store = $store;
	}

	protected function configure() {
		$this->setName('photoline:status')
			->setAliases(['photos-timeline:status'])
			->setDescription('Show index and thumbnail counts per user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mode = $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
		$output->writeln('Background jobs: ' . $mode . ($mode === 'cron' ? '' : ' (cron is recommended: thumbnails are made much more slowly otherwise)'));
		$bin = $this->frames->binary();
		$output->writeln('Video thumbnails: ' . ($bin !== null ? 'ffmpeg (' . $bin . ')' : 'no ffmpeg found, taken from the first frame when a video is played'));
		foreach ($this->mapper->getUsers() as $uid) {
			$s = $this->mapper->stats($uid);
			$u = $this->store->usage($uid);
			$output->writeln(\sprintf('%s: %d items (%d photos, %d videos); thumbnails %d ready, %d pending, %d waiting for playback, %d not possible; cache %.1f MB',
				$uid, $s['total'], $s['photos'], $s['videos'], $s['ready'], $s['pending'], $s['playback'], $s['failed'], $u['bytes'] / 1048576));
		}
		return 0;
	}
}
