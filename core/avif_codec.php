<?php
/**
 * AVIF encode / decode with GD, falling back to Imagick (ImageMagick) when GD has no AVIF support.
 *
 * GD is always preferred. Imagick is used only for the AVIF step: images are still decoded, rotated and resized
 * with GD, an AVIF target is handed to Imagick as a lossless PNG, an AVIF source is read by Imagick and handed
 * back to GD as PNG. Whatever Imagick writes is checked (non-empty, "ftypavif" brand), otherwise it counts as failed.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\core;

class avif_codec
{
	/** @var bool|null Cached result of the Imagick self-test */
	protected static $imagick_enc = null;
	protected static $imagick_dec = null;

	public static function gd_encode()
	{
		return function_exists('imageavif');
	}

	public static function gd_decode()
	{
		return function_exists('imagecreatefromavif');
	}

	/**
	 * Imagick can WRITE AVIF: a tiny test image is encoded and the result is checked. (Listing "AVIF" in
	 * queryFormats() is not enough, some ImageMagick builds only read it.)
	 */
	public static function imagick_encode()
	{
		if (self::$imagick_enc === null)
		{
			self::$imagick_enc = false;
			if (class_exists('Imagick') && function_exists('imagecreatetruecolor') && function_exists('imagepng'))
			{
				$img = @imagecreatetruecolor(16, 16);
				$tmp = @tempnam(sys_get_temp_dir(), 'avt');
				if ($img && $tmp)
				{
					self::$imagick_enc = self::encode_imagick($img, $tmp, 50);
					@unlink($tmp);
				}
			}
		}
		return self::$imagick_enc;
	}

	/** Imagick can READ AVIF (ImageMagick built with libheif / libavif). */
	public static function imagick_decode()
	{
		if (self::$imagick_dec === null)
		{
			self::$imagick_dec = false;
			if (class_exists('Imagick'))
			{
				try
				{
					self::$imagick_dec = in_array('AVIF', \Imagick::queryFormats('AVIF'), true);
				}
				catch (\Throwable $e)
				{
				}
			}
		}
		return self::$imagick_dec;
	}

	public static function supported()
	{
		return (self::gd_encode() || self::imagick_encode()) && (self::gd_decode() || self::imagick_decode());
	}

	/** Which engine does the work, for diagnostics: "GD", "Imagick", "GD + Imagick" or "". */
	public static function engine()
	{
		if (!self::supported())
		{
			return '';
		}
		$e = [];
		if (self::gd_encode() && self::gd_decode())
		{
			return 'GD';
		}
		if (self::gd_encode() || self::gd_decode())
		{
			$e[] = 'GD';
		}
		if ((!self::gd_encode() && self::imagick_encode()) || (!self::gd_decode() && self::imagick_decode()))
		{
			$e[] = 'Imagick';
		}
		return implode(' + ', $e);
	}

	/**
	 * @param resource|\GdImage $img GD image (true colour, alpha kept)
	 * @return bool
	 */
	public static function encode($img, $file, $quality)
	{
		if (self::gd_encode())
		{
			return (bool) @imageavif($img, $file, (int) $quality);
		}
		return self::encode_imagick($img, $file, (int) $quality);
	}

	/**
	 * @return resource|\GdImage|false GD image
	 */
	public static function decode($file)
	{
		if (self::gd_decode())
		{
			return @imagecreatefromavif($file);
		}
		return self::decode_imagick($file);
	}

	public static function encode_imagick($img, $file, $quality)
	{
		if (!class_exists('Imagick') || !function_exists('imagepng'))
		{
			return false;
		}
		try
		{
			ob_start();
			@imagepng($img, null, 1);
			$png = ob_get_clean();
			if ($png === false || $png === '')
			{
				return false;
			}
			$im = new \Imagick();
			$im->readImageBlob($png);
			$im->stripImage();
			$im->setImageFormat('avif');
			$im->setImageCompressionQuality((int) $quality);
			if ($quality >= 90)
			{
				// best effort: ask the encoder for full-resolution colour; builds that do not know the option ignore it
				$im->setOption('heic:chroma', '444');
			}
			$ok = $im->writeImage($file);
			$im->clear();
			if (!$ok)
			{
				@unlink($file);
				return false;
			}
			clearstatcache(true, $file);
			$head = (string) @file_get_contents($file, false, null, 0, 16);
			if ((int) @filesize($file) <= 0 || substr($head, 4, 8) !== 'ftypavif')
			{
				@unlink($file);
				return false;
			}
			return true;
		}
		catch (\Throwable $e)
		{
			if (ob_get_level() > 0)
			{
				@ob_end_clean();
			}
			@unlink($file);
			return false;
		}
	}

	public static function decode_imagick($file)
	{
		if (!class_exists('Imagick') || !function_exists('imagecreatefromstring'))
		{
			return false;
		}
		try
		{
			$im = new \Imagick();
			$im->readImage($file . '[0]');
			$im->setImageFormat('png');
			$blob = $im->getImageBlob();
			$im->clear();
			$img = @imagecreatefromstring($blob);
			if ($img !== false)
			{
				imagealphablending($img, false);
				imagesavealpha($img, true);
			}
			return $img;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}
