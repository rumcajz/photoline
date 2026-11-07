<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCA\PhotosTimeline\AppInfo\Application;
use OCP\IConfig;

/**
 * Keeps the finished, gzip-compressed timeline response of each view, so that
 * opening a timeline again does not rebuild it. Entries are per user, stored
 * next to the user's thumbnails, and keyed by the view's parameters plus a
 * per-user "generation" number that is raised whenever the index or the
 * thumbnails change.
 */
class ViewCache {
	private const KEEP = 24;

	/** @var ThumbnailStore */
	private $store;
	/** @var IConfig */
	private $config;

	public function __construct(ThumbnailStore $store, IConfig $config) {
		$this->store = $store;
		$this->config = $config;
	}

	public function generation(string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'gen', '0');
	}

	public function bump(string $userId): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'gen', (string)((int)$this->generation($userId) + 1));
	}

	public function key(string $userId, array $params): string {
		return \sha1(\json_encode([2, $this->generation($userId), $params]));
	}

	public function get(string $userId, string $key): ?string {
		$f = $this->file($userId, $key);
		if (!\is_file($f)) {
			return null;
		}
		$data = @\file_get_contents($f);
		return \is_string($data) && $data !== '' ? $data : null;
	}

	public function put(string $userId, string $key, string $gz): void {
		$dir = $this->dir($userId);
		if (!\is_dir($dir) && !@\mkdir($dir, 0750, true) && !\is_dir($dir)) {
			return;
		}
		$tmp = $this->file($userId, $key) . '.' . \bin2hex(\random_bytes(4)) . '.part';
		if (@\file_put_contents($tmp, $gz) === \strlen($gz)) {
			@\rename($tmp, $this->file($userId, $key));
		} else {
			@\unlink($tmp);
		}
		// keep only the most recent views
		$files = \glob($dir . '/*.json.gz') ?: [];
		if (\count($files) > self::KEEP) {
			\usort($files, function ($a, $b) {
				return (int)@\filemtime($a) <=> (int)@\filemtime($b);
			});
			foreach (\array_slice($files, 0, \count($files) - self::KEEP) as $old) {
				@\unlink($old);
			}
		}
	}

	private function dir(string $userId): string {
		// reuses the thumbnail store's validated per-user folder
		return \dirname($this->store->path($userId, 0, 'x'), 2) . '/views';
	}

	private function file(string $userId, string $key): string {
		return $this->dir($userId) . '/' . $key . '.json.gz';
	}
}
