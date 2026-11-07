<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

use OCP\IConfig;

/**
 * Takes one frame from a video with ffmpeg, when the server has ffmpeg.
 *
 * ffmpeg is never shipped with the app. It is used when ownCloud's own
 * "preview_ffmpeg_path" setting points to it, or when it is on the PATH.
 * Without it, video thumbnails come from the browser of a user who plays the
 * video (see ApiController::videoFrame).
 */
class VideoFrames {
	/** Seconds one ffmpeg run may take before it is stopped. */
	private const TIMEOUT = 30;

	/** @var IConfig */
	private $config;
	/** @var string|false|null */
	private $binary = null;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * The ffmpeg executable, or null when it cannot be used.
	 */
	public function binary(): ?string {
		if ($this->binary === null) {
			$this->binary = $this->find() ?? false;
		}
		return $this->binary === false ? null : $this->binary;
	}

	public function available(): bool {
		return $this->binary() !== null;
	}

	private function find(): ?string {
		if (!\function_exists('proc_open') || \in_array('proc_open', \array_map('trim', \explode(',', (string)\ini_get('disable_functions'))), true)) {
			return null;
		}
		$candidates = [];
		$configured = $this->config->getSystemValue('preview_ffmpeg_path', '');
		if (\is_string($configured) && $configured !== '') {
			$candidates[] = $configured;
		}
		foreach (\explode(PATH_SEPARATOR, (string)\getenv('PATH')) as $dir) {
			if ($dir !== '') {
				$candidates[] = \rtrim($dir, '/') . '/ffmpeg';
			}
		}
		$candidates[] = '/usr/bin/ffmpeg';
		$candidates[] = '/usr/local/bin/ffmpeg';
		foreach ($candidates as $c) {
			if (@\is_file($c) && @\is_executable($c)) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Writes one frame (JPEG, at most 1280x640) to a new temporary file and
	 * returns its path; the caller deletes it. Null when no frame could be made.
	 *
	 * @param int $duration in seconds, 0 if unknown
	 */
	public function frame(string $videoPath, int $duration): ?string {
		$bin = $this->binary();
		if ($bin === null || !\is_file($videoPath)) {
			return null;
		}
		$out = \tempnam(\sys_get_temp_dir(), 'ptl-frame-');
		if ($out === false) {
			return null;
		}
		$jpg = $out . '.jpg';
		@\unlink($out);
		// A frame one second in avoids the black first frame of many cameras;
		// short or unknown-length videos use the very first frame.
		foreach ($duration >= 2 ? ['1', '0'] : ['0'] as $at) {
			$ok = $this->run([
				$bin, '-nostdin', '-hide_banner', '-loglevel', 'error',
				'-ss', $at, '-i', $videoPath,
				'-frames:v', '1', '-an', '-sn',
				'-vf', 'scale=w=1280:h=640:force_original_aspect_ratio=decrease',
				'-q:v', '3', '-f', 'image2', '-y', $jpg,
			]);
			if ($ok && @\filesize($jpg) > 0) {
				return $jpg;
			}
		}
		@\unlink($jpg);
		return null;
	}

	/**
	 * Runs a command (no shell) with a time limit.
	 */
	private function run(array $cmd): bool {
		$null = \DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL';
		$proc = @\proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
		if (!\is_resource($proc)) {
			return false;
		}
		$end = \microtime(true) + self::TIMEOUT;
		while (true) {
			$st = \proc_get_status($proc);
			if (!$st['running']) {
				\proc_close($proc);
				return $st['exitcode'] === 0;
			}
			if (\microtime(true) > $end) {
				\proc_terminate($proc, 9);
				\proc_close($proc);
				return false;
			}
			\usleep(20000);
		}
	}
}
