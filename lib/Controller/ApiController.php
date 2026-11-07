<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Controller;

use OCA\PhotosTimeline\Db\MediaMapper;
use OCA\PhotosTimeline\Service\ImageProcessor;
use OCA\PhotosTimeline\Service\SettingsService;
use OCA\PhotosTimeline\Service\ThumbnailService;
use OCA\PhotosTimeline\Service\ThumbnailStore;
use OCA\PhotosTimeline\Service\ViewCache;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Every method here needs a logged-in user and a valid CSRF token (ownCloud's
 * defaults: no @PublicPage, no @NoCSRFRequired). The user id always comes from
 * the session, and every file id is checked against that user's own index and
 * file system before anything is returned.
 */
class ApiController extends Controller {
	private const MAX_IDS = ['s' => 60, 't' => 300];
	private const ON_DEMAND_SECONDS = 2.5;
	private const LARGE_EDGE = 2048;
	private const LONG_CACHE = 'private, max-age=31536000, immutable';

	/** @var IUserSession */
	private $userSession;
	/** @var IRootFolder */
	private $rootFolder;
	/** @var MediaMapper */
	private $mapper;
	/** @var ThumbnailStore */
	private $store;
	/** @var ThumbnailService */
	private $thumbs;
	/** @var ImageProcessor */
	private $processor;
	/** @var SettingsService */
	private $settings;
	/** @var ViewCache */
	private $views;

	public function __construct(string $AppName, IRequest $request, IUserSession $userSession, IRootFolder $rootFolder,
								MediaMapper $mapper, ThumbnailStore $store, ThumbnailService $thumbs,
								ImageProcessor $processor, SettingsService $settings, ViewCache $views) {
		parent::__construct($AppName, $request);
		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
		$this->mapper = $mapper;
		$this->store = $store;
		$this->thumbs = $thumbs;
		$this->processor = $processor;
		$this->settings = $settings;
		$this->views = $views;
	}

