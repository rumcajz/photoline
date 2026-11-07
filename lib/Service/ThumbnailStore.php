<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCP\IConfig;

/**
 * Thumbnail files live in the data directory, outside the web root and outside
 * ownCloud's file cache, so they are never served directly and never appear in Files:
 *
 *   <datadirectory>/appdata_photos_timeline/<user>/<2 hex>/<fileid>-<size>.webp
 *
 * They can only be read through the app's controllers, which check the user.
 */
class ThumbnailStore {
	public const SIZE_SMALL = 's';
	public const SIZE_TINY = 't';

	/** @var string */
	private $base;

	public function __construct(IConfig $config) {
		$dataDir = \rtrim((string)$config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data'), '/');
		$this->base = $dataDir . '/appdata_photos_timeline';
	}

	public function getBase(): string {
		return $this->base;
	}

	public function path(string $userId, int $fileId, string $size): string {
		return $this->userDir($userId) . '/' . \sprintf('%02x', $fileId % 256) . '/' . $fileId . '-' . $size . '.webp';
	}

	public function read(string $userId, int $fileId, string $size): ?string {
		$p = $this->path($userId, $fileId, $size);
		if (!\is_file($p)) {
			return null;
		}
		$data = @\file_get_contents($p);
		return \is_string($data) ? $data : null;
	}

	public function write(string $userId, int $fileId, string $size, string $data): void {
		$p = $this->path($userId, $fileId, $size);
		$dir = \dirname($p);
		if (!\is_dir($dir) && !@\mkdir($dir, 0750, true) && !\is_dir($dir)) {
			throw new \RuntimeException('Cannot create thumbnail folder');
		}
		$tmp = $p . '.' . \bin2hex(\random_bytes(4)) . '.part';
		if (@\file_put_contents($tmp, $data) !== \strlen($data) || !@\rename($tmp, $p)) {
			@\unlink($tmp);
			throw new \RuntimeException('Cannot write thumbnail');
		}
	}

	public function delete(string $userId, int $fileId): void {
		foreach ([self::SIZE_SMALL, self::SIZE_TINY] as $s) {
			@\unlink($this->path($userId, $fileId, $s));
		}
	}

	public function deleteUser(string $userId): void {
		$this->removeTree($this->userDir($userId));
	}

	public function deleteAll(): void {
		$this->removeTree($this->base);
	}

	/**
	 * @return array{files:int, bytes:int}
	 */
	public function usage(string $userId): array {
		$dir = $this->userDir($userId);
		$files = 0;
		$bytes = 0;
		if (\is_dir($dir)) {
			$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				$files++;
				$bytes += $f->getSize();
			}
		}
		return ['files' => $files, 'bytes' => $bytes];
	}

	private function userDir(string $userId): string {
		if ($userId === '' || \strpbrk($userId, "/\\\0") !== false || $userId === '.' || $userId === '..') {
			throw new \InvalidArgumentException('Invalid user id');
		}
		return $this->base . '/' . $userId;
	}

	private function removeTree(string $dir): void {
		if (!\is_dir($dir) || \strpos($dir, '/appdata_photos_timeline') === false) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $f) {
			$f->isDir() ? @\rmdir($f->getPathname()) : @\unlink($f->getPathname());
		}
		@\rmdir($dir);
	}
}
