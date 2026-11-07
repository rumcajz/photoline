<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCA\PhotosTimeline\Db\MediaMapper;

/**
 * Reads the capture date, dimensions and (for videos) duration of a file.
 * Only file headers are read: EXIF for photos, the MP4/MOV "moov" box for videos.
 */
class MetadataExtractor {
	public const SOURCE_EXIF = 1;
	public const SOURCE_NAME = 2;
	public const SOURCE_VIDEO = 3;
	public const SOURCE_MTIME = 4;

	/** Photo types the app can make thumbnails for. */
	public const PHOTO_MIMES = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/tiff',
	];

	/** Videos browsers can usually play inline. */
	public const PLAYABLE_VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg'];

	private const MP4_MIMES = ['video/mp4', 'video/quicktime', 'video/3gpp', 'video/x-m4v'];

	public static function kindForMime(string $mime): ?int {
		if (\in_array($mime, self::PHOTO_MIMES, true)) {
			return MediaMapper::KIND_PHOTO;
		}
		if (\strpos($mime, 'video/') === 0) {
			return MediaMapper::KIND_VIDEO;
		}
		return null;
	}

	/**
	 * @param string $localPath readable local file
	 * @return array{taken:int, date_source:int, width:int, height:int, duration:int}
	 */
	public function extract(string $localPath, string $name, string $mime, int $mtime): array {
		$kind = self::kindForMime($mime);
		$meta = ['taken' => 0, 'date_source' => self::SOURCE_MTIME, 'width' => 0, 'height' => 0, 'duration' => 0];

		if ($kind === MediaMapper::KIND_PHOTO) {
			$this->readPhoto($localPath, $mime, $meta);
		} elseif (\in_array($mime, self::MP4_MIMES, true)) {
			$this->readMp4($localPath, $meta);
		}

		if ($meta['taken'] === 0 || $meta['date_source'] === self::SOURCE_VIDEO) {
			// File names written by cameras are local time, better than the UTC video header.
			$fromName = self::dateFromName($name);
			if ($fromName !== null) {
				$meta['taken'] = $fromName;
				$meta['date_source'] = self::SOURCE_NAME;
			}
		}
		if ($meta['taken'] === 0) {
			$meta['taken'] = self::wallClock($mtime);
			$meta['date_source'] = self::SOURCE_MTIME;
		}
		if ($meta['width'] <= 0 || $meta['height'] <= 0) {
			// Unknown shape: a 4:3 tile for photos, 16:9 for videos.
			[$meta['width'], $meta['height']] = $kind === MediaMapper::KIND_VIDEO ? [1920, 1080] : [1600, 1200];
		}
		return $meta;
	}

	private function readPhoto(string $path, string $mime, array &$meta): void {
		$size = @\getimagesize($path);
		if (\is_array($size) && $size[0] > 0 && $size[1] > 0) {
			$meta['width'] = (int)$size[0];
			$meta['height'] = (int)$size[1];
		}
		if ($mime !== 'image/jpeg' && $mime !== 'image/tiff') {
			return;
		}
		$exif = @\exif_read_data($path, 'ANY_TAG', false, false);
		if (!\is_array($exif)) {
			return;
		}
		foreach (['DateTimeOriginal', 'DateTimeDigitized'] as $tag) {
			$ts = isset($exif[$tag]) ? self::parseExifDate((string)$exif[$tag]) : null;
			if ($ts !== null) {
				$meta['taken'] = $ts;
				$meta['date_source'] = self::SOURCE_EXIF;
				break;
			}
		}
		$orientation = (int)($exif['Orientation'] ?? 1);
		if ($orientation >= 5 && $orientation <= 8) {
			[$meta['width'], $meta['height']] = [$meta['height'], $meta['width']];
		}
	}

	/**
	 * "2026:08:06 17:56:14" as wall-clock seconds, or null when missing or invalid.
	 */
	public static function parseExifDate(string $s): ?int {
		if (!\preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', \trim($s), $m)) {
			return null;
		}
		return self::makeTime((int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], (int)$m[6]);
	}

	/**
	 * Dates in common camera and phone file names, e.g. 20261031_152312.jpg,
	 * IMG_20200101_120000.jpg, VID-20190502-WA0003.mp4, 2019-05-02 12.30.00.jpg,
	 * PXL_20230401_101010123.jpg, 1567890123456.jpg (Unix milliseconds).
	 */
	public static function dateFromName(string $name): ?int {
		$base = \pathinfo($name, PATHINFO_FILENAME);
		if (\preg_match('/(?<!\d)((?:19|20)\d{2})[-_.]?(\d{2})[-_.]?(\d{2})[ _\-T.]?(\d{2})[-_.:h]?(\d{2})[-_.:m]?(\d{2})/', $base, $m)) {
			$ts = self::makeTime((int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], (int)$m[6]);
			if ($ts !== null) {
				return $ts;
			}
		}
		if (\preg_match('/(?<!\d)((?:19|20)\d{2})[-_.]?(\d{2})[-_.]?(\d{2})(?!\d)/', $base, $m)) {
			$ts = self::makeTime((int)$m[1], (int)$m[2], (int)$m[3], 12, 0, 0);
			if ($ts !== null) {
				return $ts;
			}
		}
		if (\preg_match('/^1\d{12}$/', $base)) {
			$sec = (int)\substr($base, 0, 10);
			if ($sec >= 946684800 && $sec <= \time() + 86400) {
				return self::wallClock($sec);
			}
		}
		return null;
	}

	private static function makeTime(int $y, int $mo, int $d, int $h, int $mi, int $s): ?int {
		if ($y < 1900 || $y > 2100 || !\checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 60) {
			return null;
		}
		$ts = \gmmktime($h, $mi, \min($s, 59), $mo, $d, $y);
		// Ignore dates in the future (broken camera clocks)
		if ($ts === false || $ts > self::wallClock(\time()) + 2 * 86400) {
			return null;
		}
		return $ts;
	}

	/**
	 * A Unix timestamp as wall-clock seconds in the server's time zone,
	 * so it groups by the same calendar day as EXIF dates.
	 */
	public static function wallClock(int $unix): int {
		try {
			$tz = new \DateTimeZone(\date_default_timezone_get());
			return $unix + $tz->getOffset(new \DateTime('@' . $unix));
		} catch (\Exception $e) {
			return $unix;
		}
	}

	/**
	 * Minimal ISO-BMFF reader: finds moov/mvhd (creation time, duration)
	 * and the video track's tkhd (size and rotation).
	 */
	private function readMp4(string $path, array &$meta): void {
		$fh = @\fopen($path, 'rb');
		if ($fh === false) {
			return;
		}
		try {
			$fileSize = (int)@\filesize($path);
			$moov = $this->findBox($fh, 0, $fileSize, 'moov');
			if ($moov === null || $moov[1] > 16 * 1024 * 1024) {
				return;
			}
			\fseek($fh, $moov[0]);
			$data = \fread($fh, $moov[1]);
			if (!\is_string($data)) {
				return;
			}
			$this->parseMoov($data, $meta);
		} finally {
			\fclose($fh);
		}
	}

	/**
	 * @return array{0:int,1:int}|null [payload offset, payload size]
	 */
	private function findBox($fh, int $start, int $end, string $type): ?array {
		$pos = $start;
		for ($i = 0; $i < 4096 && $pos + 8 <= $end; $i++) {
			\fseek($fh, $pos);
			$hdr = \fread($fh, 16);
			if (!\is_string($hdr) || \strlen($hdr) < 8) {
				return null;
			}
			$size = \unpack('N', \substr($hdr, 0, 4))[1];
			$boxType = \substr($hdr, 4, 4);
			$headerLen = 8;
			if ($size === 1 && \strlen($hdr) >= 16) {
				$hi = \unpack('N', \substr($hdr, 8, 4))[1];
				$lo = \unpack('N', \substr($hdr, 12, 4))[1];
				$size = $hi * 4294967296 + $lo;
				$headerLen = 16;
			} elseif ($size === 0) {
				$size = $end - $pos;
			}
			if ($size < $headerLen) {
				return null;
			}
			if ($boxType === $type) {
				return [$pos + $headerLen, $size - $headerLen];
			}
			$pos += $size;
		}
		return null;
	}

	private function parseMoov(string $moov, array &$meta): void {
		foreach ($this->children($moov) as [$type, $body]) {
			if ($type === 'mvhd' && \strlen($body) >= 20) {
				$version = \ord($body[0]);
				if ($version === 1 && \strlen($body) >= 32) {
					$created = $this->u64(\substr($body, 4, 8));
					$timescale = \unpack('N', \substr($body, 20, 4))[1];
					$duration = $this->u64(\substr($body, 24, 8));
				} else {
					$created = \unpack('N', \substr($body, 4, 4))[1];
					$timescale = \unpack('N', \substr($body, 12, 4))[1];
					$duration = \unpack('N', \substr($body, 16, 4))[1];
				}
				if ($timescale > 0) {
					$meta['duration'] = (int)\round($duration / $timescale);
				}
				// Seconds since 1904-01-01 UTC
				$unix = $created - 2082844800;
				if ($unix > 946684800 && $unix < \time() + 86400) {
					$meta['taken'] = self::wallClock((int)$unix);
					$meta['date_source'] = self::SOURCE_VIDEO;
				}
			} elseif ($type === 'trak') {
				$this->parseTrak($body, $meta);
			}
		}
	}

	private function parseTrak(string $trak, array &$meta): void {
		$tkhd = null;
		$isVideo = false;
		foreach ($this->children($trak) as [$type, $body]) {
			if ($type === 'tkhd') {
				$tkhd = $body;
			} elseif ($type === 'mdia') {
				foreach ($this->children($body) as [$t2, $b2]) {
					if ($t2 === 'hdlr' && \substr($b2, 8, 4) === 'vide') {
						$isVideo = true;
					}
				}
			}
		}
		if (!$isVideo || $tkhd === null || $meta['width'] > 0) {
			return;
		}
		$version = \ord($tkhd[0]);
		$matrixOffset = $version === 1 ? 52 : 40;
		$sizeOffset = $matrixOffset + 36;
		if (\strlen($tkhd) < $sizeOffset + 8) {
			return;
		}
		$w = (int)(\unpack('N', \substr($tkhd, $sizeOffset, 4))[1] / 65536);
		$h = (int)(\unpack('N', \substr($tkhd, $sizeOffset + 4, 4))[1] / 65536);
		// A rotation of 90 or 270 degrees has a zero in the matrix' first cell.
		$a = \unpack('N', \substr($tkhd, $matrixOffset, 4))[1];
		if ($a === 0) {
			[$w, $h] = [$h, $w];
		}
		if ($w > 0 && $h > 0) {
			$meta['width'] = $w;
			$meta['height'] = $h;
		}
	}

	/**
	 * @return \Generator<array{0:string,1:string}>
	 */
	private function children(string $data): \Generator {
		$pos = 0;
		$len = \strlen($data);
		while ($pos + 8 <= $len) {
			$size = \unpack('N', \substr($data, $pos, 4))[1];
			$type = \substr($data, $pos + 4, 4);
			$header = 8;
			if ($size === 1) {
				$size = $this->u64(\substr($data, $pos + 8, 8));
				$header = 16;
			} elseif ($size === 0) {
				$size = $len - $pos;
			}
			if ($size < $header || $pos + $size > $len) {
				return;
			}
			yield [$type, \substr($data, $pos + $header, $size - $header)];
			$pos += $size;
		}
	}

	private function u64(string $b): int {
		$p = \unpack('N2', $b);
		return (int)($p[1] * 4294967296 + $p[2]);
	}
}