	/**
	 * The timeline of "My timeline" (scope=mine) or of one folder and its subfolders.
	 *
	 * @NoAdminRequired
	 */
	public function items(string $scope = 'mine', string $folder = '', string $types = 'all', string $from = '', string $to = ''): Response {
		[$uid, $userFolder] = $this->current();

		$dedupe = false;
		if ($scope === 'mine') {
			$prefixes = $this->settings->get($uid, $userFolder)['folders'];
			$dedupe = true;
		} else {
			$path = SettingsService::cleanPath($folder);
			if ($path === null || ($path !== '' && !$this->isFolder($userFolder, $path))) {
				return new JSONResponse(['error' => 'Folder not found'], Http::STATUS_NOT_FOUND);
			}
			$prefixes = [$path];
		}
		$kinds = $types === 'photos' ? [MediaMapper::KIND_PHOTO]
			: ($types === 'videos' ? [MediaMapper::KIND_VIDEO] : [MediaMapper::KIND_PHOTO, MediaMapper::KIND_VIDEO]);
		$fromDate = SettingsService::cleanDate($from);
		$toDate = SettingsService::cleanDate($to);
		$fromTs = $fromDate !== null ? \gmmktime(0, 0, 0, (int)\substr($fromDate, 5, 2), (int)\substr($fromDate, 8, 2), (int)\substr($fromDate, 0, 4)) : null;
		$toTs = $toDate !== null ? \gmmktime(23, 59, 59, (int)\substr($toDate, 5, 2), (int)\substr($toDate, 8, 2), (int)\substr($toDate, 0, 4)) : null;

		$cacheKey = $this->views->key($uid, [$scope === 'mine', $prefixes, $kinds, $fromTs, $toTs]);
		$cached = $this->views->get($uid, $cacheKey);
		if ($cached !== null) {
			return $this->gzipResponse($cached);
		}

		$rows = $this->mapper->findForTimeline($uid, $prefixes, $kinds, $fromTs, $toTs);
		$also = [];
		if ($dedupe) {
			[$rows, $also] = $this->dedupe($rows, $prefixes);
		}

		$dirs = [];
		$dirIndex = [];
		$mimes = [];
		$mimeIndex = [];
		$items = [];
		foreach ($rows as $r) {
			$path = $r['path'];
			$slash = \strrpos($path, '/');
			$dir = $slash === false ? '' : \substr($path, 0, $slash);
			if (!isset($dirIndex[$dir])) {
				$dirIndex[$dir] = \count($dirs);
				$dirs[] = $dir;
			}
			if (!isset($mimeIndex[$r['mime']])) {
				$mimeIndex[$r['mime']] = \count($mimes);
				$mimes[] = $r['mime'];
			}
			$fileId = (int)$r['file_id'];
			$item = [
				$fileId,
				(int)$r['taken'],
				(int)$r['width'],
				(int)$r['height'],
				(int)$r['kind'],
				(int)$r['color'],
				$dirIndex[$dir],
				$r['name'],
				\substr(\md5($r['etag']), 0, 10),
				(int)$r['duration'],
				(int)$r['thumb_state'],
				$mimeIndex[$r['mime']],
			];
			if (isset($also[$fileId])) {
				$item[] = \array_values(\array_unique($also[$fileId]));
			}
			$items[] = $item;
		}

		$gz = \gzencode(\json_encode([
			'v' => 1,
			'scope' => $scope === 'mine' ? 'mine' : 'folder',
			'folders' => $prefixes,
			'dirs' => $dirs,
			'mimes' => $mimes,
			'items' => $items,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 6);
		$this->views->put($uid, $cacheKey, $gz);
		return $this->gzipResponse($gz);
	}

	/**
	 * A pack of thumbnails: "PTL1", uint32 header length, JSON header
	 * [[fileId, byteLength], ...], then the WebP images back to back.
	 * Ids that are not the user's are left out; length 0 means "not available".
	 *
	 * @NoAdminRequired
	 */
	public function thumbs(string $ids = '', string $s = 's'): Response {
		[$uid, $userFolder] = $this->current();
		$size = $s === 't' ? ThumbnailStore::SIZE_TINY : ThumbnailStore::SIZE_SMALL;
		$wanted = \array_slice(\array_values(\array_unique(\array_filter(\array_map('intval', \explode(',', $ids)), function ($i) {
			return $i > 0;
		}))), 0, self::MAX_IDS[$size]);

		$rows = $this->mapper->findByIds($uid, $wanted);
		$header = [];
		$body = '';
		$complete = true;
		$deadline = \microtime(true) + self::ON_DEMAND_SECONDS;
		foreach ($wanted as $fileId) {
			$row = $rows[$fileId] ?? null;
			if ($row === null || !$this->canRead($userFolder, $fileId)) {
				continue;
			}
			$state = (int)$row['thumb_state'];
			$data = $state === MediaMapper::THUMB_READY ? $this->store->read($uid, $fileId, $size) : null;
			if ($data === null && $state !== MediaMapper::THUMB_FAILED && (int)$row['kind'] === MediaMapper::KIND_PHOTO) {
				if (\microtime(true) < $deadline && $this->thumbs->generate($uid, $userFolder, $row)) {
					$data = $this->store->read($uid, $fileId, $size);
				} else {
					$complete = false;
				}
			}
			$header[] = [$fileId, $data === null ? 0 : \strlen($data)];
			$body .= $data ?? '';
		}
		$json = \json_encode($header);
		$resp = new DataDisplayResponse('PTL1' . \pack('N', \strlen($json)) . $json . $body, Http::STATUS_OK, [
			'Content-Type' => 'application/octet-stream',
			'X-Content-Type-Options' => 'nosniff',
		]);
		$resp->addHeader('Cache-Control', $complete ? self::LONG_CACHE : 'no-store');
		return $resp;
	}

	/**
	 * A large WebP preview for the full-screen view, made on demand (not stored).
	 *
	 * @NoAdminRequired
	 */
	public function large(int $fileId, int $w = 2048): Response {
		// a few fixed sizes, so browser caching works and requests stay cheap
		$edge = $w <= 1024 ? 1024 : ($w <= 1600 ? 1600 : self::LARGE_EDGE);
		[$uid, $userFolder] = $this->current();
		$rows = $this->mapper->findByIds($uid, [$fileId]);
		$row = $rows[$fileId] ?? null;
		if ($row === null || (int)$row['kind'] !== MediaMapper::KIND_PHOTO) {
			return $this->notFound();
		}
		$node = $this->readableFile($userFolder, $fileId);
		if ($node === null) {
			return $this->notFound();
		}
		try {
			$local = $node->getStorage()->getLocalFile($node->getInternalPath());
			if (!\is_string($local)) {
				return $this->notFound();
			}
			$data = $this->processor->makeLarge($local, (string)$node->getMimetype(), $edge);
		} catch (\Throwable $e) {
			return $this->notFound();
		}
		$resp = new DataDisplayResponse($data, Http::STATUS_OK, [
			'Content-Type' => 'image/webp',
			'X-Content-Type-Options' => 'nosniff',
		]);
		$resp->addHeader('Cache-Control', self::LONG_CACHE);
		return $resp;
	}

	/**
	 * Subfolders of a folder, with the number of indexed photos and videos below
	 * each, for the folder picker.
	 *
	 * @NoAdminRequired
	 */
	public function folders(string $path = ''): Response {
		[$uid, $userFolder] = $this->current();
		$clean = SettingsService::cleanPath($path);
		if ($clean === null) {
			return new JSONResponse(['error' => 'Invalid path'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$folder = $clean === '' ? $userFolder : $userFolder->get($clean);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'Folder not found'], Http::STATUS_NOT_FOUND);
		}
		if (!$folder instanceof Folder) {
			return new JSONResponse(['error' => 'Folder not found'], Http::STATUS_NOT_FOUND);
		}

		$counts = [];
		$prefix = $clean === '' ? '' : $clean . '/';
		foreach ($this->mapper->findForTimeline($uid, [$clean], [MediaMapper::KIND_PHOTO, MediaMapper::KIND_VIDEO], null, null) as $r) {
			$rest = \substr($r['path'], \strlen($prefix));
			$slash = \strpos($rest, '/');
			if ($slash !== false) {
				$child = \substr($rest, 0, $slash);
				$counts[$child] = ($counts[$child] ?? 0) + 1;
			}
		}
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$out[] = ['name' => $node->getName(), 'path' => $prefix . $node->getName(), 'media' => $counts[$node->getName()] ?? 0];
			}
		}
		\usort($out, function ($a, $b) {
			return \strnatcasecmp($a['name'], $b['name']);
		});
		return $this->json(['path' => $clean, 'folders' => $out]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getSettings(): Response {
		[$uid, $userFolder] = $this->current();
		return $this->json($this->settings->get($uid, $userFolder));
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param mixed $folders
	 */
	public function setSettings($folders = null, string $types = 'all', ?string $from = null, ?string $to = null): Response {
		[$uid, $userFolder] = $this->current();
		if (!\is_array($folders)) {
			$folders = $this->settings->get($uid, $userFolder)['folders'];
		}
		$existing = [];
		foreach ($this->settings->cleanFolders($folders) as $f) {
			if ($f === '' || $this->isFolder($userFolder, $f)) {
				$existing[] = $f;
			}
		}
		return $this->json($this->settings->save($uid, $existing, $types, $from, $to));
	}

	/**
	 * @return array{0:string, 1:Folder}
	 */
	private function current(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			// Cannot happen behind ownCloud's login check; refuse anyway.
			throw new \RuntimeException('Not logged in');
		}
		$uid = $user->getUID();
		return [$uid, $this->rootFolder->getUserFolder($uid)];
	}

	private function isFolder(Folder $userFolder, string $path): bool {
		try {
			return $userFolder->get($path) instanceof Folder;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * The file must still be reachable and readable in the user's own file system.
	 */
	private function canRead(Folder $userFolder, int $fileId): bool {
		return $this->readableFile($userFolder, $fileId) !== null;
	}

	private function readableFile(Folder $userFolder, int $fileId): ?File {
		try {
			foreach ($userFolder->getById($fileId) as $node) {
				if ($node instanceof File && $node->isReadable()) {
					return $node;
				}
			}
		} catch (\Throwable $e) {
		}
		return null;
	}

	/**
	 * Duplicates (same name and size) are shown once, from the folder listed first.
	 *
	 * @return array{0:array, 1:array} [rows, fileId => other folders]
	 */
	private function dedupe(array $rows, array $prefixes): array {
		$rank = function (string $path) use ($prefixes): int {
			foreach ($prefixes as $i => $p) {
				if ($p === '' || \strpos($path, $p . '/') === 0) {
					return $i;
				}
			}
			return \PHP_INT_MAX;
		};
		$best = [];
		foreach ($rows as $i => $r) {
			$key = \mb_strtolower($r['name']) . '|' . $r['size'];
			$r['_rank'] = $rank($r['path']);
			if (!isset($best[$key])) {
				$best[$key] = ['i' => $i, 'rank' => $r['_rank'], 'path' => $r['path'], 'others' => []];
				continue;
			}
			$b = &$best[$key];
			if ($r['_rank'] < $b['rank'] || ($r['_rank'] === $b['rank'] && \strcmp($r['path'], $b['path']) < 0)) {
				$b['others'][] = \dirname($b['path']);
				$b['i'] = $i;
				$b['rank'] = $r['_rank'];
				$b['path'] = $r['path'];
			} else {
				$b['others'][] = \dirname($r['path']);
			}
			unset($b);
		}
		$keep = [];
		$also = [];
		foreach ($best as $b) {
			$keep[$b['i']] = true;
			if ($b['others']) {
				$also[(int)$rows[$b['i']]['file_id']] = \array_map(function ($d) {
					return $d === '.' ? '' : $d;
				}, $b['others']);
			}
		}
		$out = [];
		foreach ($rows as $i => $r) {
			if (isset($keep[$i])) {
				$out[] = $r;
			}
		}
		return [$out, $also];
	}

	/**
	 * JSON, gzip-compressed when the browser accepts it (the server does not compress JSON).
	 */
	private function json(array $data): Response {
		$json = \json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$headers = ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Accept-Encoding'];
		if (\strlen($json) > 2048 && \stripos((string)$this->request->getHeader('Accept-Encoding'), 'gzip') !== false && \function_exists('gzencode')) {
			$json = \gzencode($json, 5);
			$headers['Content-Encoding'] = 'gzip';
		}
		return new DataDisplayResponse($json, Http::STATUS_OK, $headers);
	}

	/**
	 * Sends already gzip-compressed JSON (decompressed for the rare client without gzip).
	 */
	private function gzipResponse(string $gz): Response {
		$headers = ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Accept-Encoding'];
		if (\stripos((string)$this->request->getHeader('Accept-Encoding'), 'gzip') !== false) {
			$headers['Content-Encoding'] = 'gzip';
			return new DataDisplayResponse($gz, Http::STATUS_OK, $headers);
		}
		return new DataDisplayResponse((string)\gzdecode($gz), Http::STATUS_OK, $headers);
	}

	private function notFound(): Response {
		$r = new Response();
		$r->setStatus(Http::STATUS_NOT_FOUND);
		return $r;
	}
}
