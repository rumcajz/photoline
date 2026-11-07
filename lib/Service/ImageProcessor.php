<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

/**
 * Makes WebP thumbnails from a local image file, with EXIF orientation applied.
 *
 * Imagick is used when available: libjpeg can then decode a JPEG directly at
 * 1/2, 1/4 or 1/8 scale, which is several times faster than decoding the full
 * photo. Without Imagick, GD is used (slower, same result).
 */
class ImageProcessor {
	public const SMALL_HEIGHT = 320;
	public const SMALL_MAX_WIDTH = 1280;
	public const TINY_HEIGHT = 48;
	public const SMALL_QUALITY = 60;
	public const TINY_QUALITY = 40;
	public const LARGE_QUALITY = 80;
	public const LARGE_JPEG_QUALITY = 82;

	/** ImageMagick coder per accepted type; the file is never sniffed for other formats. */
	private const CODERS = [
		'image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
		'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp', 'image/tiff' => 'tiff',
	];

	/** Above this many pixels GD is not used (memory). */
	private const GD_MAX_PIXELS = 50000000;

	/** @var bool */
	private $useImagick;

	public function __construct() {
		$this->useImagick = \class_exists(\Imagick::class) && \in_array('WEBP', \Imagick::queryFormats('WEBP'), true);
	}

	/**
	 * @return array{s:string, t:string, color:int}
	 */
	public function makeThumbs(string $path, string $mime): array {
		if ($this->useImagick && isset(self::CODERS[$mime])) {
			try {
				return $this->thumbsWithImagick($path, $mime);
			} catch (\ImagickException $e) {
				// fall back to GD below
			}
		}
		return $this->thumbsWithGd($path, $mime);
	}

	/**
	 * A large preview for the full-screen view (longest edge about $maxEdge), made on demand.
	 *
	 * Speed matters more than exact size here: resizing a big photo is the slowest
	 * step on a small server, so a JPEG that libjpeg already decoded at up to 1.5x
	 * the wanted size is sent as it is and the browser scales it. JPEG photos are
	 * returned as JPEG (encoding is several times faster than WebP); other types as WebP.
	 *
	 * @return array{data:string, mime:string}
	 */
	public function makeLarge(string $path, string $mime, int $maxEdge): array {
		if ($this->useImagick && isset(self::CODERS[$mime])) {
			try {
				$im = $this->readImagick($path, $mime, $maxEdge, false, 0.8);
				try {
					$long = \max($im->getImageWidth(), $im->getImageHeight());
					if ($long > $maxEdge * 1.5) {
						$this->fitImagick($im, $maxEdge, $maxEdge);
					}
					$im->autoOrient();
					if ($mime === 'image/jpeg') {
						return ['data' => $this->jpegImagick($im, self::LARGE_JPEG_QUALITY), 'mime' => 'image/jpeg'];
					}
					$this->fitImagick($im, $maxEdge, $maxEdge);
					return ['data' => $this->webpImagick($im, self::LARGE_QUALITY), 'mime' => 'image/webp'];
				} finally {
					$im->clear();
				}
			} catch (\ImagickException $e) {
				// fall back to GD below
			}
		}
		$img = $this->loadGd($path, $mime);
		try {
			$out = $this->scaleGd($img, $maxEdge, $maxEdge);
			if ($mime === 'image/jpeg') {
				\ob_start();
				\imagejpeg($out, null, self::LARGE_JPEG_QUALITY);
				$res = ['data' => (string)\ob_get_clean(), 'mime' => 'image/jpeg'];
			} else {
				$res = ['data' => $this->webpGd($out, self::LARGE_QUALITY), 'mime' => 'image/webp'];
			}
			if ($out !== $img) {
				\imagedestroy($out);
			}
			return $res;
		} finally {
			\imagedestroy($img);
		}
	}

	// ------------------------------------------------------------ Imagick

