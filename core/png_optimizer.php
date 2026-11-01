<?php
/**
 * Lossless PNG optimizer. Pixels are never changed: every result is checked against the original
 * (decompressed scanlines, and for re-encoded results a pixel-by-pixel comparison) and thrown away
 * on any difference.
 *
 * What it tries:
 *  1. the original scanlines packed with the best deflate settings (several strategies);
 *  2. when GD can read the image exactly: a palette image if it has no more than 256 colours,
 *     dropping an alpha channel that is fully opaque, and every PNG scanline filter.
 * Metadata is removed, colour chunks (ICC profile, sRGB, gamma, chromaticities, cICP) are kept.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\core;

class png_optimizer
{
	/** Larger files / images are left alone (time and memory). */
	const MAX_BYTES = 4194304;
	const MAX_PIXELS = 6000000;
	/** A result has to be at least this much smaller than the original. */
	const MIN_GAIN = 16;
	/** Seconds after which no further variants are tried. */
	const TIME_BUDGET = 6;

	/** Moment after which no further variants are tried (set by optimize()). */
	protected static $deadline = 0;

	/** Ancillary chunks that decide how colours look; they stay. */
	protected static $keep = ['iCCP', 'sRGB', 'gAMA', 'cHRM', 'cICP', 'mDCV', 'cLLI'];

	public static function supported()
	{
		return function_exists('deflate_init') && function_exists('gzuncompress') && function_exists('crc32');
	}

	/**
	 * @return string|false the optimized PNG file, or false when nothing smaller (and identical) was found
	 */
	public static function optimize($raw)
	{
		if (!self::supported() || !is_string($raw) || strlen($raw) > self::MAX_BYTES)
		{
			return false;
		}
		$t0 = microtime(true);
		self::$deadline = $t0 + self::TIME_BUDGET;

		$png = self::parse($raw);
		if (!$png || $png['animated'])
		{
			return false;
		}
		if ($png['w'] * $png['h'] > self::MAX_PIXELS || $png['w'] < 1 || $png['h'] < 1)
		{
			return false;
		}

		// scanlines (unpacked) are held in memory next to the file itself and the packed copies
		$unpacked = $png['h'] * (1 + (int) ceil($png['w'] * self::channels($png['ct']) * $png['bit'] / 8));
		if (!self::fits_in_memory($unpacked * 4 + strlen($raw) * 2))
		{
			return false;
		}

		$data = @gzuncompress($png['idat']);
		if ($data === false)
		{
			return false;
		}

		$best = false;
		$best_size = strlen($raw) - self::MIN_GAIN;

		// 1. same scanlines, better deflate
		$cand = self::assemble($png['ihdr'], $png['keep'], $png['plte'], $png['trns'], $data);
		if ($cand !== false && strlen($cand) <= $best_size && self::same_scanlines($cand, $data))
		{
			$best = $cand;
			$best_size = strlen($cand) - 1;
		}

		// 2. re-encoded through GD (palette, no useless alpha, other filters)
		// GD holds the picture as 4 bytes per pixel, several copies of it at once
		if (function_exists('imagecreatefromstring') && function_exists('imagepng') && function_exists('imagecompare')
			&& microtime(true) - $t0 < self::TIME_BUDGET
			&& self::fits_in_memory($png['w'] * $png['h'] * 4 * 4 + $unpacked * 3 + strlen($raw)))
		{
			$cand = self::reencode($png, $raw, $data, $best_size, $t0);
			if ($cand !== false && strlen($cand) <= $best_size)
			{
				$best = $cand;
			}
		}

		return $best;
	}

	/**
	 * True when both PNG files show exactly the same pixels (as GD reads them).
	 */
	public static function same_pixels($a, $b)
	{
		if (!function_exists('imagecreatefromstring') || !function_exists('imagecompare'))
		{
			return true; // nothing to compare with; optimize() has checked the scanlines itself
		}
		$ia = @imagecreatefromstring($a);
		$ib = @imagecreatefromstring($b);
		if ($ia === false || $ib === false)
		{
			return false;
		}
		return self::images_equal($ia, $ib);
	}

	protected static function channels($ct)
	{
		return [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct] ?? 4;
	}

	/** Refuse what would not fit into the PHP memory limit (a fatal error cannot be caught). */
	protected static function fits_in_memory($need)
	{
		$limit = ini_get('memory_limit');
		if ($limit === '-1' || $limit === false || $limit === '')
		{
			return true;
		}
		$bytes = (int) $limit;
		switch (strtolower(substr($limit, -1)))
		{
			case 'g': $bytes *= 1024;
			case 'm': $bytes *= 1024;
			case 'k': $bytes *= 1024;
		}
		return ($need + memory_get_usage()) < ($bytes * 0.8);
	}

	/* ------------------------------------------------------------ parsing */

	protected static function parse($raw)
	{
		if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n")
		{
			return false;
		}
		$len = strlen($raw);
		$pos = 8;
		$png = ['ihdr' => '', 'plte' => '', 'trns' => '', 'idat' => '', 'keep' => '', 'animated' => false, 'iend' => false];
		$seen = [];
		while ($pos + 12 <= $len)
		{
			$n = unpack('N', substr($raw, $pos, 4))[1];
			$type = substr($raw, $pos + 4, 4);
			if ($pos + 12 + $n > $len)
			{
				return false;
			}
			$body = substr($raw, $pos + 8, $n);
			$whole = substr($raw, $pos, 12 + $n);
			$pos += 12 + $n;

			if ($type === 'IHDR')
			{
				if ($n !== 13)
				{
					return false;
				}
				$png['ihdr'] = $body;
			}
			else if ($type === 'PLTE')
			{
				$png['plte'] = $body;
			}
			else if ($type === 'tRNS')
			{
				$png['trns'] = $body;
			}
			else if ($type === 'IDAT')
			{
				$png['idat'] .= $body;
			}
			else if ($type === 'acTL' || $type === 'fcTL' || $type === 'fdAT')
			{
				$png['animated'] = true;
			}
			else if (in_array($type, self::$keep, true) && !isset($seen[$type]))
			{
				$seen[$type] = true;
				$png['keep'] .= $whole;
			}
			else if ($type === 'IEND')
			{
				$png['iend'] = true;
				break;
			}
		}
		if ($png['ihdr'] === '' || $png['idat'] === '' || !$png['iend'])
		{
			return false;
		}
		$h = unpack('Nw/Nh/Cbit/Cct/Ccm/Cfm/Cil', $png['ihdr']);
		if ($h['cm'] !== 0 || $h['fm'] !== 0)
		{
			return false;
		}
		$png += ['w' => $h['w'], 'h' => $h['h'], 'bit' => $h['bit'], 'ct' => $h['ct'], 'il' => $h['il']];
		return $png;
	}

	protected static function chunk($type, $body)
	{
		return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
	}

	/**
	 * Pack uncompressed scanlines with the smallest of several deflate settings and build the file.
	 */
	protected static function assemble($ihdr, $keep, $plte, $trns, $scanlines)
	{
		$best = false;
		$strategies = [ZLIB_DEFAULT_STRATEGY, ZLIB_FILTERED, ZLIB_RLE];
		foreach ($strategies as $s)
		{
			if ($best !== false && microtime(true) > self::$deadline)
			{
				break;
			}
			$ctx = @deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9, 'memory' => 9, 'window' => 15, 'strategy' => $s]);
			if ($ctx === false)
			{
				continue;
			}
			$z = @deflate_add($ctx, $scanlines, ZLIB_FINISH);
			if ($z !== false && ($best === false || strlen($z) < strlen($best)))
			{
				$best = $z;
			}
		}
		if ($best === false)
		{
			return false;
		}
		$out = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $ihdr) . $keep;
		if ($plte !== '')
		{
			$out .= self::chunk('PLTE', $plte);
		}
		if ($trns !== '')
		{
			$out .= self::chunk('tRNS', $trns);
		}
		return $out . self::chunk('IDAT', $best) . self::chunk('IEND', '');
	}

	protected static function same_scanlines($file, $expected)
	{
		$p = self::parse($file);
		return $p && @gzuncompress($p['idat']) === $expected;
	}

	/* ------------------------------------------------------------ GD re-encoding */

	protected static function reencode($png, $raw, $data, $best_size, $t0)
	{
		$ct = $png['ct'];

		// only what GD reads exactly: up to 8 bits per channel, no colour-key transparency
		if ($png['bit'] > 8)
		{
			return false;
		}
		if (($ct === 0 || $ct === 2) && $png['trns'] !== '')
		{
			return false;
		}
		if ($ct === 3 && $png['trns'] !== '' && !self::binary_trns($png['trns']))
		{
			return false;
		}
		$opaque = false;
		if ($ct === 4 || $ct === 6)
		{
			if ($png['bit'] !== 8 || $png['il'] !== 0)
			{
				return false;
			}
			$a = self::alpha_class($data, $png['w'], $png['h'], $ct === 6 ? 4 : 2);
			if ($a === 'partial' || $a === false)
			{
				return false; // GD keeps only 7 bits of alpha
			}
			$opaque = ($a === 'opaque');
		}

		$im = @imagecreatefromstring($raw);
		if ($im === false)
		{
			return false;
		}
		if (!imageistruecolor($im))
		{
			imagepalettetotruecolor($im);
		}
		imagealphablending($im, false);
		imagesavealpha($im, true);
		$w = imagesx($im);
		$h = imagesy($im);
		if ($w !== $png['w'] || $h !== $png['h'])
		{
			return false;
		}

		$variants = [];

		// palette, when the image has no more than 256 colours
		$pal = imagecreatetruecolor($w, $h);
		imagealphablending($pal, false);
		imagesavealpha($pal, true);
		imagecopy($pal, $im, 0, 0, 0, 0, $w, $h);
		imagetruecolortopalette($pal, false, 256);
		if (self::images_equal($im, $pal))
		{
			$variants[] = $pal;
		}
		else
		{
			unset($pal);
		}

		// true colour, without the alpha channel when it is fully opaque
		if ($opaque || $ct === 0 || $ct === 2 || $ct === 3 && $png['trns'] === '')
		{
			$rgb = imagecreatetruecolor($w, $h);
			imagealphablending($rgb, false);
			imagecopy($rgb, $im, 0, 0, 0, 0, $w, $h);
			imagesavealpha($rgb, false);
			$variants[] = $rgb;
		}
		else
		{
			$variants[] = $im; // alpha stays (binary)
		}

		$filters = [PNG_ALL_FILTERS, PNG_FILTER_SUB, PNG_FILTER_UP, PNG_FILTER_PAETH, PNG_NO_FILTER, PNG_FILTER_AVG];
		if ($w * $h > 1500000)
		{
			$filters = [PNG_ALL_FILTERS, PNG_FILTER_SUB];
		}

		$best = false;
		foreach ($variants as $v)
		{
			foreach ($filters as $f)
			{
				if ($best !== false && microtime(true) > self::$deadline)
				{
					break 2;
				}
				ob_start();
				$ok = @imagepng($v, null, 9, $f);
				$out = ob_get_clean();
				if (!$ok || $out === '' || $out === false)
				{
					continue;
				}
				$p = self::parse($out);
				if (!$p || $p['il'] !== 0)
				{
					continue;
				}
				$sl = @gzuncompress($p['idat']);
				if ($sl === false)
				{
					continue;
				}
				$cand = self::assemble($p['ihdr'], $png['keep'], $p['plte'], $p['trns'], $sl);
				if ($cand === false || strlen($cand) > $best_size || ($best !== false && strlen($cand) >= strlen($best)))
				{
					continue;
				}
				// must show the same pixels as the original
				$chk = @imagecreatefromstring($cand);
				if ($chk === false || !self::images_equal($im, $chk))
				{
					continue;
				}
				$best = $cand;
			}
		}
		return $best;
	}

	protected static function images_equal($a, $b)
	{
		if (!imageistruecolor($a))
		{
			imagepalettetotruecolor($a);
		}
		if (!imageistruecolor($b))
		{
			imagepalettetotruecolor($b);
		}
		if (imagesx($a) !== imagesx($b) || imagesy($a) !== imagesy($b))
		{
			return false;
		}
		$r = imagecompare($a, $b);
		return ($r & (IMG_CMP_SIZE_X | IMG_CMP_SIZE_Y | IMG_CMP_COLOR)) === 0;
	}

	/** tRNS of a palette image with only fully transparent or fully opaque entries. */
	protected static function binary_trns($trns)
	{
		for ($i = 0, $n = strlen($trns); $i < $n; $i++)
		{
			$v = ord($trns[$i]);
			if ($v !== 0 && $v !== 255)
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Alpha channel of an 8-bit non-interlaced RGBA / gray+alpha image: 'opaque' (all 255), 'binary'
	 * (only 0 and 255) or 'partial'. The alpha bytes are rebuilt on their own: PNG filters work
	 * on every byte against the same channel of the neighbouring pixels, so the other channels are not needed.
	 */
	protected static function alpha_class($data, $w, $h, $bpp)
	{
		$stride = 1 + $w * $bpp;
		if (strlen($data) < $stride * $h)
		{
			return false;
		}
		$prev = array_fill(0, $w, 0);
		$zero = false;
		$off = $bpp - 1;
		for ($y = 0; $y < $h; $y++)
		{
			$base = $y * $stride;
			$ft = ord($data[$base]);
			if ($ft > 4)
			{
				return false;
			}
			$cur = [];
			$left = 0;
			$upleft = 0;
			$pos = $base + 1 + $off;
			for ($x = 0; $x < $w; $x++, $pos += $bpp)
			{
				$v = ord($data[$pos]);
				$up = $prev[$x];
				switch ($ft)
				{
					case 1: $v += $left; break;
					case 2: $v += $up; break;
					case 3: $v += ($left + $up) >> 1; break;
					case 4:
						$p = $left + $up - $upleft;
						$pa = abs($p - $left);
						$pb = abs($p - $up);
						$pc = abs($p - $upleft);
						$v += ($pa <= $pb && $pa <= $pc) ? $left : (($pb <= $pc) ? $up : $upleft);
						break;
				}
				$v &= 255;
				if ($v !== 255)
				{
					if ($v !== 0)
					{
						return 'partial';
					}
					$zero = true;
				}
				$cur[$x] = $v;
				$left = $v;
				$upleft = $up;
			}
			$prev = $cur;
		}
		return $zero ? 'binary' : 'opaque';
	}
}
