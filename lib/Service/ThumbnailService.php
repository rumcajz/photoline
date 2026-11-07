<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCA\PhotosTimeline\AppInfo\Application;
use OCA\PhotosTimeline\Db\MediaMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ILogger;

/**
 * Makes the small and tiny thumbnails for indexed photos and videos.
 *
 * Videos: with ffmpeg on the server a frame is taken here; without it the
 * video waits (state "from playback") until a user plays it in the timeline
 * and the browser sends the first frame it showed.
 */
class ThumbnailService {
	/** @var MediaMapper */
	private $mapper;
	/** @var ThumbnailStore */
	private $store;
	/** @var ImageProcessor */
	private $processor;
	/** @var ILogger */
	private $logger;
	/** @var resource|null */
	private $lock;

	/** @var ViewCache */
	private $views;
	/** @var VideoFrames */
	private $frames;

	public function __construct(MediaMapper $mapper, ThumbnailStore $store, ImageProcessor $processor, ILogger $logger,
								ViewCache $views, VideoFrames $frames) {
		$this->views = $views;
		$this->frames = $frames;
		$this->mapper = $mapper;
		$this->store = $store;
		$this->processor = $processor;
		$this->logger = $logger;
	}

	/**
	 * Only one background run at a time (cron job or occ command).
	 */
	public function acquireLock(): bool {
		$base = $this->store->getBase();
		if (!\is_dir($base)) {
			@\mkdir($base, 0750, true);
		}
		$fh = @\fopen($base . '/.lock', 'c');
		if ($fh === false) {
			return false;
		}
		if (!\flock($fh, LOCK_EX | LOCK_NB)) {
			\fclose($fh);
			return false;
		}
		$this->lock = $fh;
		return true;
	}

	public function releaseLock(): void {
		if ($this->lock) {
			\flock($this->lock, LOCK_UN);
			\fclose($this->lock);
			$this->lock = null;
		}
	}

	/**
	 * Works through pending thumbnails until the deadline.
	 *
	 * @param string[]|null $prefixes only these folders, null = all
	 * @return array{done:int, failed:int, more:bool}
	 */
	public function processBacklog(string $userId, Folder $userFolder, ?array $prefixes, float $deadline, ?callable $progress = null): array {
		$out = ['done' => 0, 'failed' => 0, 'more' => false];
		try {
			return $this->backlog($userId, $userFolder, $prefixes, $deadline, $progress, $out);
		} finally {
			// new thumbnails bring new placeholder colours: refresh cached views
			if ($out['done'] > 0) {
				$this->views->bump($userId);
			}
		}
	}

	private function backlog(string $userId, Folder $userFolder, ?array $prefixes, float $deadline, ?callable $progress, array &$out): array {
		$this->mapper->failStuckThumbs($userId);
		$this->mapper->switchVideoStates($userId, $this->frames->available());
		while (\microtime(true) < $deadline) {
			$rows = $this->mapper->findPendingThumbs($userId, $prefixes, 50);
			if (!$rows) {
				return $out;
			}
			foreach ($rows as $row) {
				if (\microtime(true) >= $deadline) {
					$out['more'] = true;
					return $out;
				}
				$this->generate($userId, $userFolder, $row) ? $out['done']++ : $out['failed']++;
				if ($out['done'] > 0 && $out['done'] % 1000 === 0) {
					$this->views->bump($userId);
				}
				if ($progress && ($out['done'] + $out['failed']) % 100 === 0) {
					$progress(\sprintf('%s: %d thumbnails made, %d failed', $userId, $out['done'], $out['failed']));
				}
			}
		}
		$out['more'] = (bool)$this->mapper->findPendingThumbs($userId, $prefixes, 1);
		return $out;
	}

	/**
	 * Makes both thumbnail sizes for one row. The row is marked "being made"
	 * first, so a crash on a broken file marks it failed on the next run.
	 */
	public function generate(string $userId, Folder $userFolder, array $row): bool {
		$fileId = (int)$row['file_id'];
		$video = (int)$row['kind'] === MediaMapper::KIND_VIDEO;
		if ($video && !$this->frames->available()) {
			$this->mapper->setThumbState($userId, $fileId, MediaMapper::THUMB_FROM_PLAYBACK);
			return false;
		}
		$this->mapper->setThumbState($userId, $fileId, MediaMapper::THUMB_WORKING);
		$frame = null;
		try {
			$nodes = $userFolder->getById($fileId, true);
			$node = $nodes[0] ?? null;
			if (!$node instanceof File) {
				throw new \RuntimeException('File not found');
			}
			$local = $node->getStorage()->getLocalFile($node->getInternalPath());
			if (!\is_string($local) || !\is_file($local)) {
				throw new \RuntimeException('No local copy');
			}
			if ($video) {
				$frame = $this->frames->frame($local, (int)($row['duration'] ?? 0));
				if ($frame === null) {
					throw new \RuntimeException('ffmpeg could not read a frame');
				}
				$t = $this->processor->makeThumbs($frame, 'image/jpeg');
			} else {
				$t = $this->processor->makeThumbs($local, (string)$node->getMimetype());
			}
			$this->save($userId, $fileId, $t);
			return true;
		} catch (\Throwable $e) {
			// a video ffmpeg cannot read may still get a frame from a browser
			$this->mapper->setThumbState($userId, $fileId, $video ? MediaMapper::THUMB_FROM_PLAYBACK : MediaMapper::THUMB_FAILED);
			$this->logger->info('Photos Timeline: no thumbnail for file ' . $fileId . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return false;
		} finally {
			if ($frame !== null) {
				@\unlink($frame);
			}
		}
	}

	/**
	 * Stores a video frame sent by the user's browser (already checked to be a
	 * small JPEG, PNG or WebP). It is decoded and encoded again, never stored as sent.
	 */
	public function fromPlayback(string $userId, int $fileId, string $imagePath, string $mime): bool {
		try {
			$this->save($userId, $fileId, $this->processor->makeThumbs($imagePath, $mime));
			$this->views->bump($userId);
			return true;
		} catch (\Throwable $e) {
			$this->logger->info('Photos Timeline: frame for video ' . $fileId . ' not usable: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return false;
		}
	}

	private function save(string $userId, int $fileId, array $t): void {
		$this->store->write($userId, $fileId, ThumbnailStore::SIZE_SMALL, $t['s']);
		$this->store->write($userId, $fileId, ThumbnailStore::SIZE_TINY, $t['t']);
		$this->mapper->setThumbState($userId, $fileId, MediaMapper::THUMB_READY, $t['color']);
	}

	public function ffmpegAvailable(): bool {
		return $this->frames->available();
	}
}