	private function thumbsWithImagick(string $path, string $mime): array {
		$im = $this->readImagick($path, $mime, self::SMALL_HEIGHT, true);
		try {
			// Size first, rotate the small image afterwards (much cheaper).
			$rotated = \in_array($im->getImageOrientation(), [5, 6, 7, 8], true);
			if ($rotated) {
				// stored sideways: its width becomes the displayed height
				$this->fitImagick($im, self::SMALL_HEIGHT, self::SMALL_MAX_WIDTH);
			} else {
				$this->fitImagick($im, self::SMALL_MAX_WIDTH, self::SMALL_HEIGHT);
			}
			$im->autoOrient();
			$small = $this->webpImagick($im, self::SMALL_QUALITY);

			$tiny = clone $im;
			$this->fitImagick($tiny, 400, self::TINY_HEIGHT);
			$tinyData = $this->webpImagick($tiny, self::TINY_QUALITY);

			$tiny->resizeImage(1, 1, \Imagick::FILTER_BOX, 1);
			$c = $tiny->getImagePixelColor(0, 0)->getColor();
			$color = (($c['r'] & 255) << 16) | (($c['g'] & 255) << 8) | ($c['b'] & 255);
			$tiny->clear();
			return ['s' => $small, 't' => $tinyData, 'color' => $color];
		} finally {
			$im->clear();
		}
	}

	/**
	 * @param int $hint the longest edge needed (large view), or for thumbnails
	 *                  ($forHeight) the displayed height needed
	 * @param float $slack accept a decode this much smaller than $hint (e.g. 0.8),
	 *                  so a 4080 px photo decodes at 1/2 instead of full size for 2048
	 */
	private function readImagick(string $path, string $mime, int $hint, bool $forHeight = false, float $slack = 1.0): \Imagick {
		$im = new \Imagick();
		$info = $mime === 'image/jpeg' ? @\getimagesize($path) : false;
		if (\is_array($info) && $info[0] > 0 && $info[1] > 0) {
			// libjpeg decodes at the smallest 1/N scale that is still at least
			// this size in both directions, so ask for exactly what is needed.
			[$w, $h] = $info;
			if ($forHeight) {
				$exif = @\exif_read_data($path, 'IFD0');
				$sideways = \is_array($exif) && \in_array((int)($exif['Orientation'] ?? 1), [5, 6, 7, 8], true);
				$shown = $sideways ? $w : $h;
				$scale = $hint / $shown;
			} else {
				$scale = $hint * $slack / \max($w, $h);
			}
			if ($scale < 1) {
				$im->setOption('jpeg:size', (int)\ceil($w * $scale) . 'x' . (int)\ceil($h * $scale));
			}
		}
		// "coder:path" stops ImageMagick from guessing the format from the content.
		$im->readImage(self::CODERS[$mime] . ':' . $path . '[0]');
		if ($im->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
			$im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		}
		return $im;
	}

	/**
	 * Fits the image into $maxW x $maxH (never enlarges).
	 */
	private function fitImagick(\Imagick $im, int $maxW, int $maxH): void {
		$w = $im->getImageWidth();
		$h = $im->getImageHeight();
		$scale = \min(1, $maxW / $w, $maxH / $h);
		if ($scale < 1) {
			$im->resizeImage((int)\max(1, \round($w * $scale)), (int)\max(1, \round($h * $scale)), \Imagick::FILTER_TRIANGLE, 1);
		}
	}

	private function jpegImagick(\Imagick $im, int $quality): string {
		$im->setImageFormat('jpeg');
		$im->setImageCompressionQuality($quality);
		$im->setOption('jpeg:sampling-factor', '4:2:0');
		$im->stripImage();
		return $im->getImageBlob();
	}

	private function webpImagick(\Imagick $im, int $quality): string {
		$im->setImageFormat('webp');
		$im->setOption('webp:method', '2');
		$im->setImageCompressionQuality($quality);
		$im->stripImage();
		return $im->getImageBlob();
	}

	// ----------------------------------------------------------------- GD

