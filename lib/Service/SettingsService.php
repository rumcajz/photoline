<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCA\PhotosTimeline\AppInfo\Application;
use OCP\Files\Folder;
use OCP\IConfig;

/**
 * Per-user settings, stored in ownCloud's user preferences:
 * the folders that make up "My timeline" and the last used filters.
 */
class SettingsService {
	public const DEFAULT_FOLDER = 'CameraUpload';
	public const TYPES = ['all', 'photos', 'videos'];

	/** @var IConfig */
	private $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * @return array{folders:string[], configured:bool, types:string, from:?string, to:?string}
	 */
	public function get(string $userId, ?Folder $userFolder): array {
		$raw = $this->config->getUserValue($userId, Application::APP_ID, 'settings', '');
		$s = \json_decode($raw, true);
		$configured = \is_array($s) && isset($s['folders']) && \is_array($s['folders']);
		if (!$configured) {
			$s = ['folders' => []];
			if ($userFolder !== null && $userFolder->nodeExists(self::DEFAULT_FOLDER)) {
				$s['folders'] = [self::DEFAULT_FOLDER];
			}
		}
		return [
			'folders' => $this->cleanFolders($s['folders']),
			'configured' => $configured,
			'types' => \in_array($s['types'] ?? 'all', self::TYPES, true) ? $s['types'] ?? 'all' : 'all',
			'from' => self::cleanDate($s['from'] ?? null),
			'to' => self::cleanDate($s['to'] ?? null),
		];
	}

	public function save(string $userId, array $folders, string $types, ?string $from, ?string $to): array {
		$s = [
			'folders' => $this->cleanFolders($folders),
			'types' => \in_array($types, self::TYPES, true) ? $types : 'all',
			'from' => self::cleanDate($from),
			'to' => self::cleanDate($to),
		];
		$this->config->setUserValue($userId, Application::APP_ID, 'settings', \json_encode($s));
		return $s + ['configured' => true];
	}

	/**
	 * Normalised relative paths: no leading/trailing slashes, no "." or "..",
	 * no duplicates, at most 200 entries.
	 *
	 * @return string[]
	 */
	public function cleanFolders(array $folders): array {
		$out = [];
		foreach ($folders as $f) {
			$p = self::cleanPath(\is_string($f) ? $f : '');
			if ($p !== null && !\in_array($p, $out, true)) {
				$out[] = $p;
			}
			if (\count($out) >= 200) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A folder path relative to the user's files, or null when invalid.
	 * '' means the whole account.
	 */
	public static function cleanPath(string $p): ?string {
		$p = \trim(\str_replace('\\', '/', $p), '/');
		if (\strlen($p) > 3000 || \strpos($p, "\0") !== false) {
			return null;
		}
		if ($p === '') {
			return '';
		}
		foreach (\explode('/', $p) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return null;
			}
		}
		return $p;
	}

	/**
	 * "YYYY-MM-DD" or null.
	 */
	public static function cleanDate($d): ?string {
		if (!\is_string($d) || !\preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
			return null;
		}
		return \checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
	}
}
