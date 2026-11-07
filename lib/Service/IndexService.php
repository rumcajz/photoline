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
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\ILogger;

/**
 * Keeps the timeline table in step with a user's files. Only what changed is
 * read: new files, files whose content changed (etag), moves and deletions.
 * Files are found through ownCloud's file layer, so the user's own permissions apply.
 */
class IndexService {
	/** @var IRootFolder */
	private $rootFolder;
	/** @var MediaMapper */
	private $mapper;
	/** @var MetadataExtractor */
	private $extractor;
	/** @var ThumbnailStore */
	private $store;
	/** @var IConfig */
	private $config;
	/** @var ILogger */
	private $logger;

	public function __construct(IRootFolder $rootFolder, MediaMapper $mapper, MetadataExtractor $extractor,
								ThumbnailStore $store, IConfig $config, ILogger $logger) {
		$this->rootFolder = $rootFolder;
		$this->mapper = $mapper;
		$this->extractor = $extractor;
		$this->store = $store;
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * @param callable|null $progress fn(string $message)
	 * @return array{skipped:bool, added:int, updated:int, moved:int, removed:int, errors:int, total:int}
	 */
	public function syncUser(string $userId, bool $force = false, ?callable $progress = null): array {
		$result = ['skipped' => false, 'added' => 0, 'updated' => 0, 'moved' => 0, 'removed' => 0, 'errors' => 0, 'total' => 0];
		$userFolder = $this->userFolder($userId);
		if ($userFolder === null) {
			return ['skipped' => true] + $result;
		}

		// The root folder's etag changes whenever anything below it changes.
		$rootEtag = (string)$userFolder->getEtag();
		$lastEtag = $this->config->getUserValue($userId, Application::APP_ID, 'index_etag', '');
		if (!$force && $rootEtag !== '' && $rootEtag === $lastEtag) {
			return ['skipped' => true] + $result;
		}

		$known = $this->mapper->getIndexState($userId);
		$excluded = $this->excludedFolders($userFolder);
		$seen = [];
		$db = $this->mapper->getConnection();
		$batch = 0;
		$db->beginTransaction();
		try {
			foreach (['image', 'video'] as $type) {
				foreach ($userFolder->searchByMime($type) as $node) {
					if (!$node instanceof File) {
						continue;
					}
					$mime = (string)$node->getMimetype();
					$kind = MetadataExtractor::kindForMime($mime);
					if ($kind === null) {
						continue;
					}
					$path = \ltrim((string)$userFolder->getRelativePath($node->getPath()), '/');
					if ($path === '' || $this->isExcluded($path, $excluded)) {
						continue;
					}
					$fileId = (int)$node->getId();
					if (isset($seen[$fileId])) {
						continue;
					}
					$seen[$fileId] = true;
					$etag = \substr((string)$node->getEtag(), 0, 40);
					$old = $known[$fileId] ?? null;

					if ($old !== null && $old['etag'] === $etag) {
						if ($old['path'] !== $path) {
							$this->mapper->updatePath($userId, $fileId, $path, $this->cut($node->getName(), 250));
							$result['moved']++;
						}
						continue;
					}

					try {
						$row = $this->describe($node, $path, $mime, $kind, $etag);
					} catch (\Throwable $e) {
						$result['errors']++;
						$this->logger->info('Photos Timeline could not read ' . $path . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
						continue;
					}
					if ($old === null) {
						$this->mapper->insert($userId, $row + ['file_id' => $fileId]);
						$result['added']++;
					} else {
						$this->mapper->update($userId, $fileId, $row);
						$this->store->delete($userId, $fileId);
						$result['updated']++;
					}
					if (++$batch % 250 === 0) {
						$db->commit();
						$db->beginTransaction();
						if ($progress) {
							$progress(\sprintf('%s: %d new, %d changed so far', $userId, $result['added'], $result['updated']));
						}
					}
				}
			}

			$gone = \array_diff(\array_keys($known), \array_keys($seen));
			if ($gone) {
				$this->mapper->deleteFiles($userId, $gone);
				foreach ($gone as $fileId) {
					$this->store->delete($userId, (int)$fileId);
				}
				$result['removed'] = \count($gone);
			}
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}

		$result['total'] = \count($seen);
		$this->config->setUserValue($userId, Application::APP_ID, 'index_etag', $rootEtag);
		return $result;
	}

	private function describe(File $node, string $path, string $mime, int $kind, string $etag): array {
		$local = $node->getStorage()->getLocalFile($node->getInternalPath());
		if (!\is_string($local) || !\is_file($local)) {
			throw new \RuntimeException('No local copy');
		}
		$meta = $this->extractor->extract($local, $node->getName(), $mime, (int)$node->getMTime());
		return [
			'etag' => $etag,
			'size' => (int)$node->getSize(),
			'path' => $path,
			'name' => $this->cut($node->getName(), 250),
			'mime' => $this->cut($mime, 100),
			'kind' => $kind,
			'taken' => $meta['taken'],
			'date_source' => $meta['date_source'],
			'width' => $meta['width'],
			'height' => $meta['height'],
			'duration' => $meta['duration'],
			'color' => -1,
			// Videos have no thumbnail without a video decoder: shown as a play tile.
			'thumb_state' => $kind === MediaMapper::KIND_VIDEO ? MediaMapper::THUMB_FAILED : MediaMapper::THUMB_PENDING,
		];
	}

	/**
	 * Folders holding a ".nomedia" or ".noimage" file are left out (same convention as Gallery).
	 *
	 * @return string[] relative folder paths
	 */
	private function excludedFolders(Folder $userFolder): array {
		$out = [];
		foreach (['.nomedia', '.noimage'] as $marker) {
			foreach ($userFolder->search($marker) as $node) {
				if ($node->getName() === $marker) {
					$out[] = \ltrim((string)$userFolder->getRelativePath(\dirname($node->getPath())), '/');
				}
			}
		}
		return $out;
	}

	private function isExcluded(string $path, array $excluded): bool {
		foreach ($excluded as $dir) {
			if ($dir === '' || \strpos($path, $dir . '/') === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sets up the file system for a user. Only for background jobs and occ commands:
	 * in a web request the logged-in user's file system is already set up.
	 */
	public function userFolder(string $userId): ?Folder {
		$user = \OC::$server->getUserManager()->get($userId);
		if ($user === null) {
			return null;
		}
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($userId);
		try {
			return $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function cut(string $s, int $len): string {
		return \mb_strlen($s) > $len ? \mb_substr($s, 0, $len) : $s;
	}
}