	private function thumbsWithGd(string $path, string $mime): array {
		$img = $this->loadGd($path, $mime);
		try {
			$small = $this->scaleGd($img, self::SMALL_MAX_WIDTH, self::SMALL_HEIGHT);
			$tiny = $this->scaleGd($small, 400, self::TINY_HEIGHT);
			$px = \imagecreatetruecolor(1, 1);
			\imagecopyresampled($px, $tiny, 0, 0, 0, 0, 1, 1, \imagesx($tiny), \imagesy($tiny));
			$color = \imagecolorat($px, 0, 0) & 0xFFFFFF;
			\imagedestroy($px);
			$out = ['s' => $this->webpGd($small, self::SMALL_QUALITY), 't' => $this->webpGd($tiny, self::TINY_QUALITY), 'color' => $color];
			if ($small !== $img) {
				\imagedestroy($small);
			}
			if ($tiny !== $small) {
				\imagedestroy($tiny);
			}
			return $out;
		} finally {
			\imagedestroy($img);
		}
	}

	private function loadGd(string $path, string $mime) {
		$info = @\getimagesize($path);
		if (\is_array($info) && $info[0] * $info[1] > self::GD_MAX_PIXELS) {
			throw new \RuntimeException('Image too large');
		}
		$img = null;
		switch ($mime) {
			case 'image/jpeg':
				$img = @\imagecreatefromjpeg($path);
				break;
			case 'image/png':
				$img = @\imagecreatefrompng($path);
				break;
			case 'image/gif':
				$img = @\imagecreatefromgif($path);
				break;
			case 'image/webp':
				$img = @\imagecreatefromwebp($path);
				break;
			case 'image/bmp':
			case 'image/x-ms-bmp':
				$img = \function_exists('imagecreatefrombmp') ? @\imagecreatefrombmp($path) : null;
				break;
		}
		if (!$img) {
			throw new \RuntimeException('Unsupported or broken image');
		}
		if (!\imageistruecolor($img)) {
			\imagepalettetotruecolor($img);
		}
		return $mime === 'image/jpeg' ? $this->orientGd($img, $path) : $img;
	}

	private function orientGd($img, string $path) {
		$exif = @\exif_read_data($path, 'IFD0');
		$o = \is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
		// imagerotate() turns counter-clockwise: 270 = 90 degrees clockwise.
		$angle = [3 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$o] ?? 0;
		if ($angle !== 0) {
			$rotated = \imagerotate($img, $angle, 0);
			if ($rotated) {
				\imagedestroy($img);
				$img = $rotated;
			}
		}
		if (\in_array($o, [2, 5, 7], true)) {
			\imageflip($img, IMG_FLIP_HORIZONTAL);
		} elseif ($o === 4) {
			\imageflip($img, IMG_FLIP_VERTICAL);
		}
		return $img;
	}

	/**
	 * Halves with a cheap 2x2 filter while the image is much too big, then one
	 * high-quality resample: close to the quality of a single resample, many times faster.
	 */
	private function scaleGd($img, int $maxW, int $maxH) {
		$scale = \min(1, $maxW / \imagesx($img), $maxH / \imagesy($img));
		if ($scale >= 1) {
			return $img;
		}
		$tw = (int)\max(1, \round(\imagesx($img) * $scale));
		$th = (int)\max(1, \round(\imagesy($img) * $scale));
		$src = $img;
		while (\imagesx($src) >= $tw * 4 && \imagesy($src) >= $th * 4) {
			$half = \imagescale($src, \intdiv(\imagesx($src), 2), \intdiv(\imagesy($src), 2), IMG_BILINEAR_FIXED);
			if (!$half) {
				break;
			}
			if ($src !== $img) {
				\imagedestroy($src);
			}
			$src = $half;
		}
		$out = \imagecreatetruecolor($tw, $th);
		\imagealphablending($out, false);
		\imagesavealpha($out, true);
		\imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, \imagesx($src), \imagesy($src));
		if ($src !== $img) {
			\imagedestroy($src);
		}
		return $out;
	}

	private function webpGd($img, int $quality): string {
		\ob_start();
		$ok = \imagewebp($img, null, $quality);
		$data = (string)\ob_get_clean();
		if (!$ok || $data === '') {
			throw new \RuntimeException('WebP encoding failed');
		}
		return $data;
	}
}
