<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Service;

/**
 * Makes WebP thumbnails from a local image file with GD (Imagick, when installed,
 * for TIFF and very large photos). EXIF orientation is applied.
 */
class ImageProcessor {
	public const SMALL_HEIGHT = 320;
	public const SMALL_MAX_WIDTH = 1280;
	public const TINY_HEIGHT = 48;
	public const SMALL_QUALITY = 62;
	public const TINY_QUALITY = 40;
	public const LARGE_QUALITY = 80;

	/** Above this many pixels GD is not used (memory), Imagick is. */
	private const GD_MAX_PIXELS = 50000000;

	/**
	 * @return array{s:string, t:string, color:int}
	 */
	public function makeThumbs(string $path, string $mime): array {
		$img = $this->load($path, $mime, self::SMALL_HEIGHT * 2);
		try {
			$small = $this->scaleToHeight($img, self::SMALL_HEIGHT, self::SMALL_MAX_WIDTH);
			$tiny = $this->scaleToHeight($small, self::TINY_HEIGHT, 400);
			$color = $this->averageColor($tiny);
			$out = [
				's' => $this->encode($small, self::SMALL_QUALITY),
				't' => $this->encode($tiny, self::TINY_QUALITY),
				'color' => $color,
			];
			if ($small !== $img) {
				\imagedestroy($small);
			}
			\imagedestroy($tiny);
			return $out;
		} finally {
			\imagedestroy($img);
		}
	}

	/**
	 * A large preview for the full-screen view (longest edge $maxEdge), made on demand.
	 */
	public function makeLarge(string $path, string $mime, int $maxEdge): string {
		$img = $this->load($path, $mime, $maxEdge);
		try {
			$w = \imagesx($img);
			$h = \imagesy($img);
			$scale = \min(1, $maxEdge / \max($w, $h));
			$out = $img;
			if ($scale < 1) {
				$out = $this->resample($img, (int)\max(1, \round($w * $scale)), (int)\max(1, \round($h * $scale)));
			}
			$data = $this->encode($out, self::LARGE_QUALITY);
			if ($out !== $img) {
				\imagedestroy($out);
			}
			return $data;
		} finally {
			\imagedestroy($img);
		}
	}

	/**
	 * @return \GdImage
	 */
	private function load(string $path, string $mime, int $hint) {
		$info = @\getimagesize($path);
		$pixels = \is_array($info) ? $info[0] * $info[1] : 0;
		$useImagick = $mime === 'image/tiff' || $pixels > self::GD_MAX_PIXELS;

		$img = null;
		if (!$useImagick) {
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
		}
		$viaImagick = false;
		if (!$img && \class_exists(\Imagick::class)) {
			$img = $this->loadWithImagick($path, $mime, $hint);
			$viaImagick = true;
		}
		if (!$img) {
			throw new \RuntimeException('Unsupported or broken image');
		}
		if (!\imageistruecolor($img)) {
			\imagepalettetotruecolor($img);
		}
		if ($mime === 'image/jpeg' && !$viaImagick) {
			$img = $this->applyOrientation($img, $path);
		}
		return $img;
	}

	private function loadWithImagick(string $path, string $mime, int $hint) {
		$im = new \Imagick();
		try {
			if ($mime === 'image/jpeg') {
				// Lets libjpeg decode at a reduced scale: much faster and smaller
				$im->setOption('jpeg:size', ($hint * 2) . 'x' . ($hint * 2));
			}
			$im->readImage($path . '[0]');
			if (\method_exists($im, 'autoOrient')) {
				$im->autoOrient();
			}
			$w = $im->getImageWidth();
			$h = $im->getImageHeight();
			$scale = \min(1, ($hint * 2) / \max($w, $h));
			if ($scale < 1) {
				$im->thumbnailImage((int)\max(1, \round($w * $scale)), (int)\max(1, \round($h * $scale)));
			}
			$im->setImageFormat('png');
			$img = @\imagecreatefromstring($im->getImageBlob());
			return $img ?: null;
		} catch (\ImagickException $e) {
			return null;
		} finally {
			$im->clear();
		}
	}

	private function applyOrientation($img, string $path) {
		$exif = @\exif_read_data($path, 'IFD0');
		$o = \is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
		if ($o <= 1 || $o > 8) {
			return $img;
		}
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

	private function scaleToHeight($img, int $height, int $maxWidth) {
		$w = \imagesx($img);
		$h = \imagesy($img);
		$scale = \min(1, $height / $h, $maxWidth / $w);
		if ($scale >= 1) {
			return $img;
		}
		return $this->resample($img, (int)\max(1, \round($w * $scale)), (int)\max(1, \round($h * $scale)));
	}

	private function resample($img, int $w, int $h) {
		$out = \imagecreatetruecolor($w, $h);
		\imagealphablending($out, false);
		\imagesavealpha($out, true);
		\imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, \imagesx($img), \imagesy($img));
		return $out;
	}

	private function averageColor($img): int {
		$px = \imagecreatetruecolor(1, 1);
		\imagecopyresampled($px, $img, 0, 0, 0, 0, 1, 1, \imagesx($img), \imagesy($img));
		$c = \imagecolorat($px, 0, 0) & 0xFFFFFF;
		\imagedestroy($px);
		return $c;
	}

	private function encode($img, int $quality): string {
		\ob_start();
		$ok = \imagewebp($img, null, $quality);
		$data = (string)\ob_get_clean();
		if (!$ok || $data === '') {
			throw new \RuntimeException('WebP encoding failed');
		}
		return $data;
	}
}
