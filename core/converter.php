<?php
/**
 * AVIF migration - converts already uploaded images to AVIF through GD, in place.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\core;

class converter
{
	const QUALITY = 50;
	/** Screenshots and graphics: GD switches the encoder to full-resolution colour (4:4:4) from quality 90 (same rule as slamb/avifattach). */
	const QUALITY_SHARP = 90;
	const EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;
	/** @var \phpbb\config\config */
	protected $config;
	protected $root_path;
	protected $log_table;
	protected $attach_table;
	protected $users_table;

	public function __construct($db, $config, $root_path, $log_table, $attach_table, $users_table)
	{
		$this->db = $db;
		$this->config = $config;
		$this->root_path = $root_path;
		$this->log_table = $log_table;
		$this->attach_table = $attach_table;
		$this->users_table = $users_table;
	}

	/**
	 * Quality by source type: PNG, BMP, GIF and lossless WebP (screenshots, graphics) are encoded in 4:4:4, photos in 4:2:0.
	 */
	protected function quality_for_type($type, $raw)
	{
		if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_BMP, IMAGETYPE_GIF], true))
		{
			return self::QUALITY_SHARP;
		}
		if ($type === IMAGETYPE_WEBP && strpos(substr($raw, 0, 64), 'VP8L') !== false)
		{
			return self::QUALITY_SHARP;
		}
		return self::QUALITY;
	}

	/**
	 * True when an AVIF file stores full-resolution colour (read from its av1C box).
	 */
	protected function is_444($head)
	{
		$p = strpos($head, 'av1C');
		return $p !== false && isset($head[$p + 6]) && (ord($head[$p + 6]) & 0x0C) === 0;
	}

	public function supported()
	{
		return avif_codec::supported();
	}

	/* ------------------------------------------------------------ queries */

	protected function candidates_sql($type, $order = true, $for_analysis = false)
	{
		// conversion takes items that are not logged yet or were analysed as OK ('queued');
		// analysis takes only the not logged ones
		$skip = $for_analysis ? '' : " AND l.status <> 'queued'";
		$not_logged = ' AND NOT EXISTS (SELECT 1 FROM ' . $this->log_table . " l WHERE l.item_type = '" . $this->db->sql_escape($type) . "' AND l.item_id = %s" . $skip . ')';

		if ($type === 'attach')
		{
			return 'SELECT a.* FROM ' . $this->attach_table . ' a
				WHERE a.is_orphan = 0
					AND ' . $this->db->sql_in_set('a.extension', self::EXTS) . "
					AND a.mimetype <> 'image/avif'"
					. sprintf($not_logged, 'a.attach_id')
				. ($order ? ' ORDER BY a.filetime ASC, a.attach_id ASC' : '');
		}

		// avatars: only uploaded ones live on disk
		return 'SELECT u.user_id, u.user_avatar, u.username FROM ' . $this->users_table . " u
			WHERE u.user_avatar_type = 'avatar.driver.upload'
				AND u.user_avatar <> ''"
				. sprintf($not_logged, 'u.user_id')
			. ($order ? ' ORDER BY u.user_regdate ASC, u.user_id ASC' : '');
	}

	public function remaining($type)
	{
		$sql = 'SELECT COUNT(*) AS c FROM (' . $this->candidates_sql($type, false) . ') t';
		$result = $this->db->sql_query($sql);
		$c = (int) $this->db->sql_fetchfield('c');
		$this->db->sql_freeresult($result);
		return $c;
	}

	/**
	 * Convert the next $limit images (oldest first). Returns the log rows created.
	 */
	public function run($type, $limit, $budget = 0)
	{
		$t0 = microtime(true);
		$limit = max(1, (int) $limit);
		$rows = [];
		$result = $this->db->sql_query_limit($this->candidates_sql($type), $limit);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		$out = [];
		foreach ($rows as $row)
		{
			if ($budget > 0 && microtime(true) - $t0 > $budget)
			{
				break; // stay under the web server / CDN timeout, the rest is picked up by the next run
			}
			$out[] = ($type === 'attach') ? $this->do_attachment($row) : $this->do_avatar($row);
		}
		return $out;
	}

	/**
	 * Convert one chosen item (ready or not yet analysed). Returns the log row or null if it is not a candidate.
	 */
	public function run_one($type, $id)
	{
		$col = ($type === 'attach') ? 'a.attach_id' : 'u.user_id';
		$result = $this->db->sql_query_limit($this->candidates_sql($type, false) . ' AND ' . $col . ' = ' . (int) $id, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row)
		{
			return null;
		}
		return ($type === 'attach') ? $this->do_attachment($row) : $this->do_avatar($row);
	}

	/* ------------------------------------------------------------ items */

	protected function do_attachment(array $a)
	{
		$entry = [
			'item_type'  => 'attach',
			'item_id'    => (int) $a['attach_id'],
			'item_name'  => (string) $a['real_filename'],
			'post_id'    => (int) $a['post_msg_id'],
			'topic_id'   => (int) $a['topic_id'],
			'in_message' => (int) $a['in_message'],
			'user_id'    => (int) $a['poster_id'],
		];
		if (!$this->claim($entry))
		{
			return $entry + ['status' => 'busy', 'message' => 'already handled', 'size_before' => 0, 'size_after' => 0];
		}

		$path = $this->root_path . $this->config['upload_path'] . '/' . $a['physical_filename'];
		$res = $this->convert_file($path);

		if ($res['status'] === 'ok')
		{
			$new_name = preg_match('/\.[A-Za-z0-9]+$/', $a['real_filename'])
				? preg_replace('/\.[A-Za-z0-9]+$/', '.avif', $a['real_filename'])
				: $a['real_filename'] . '.avif';

			$upd = [
				'real_filename' => $new_name,
				'extension'     => 'avif',
				'mimetype'      => 'image/avif',
				'filesize'      => $res['size_after'],
			];

			// the thumbnail is served with the attachment's mimetype, so it has to follow
			if (!empty($a['thumbnail']))
			{
				$thumb = $this->root_path . $this->config['upload_path'] . '/thumb_' . $a['physical_filename'];
				$tres = is_file($thumb) ? $this->convert_file($thumb) : ['status' => 'failed'];
				if ($tres['status'] !== 'ok' && $tres['status'] !== 'skipped_avif')
				{
					// thumbnail missing or not convertible: build a fresh AVIF one from the converted image,
					// otherwise phpBB would show the full image inline without the click-to-enlarge link
					if (!$this->make_thumb($path, $thumb))
					{
						$upd['thumbnail'] = 0;
					}
				}
			}

			$this->db->sql_query('UPDATE ' . $this->attach_table . ' SET ' . $this->db->sql_build_array('UPDATE', $upd) . ' WHERE attach_id = ' . (int) $a['attach_id']);
			$entry['item_name'] = $new_name;
		}

		return $this->finish($entry, $res);
	}

	/**
	 * Create an AVIF thumbnail (phpBB's thumbnail width) from an AVIF image. False when the image is
	 * not wider than the thumbnail width (phpBB would not make one either) or on any failure.
	 */
	public function make_thumb($src, $dest)
	{
		if (!$this->supported() || !is_file($src))
		{
			return false;
		}
		$max = max(50, (int) ($this->config['img_max_thumb_width'] ?: 400));
		$head = (string) @file_get_contents($src, false, null, 0, 512);
		$img = avif_codec::decode($src);
		if ($img === false)
		{
			return false;
		}
		$w = imagesx($img);
		$h = imagesy($img);
		if ($w <= $max)
		{
			return false;
		}
		$nw = $max;
		$nh = max(1, (int) round($h * $max / $w));
		$new = imagecreatetruecolor($nw, $nh);
		imagealphablending($new, false);
		imagesavealpha($new, true);
		imagefill($new, 0, 0, imagecolorallocatealpha($new, 0, 0, 0, 127));
		imagecopyresampled($new, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
		unset($img);
		$tmp = $dest . '.avif.tmp';
		// keep full-resolution colour in the thumbnail when the original has it
		$ok = avif_codec::encode($new, $tmp, $this->is_444($head) ? self::QUALITY_SHARP : self::QUALITY);
		unset($new);
		clearstatcache(true, $tmp);
		if (!$ok || (int) @filesize($tmp) <= 0 || !@rename($tmp, $dest))
		{
			@unlink($tmp);
			return false;
		}
		if (function_exists('chmod')) { @chmod($dest, 0644); }
		return true;
	}

	/**
	 * Repair for attachments converted by an earlier version: give converted images that lost their
	 * thumbnail a new AVIF one (when wide enough) and set the thumbnail flag again.
	 * @return int number of thumbnails created
	 */
	public function repair_thumbs()
	{
		if (!$this->config['img_create_thumbnail'])
		{
			return 0;
		}
		$sql = 'SELECT a.attach_id, a.physical_filename FROM ' . $this->attach_table . ' a, ' . $this->log_table . " l
			WHERE l.item_type = 'attach' AND l.status = 'ok' AND l.item_id = a.attach_id
				AND a.thumbnail = 0 AND a.extension = 'avif' AND a.is_orphan = 0
				AND l.size_before >= " . (int) $this->config['img_min_thumb_filesize'] . "
			ORDER BY a.attach_id ASC";
		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		$n = 0;
		$dir = $this->root_path . $this->config['upload_path'] . '/';
		foreach ($rows as $row)
		{
			if ($this->make_thumb($dir . $row['physical_filename'], $dir . 'thumb_' . $row['physical_filename']))
			{
				$this->db->sql_query('UPDATE ' . $this->attach_table . ' SET thumbnail = 1 WHERE attach_id = ' . (int) $row['attach_id']);
				$n++;
			}
		}
		return $n;
	}

	protected function do_avatar(array $u)
	{
		$entry = [
			'item_type'  => 'avatar',
			'item_id'    => (int) $u['user_id'],
			'item_name'  => (string) $u['username'],
			'post_id'    => 0,
			'topic_id'   => 0,
			'in_message' => 0,
			'user_id'    => (int) $u['user_id'],
		];
		if (!$this->claim($entry))
		{
			return $entry + ['status' => 'busy', 'message' => 'already handled', 'size_before' => 0, 'size_after' => 0];
		}

		// file name on disk: {salt}_{user_id}.{ext}; the extension stays as it is, because
		// download/file.php only serves png/gif/jpg/jpeg avatars. It sends the Content-Type from
		// the file content (getimagesize), so AVIF data under the old name is displayed as AVIF.
		$ext = strtolower(pathinfo($u['user_avatar'], PATHINFO_EXTENSION));
		$path = $this->root_path . $this->config['avatar_path'] . '/' . $this->config['avatar_salt'] . '_' . (int) $u['user_id'] . '.' . $ext;

		return $this->finish($entry, $this->convert_file($path));
	}

	/* ------------------------------------------------------------ log */

	/** Reserve the item in the log (unique key), so two runs never convert the same file. */
	protected function claim(array &$entry)
	{
		// analysed earlier: take over the 'queued' row (only one run can win this UPDATE)
		$this->db->sql_query('UPDATE ' . $this->log_table . " SET status = 'pending', log_time = " . time() . "
			WHERE item_type = '" . $this->db->sql_escape($entry['item_type']) . "' AND item_id = " . (int) $entry['item_id'] . " AND status = 'queued'");
		if ($this->db->sql_affectedrows() > 0)
		{
			return true;
		}

		$row = $entry + [
			'status'      => 'pending',
			'message'     => '',
			'size_before' => 0,
			'size_after'  => 0,
			'log_time'    => time(),
		];
		$this->db->sql_return_on_error(true);
		$this->db->sql_query('INSERT INTO ' . $this->log_table . ' ' . $this->db->sql_build_array('INSERT', $row));
		$failed = $this->db->get_sql_error_triggered();
		$this->db->sql_return_on_error(false);
		return !$failed;
	}

	protected function finish(array $entry, array $res)
	{
		if ($res['status'] === 'failed' && ($this->is_broken_message($res['message']) || in_array($res['message'], self::UNSUPPORTED, true)))
		{
			$res['status'] = $this->failed_status($res['message']);
		}
		$upd = [
			'item_name'   => $entry['item_name'],
			'status'      => $res['status'],
			'message'     => mb_substr((string) $res['message'], 0, 250),
			'size_before' => (int) $res['size_before'],
			'size_after'  => (int) $res['size_after'],
			'log_time'    => time(),
		];
		$this->db->sql_query('UPDATE ' . $this->log_table . ' SET ' . $this->db->sql_build_array('UPDATE', $upd) . "
			WHERE item_type = '" . $this->db->sql_escape($entry['item_type']) . "' AND item_id = " . (int) $entry['item_id']);
		return $upd + $entry;
	}

	/** Log rows with what is needed for a preview (attachment thumbnail flag, avatar file name). */
	protected function with_preview_sql($where, $order)
	{
		return 'SELECT l.*, a.thumbnail, u.user_avatar FROM ' . $this->log_table . ' l
			LEFT JOIN ' . $this->attach_table . " a ON (l.item_type = 'attach' AND a.attach_id = l.item_id)
			LEFT JOIN " . $this->users_table . " u ON (l.item_type = 'avatar' AND u.user_id = l.item_id)
			WHERE " . $where . ' ORDER BY ' . $order;
	}

	/** Log rows (with preview data) of the given items, in the order of $ids. */
	public function rows_by_ids($type, array $ids)
	{
		$ids = array_map('intval', $ids);
		if (!$ids)
		{
			return [];
		}
		$by = [];
		$result = $this->db->sql_query($this->with_preview_sql("l.item_type = '" . $this->db->sql_escape($type) . "' AND " . $this->db->sql_in_set('l.item_id', $ids), 'l.item_id'));
		while ($row = $this->db->sql_fetchrow($result))
		{
			$by[(int) $row['item_id']] = $row;
		}
		$this->db->sql_freeresult($result);
		$out = [];
		foreach ($ids as $id)
		{
			if (isset($by[$id]))
			{
				$out[] = $by[$id];
			}
		}
		return $out;
	}

	public function recent($limit = 50)
	{
		$rows = [];
		$result = $this->db->sql_query_limit($this->with_preview_sql("l.status NOT IN ('queued', 'broken', 'pending')", 'l.log_time DESC, l.log_id DESC'), $limit);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}

	/* ------------------------------------------------------------ analysis */

	/** The file is really gone or is not an image at all: the browser shows a missing-image placeholder. */
	const BROKEN = ['not a valid image', 'cannot read file', 'file missing'];
	/** A valid image that GD cannot decode (CMYK JPEG, odd WebP, ...): browsers usually show it, it just cannot be converted. */
	const UNSUPPORTED = ['GD cannot decode the image'];

	protected function is_broken_message($msg)
	{
		return in_array($msg, self::BROKEN, true);
	}

	/** Status for a failed check: 'broken' (ignored, may be deleted) or 'unsupported' (leave alone). */
	protected function failed_status($msg)
	{
		return in_array($msg, self::UNSUPPORTED, true) ? 'unsupported' : 'broken';
	}

	/**
	 * Tidy up rows written by older versions: unusable files logged as 'failed' become broken/unsupported,
	 * and files GD merely cannot decode are no longer called broken.
	 */
	public function normalize()
	{
		$this->recover();
		$this->db->sql_query('UPDATE ' . $this->log_table . " SET status = 'broken' WHERE status = 'failed' AND " . $this->db->sql_in_set('message', self::BROKEN));
		$this->db->sql_query('UPDATE ' . $this->log_table . " SET status = 'unsupported' WHERE status IN ('failed', 'broken') AND " . $this->db->sql_in_set('message', self::UNSUPPORTED));
		$this->recheck_invalid();
	}

	/**
	 * Files once logged as 'not a valid image' by a stricter check: look again (GD is tolerant like a browser).
	 * Usable ones are queued, image-like but undecodable ones become 'unsupported'.
	 */
	protected function recheck_invalid()
	{
		$rows = [];
		$result = $this->db->sql_query_limit('SELECT l.log_id, l.item_type, l.item_id, a.physical_filename, u.user_avatar FROM ' . $this->log_table . ' l
			LEFT JOIN ' . $this->attach_table . " a ON (l.item_type = 'attach' AND a.attach_id = l.item_id)
			LEFT JOIN " . $this->users_table . " u ON (l.item_type = 'avatar' AND u.user_id = l.item_id)
			WHERE l.status = 'broken' AND l.message = 'not a valid image'", 300);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		foreach ($rows as $row)
		{
			if ($row['item_type'] === 'attach')
			{
				$path = $this->root_path . $this->config['upload_path'] . '/' . $row['physical_filename'];
			}
			else
			{
				$ext = strtolower(pathinfo((string) $row['user_avatar'], PATHINFO_EXTENSION));
				$path = $this->root_path . $this->config['avatar_path'] . '/' . $this->config['avatar_salt'] . '_' . (int) $row['item_id'] . '.' . $ext;
			}
			list($ok, $msg) = $this->check_file($path);
			if ($msg === 'not a valid image')
			{
				continue;
			}
			$upd = $ok
				? ['status' => 'queued', 'message' => '', 'size_before' => (int) @filesize($path)]
				: ['status' => $this->failed_status($msg), 'message' => $msg];
			$this->db->sql_query('UPDATE ' . $this->log_table . ' SET ' . $this->db->sql_build_array('UPDATE', $upd) . ' WHERE log_id = ' . (int) $row['log_id']);
		}
	}

	/**
	 * A run that was killed (gateway timeout, PHP time limit) leaves its item as 'pending'. If the file was
	 * already replaced by AVIF but the database row was not updated yet, finish that update; otherwise
	 * just forget the row so the item is picked up again.
	 */
	public function recover()
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->log_table . " WHERE status = 'pending' AND log_time < " . (time() - 600));
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		foreach ($rows as $log)
		{
			$done = false;
			if ($log['item_type'] === 'attach')
			{
				$result = $this->db->sql_query('SELECT * FROM ' . $this->attach_table . ' WHERE attach_id = ' . (int) $log['item_id']);
				$a = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				if ($a && $a['extension'] !== 'avif')
				{
					$dir = $this->root_path . $this->config['upload_path'] . '/';
					$path = $dir . $a['physical_filename'];
					$head = is_file($path) ? @file_get_contents($path, false, null, 0, 16) : false;
					if ($head !== false && substr($head, 4, 8) === 'ftypavif')
					{
						clearstatcache(true, $path);
						$size = (int) filesize($path);
						$name = preg_match('/\.[A-Za-z0-9]+$/', $a['real_filename']) ? preg_replace('/\.[A-Za-z0-9]+$/', '.avif', $a['real_filename']) : $a['real_filename'] . '.avif';
						$upd = ['real_filename' => $name, 'extension' => 'avif', 'mimetype' => 'image/avif', 'filesize' => $size];
						if (!empty($a['thumbnail']))
						{
							$thumb = $dir . 'thumb_' . $a['physical_filename'];
							$th = is_file($thumb) ? @file_get_contents($thumb, false, null, 0, 16) : false;
							if (!($th !== false && substr($th, 4, 8) === 'ftypavif') && !$this->make_thumb($path, $thumb))
							{
								$upd['thumbnail'] = 0;
							}
						}
						$this->db->sql_query('UPDATE ' . $this->attach_table . ' SET ' . $this->db->sql_build_array('UPDATE', $upd) . ' WHERE attach_id = ' . (int) $a['attach_id']);
						$this->db->sql_query('UPDATE ' . $this->log_table . " SET status = 'ok', message = 'recovered after interrupted run', item_name = '" . $this->db->sql_escape($name) . "', size_after = " . $size . ' WHERE log_id = ' . (int) $log['log_id']);
						$done = true;
					}
				}
			}
			if (!$done)
			{
				$this->db->sql_query('DELETE FROM ' . $this->log_table . ' WHERE log_id = ' . (int) $log['log_id']);
			}
		}
	}

	/**
	 * Check whether the image is usable at all (file there, readable, valid image, GD can decode it)
	 * without changing anything. Returns [ok, message].
	 */
	public function check_file($path)
	{
		if (!is_file($path))
		{
			return [false, 'file missing'];
		}
		$raw = is_readable($path) ? @file_get_contents($path) : false;
		if ($raw === false || $raw === '')
		{
			return [false, 'cannot read file'];
		}
		$info = $this->image_info($raw);
		if (!$info)
		{
			return [false, $this->looks_like_image($raw) ? 'GD cannot decode the image' : 'not a valid image'];
		}
		if ($info[2] === IMAGETYPE_AVIF || $this->is_animated($raw) || !$this->fits_in_memory((int) $info[0], (int) $info[1]))
		{
			return [true, '']; // converter decides about these later; the file itself is fine
		}
		$img = @imagecreatefromstring($raw);
		if ($img === false)
		{
			return [false, 'GD cannot decode the image'];
		}
		unset($img);
		return [true, ''];
	}

	/**
	 * Scan the next $limit not yet analysed images (oldest first). Usable ones are marked 'queued',
	 * broken ones 'broken' and are ignored from then on. Returns [queued, broken].
	 */
	public function analyze($type, $limit, $budget = 0)
	{
		$t0 = microtime(true);
		$limit = max(1, (int) $limit);
		$rows = [];
		$result = $this->db->sql_query_limit($this->candidates_sql($type, true, true), $limit);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		$queued = $broken = $unsupported = 0;
		foreach ($rows as $row)
		{
			if ($budget > 0 && microtime(true) - $t0 > $budget)
			{
				break;
			}
			if ($type === 'attach')
			{
				$path = $this->root_path . $this->config['upload_path'] . '/' . $row['physical_filename'];
				$entry = ['item_type' => 'attach', 'item_id' => (int) $row['attach_id'], 'item_name' => (string) $row['real_filename'],
					'post_id' => (int) $row['post_msg_id'], 'topic_id' => (int) $row['topic_id'], 'in_message' => (int) $row['in_message'], 'user_id' => (int) $row['poster_id']];
			}
			else
			{
				$ext = strtolower(pathinfo($row['user_avatar'], PATHINFO_EXTENSION));
				$path = $this->root_path . $this->config['avatar_path'] . '/' . $this->config['avatar_salt'] . '_' . (int) $row['user_id'] . '.' . $ext;
				$entry = ['item_type' => 'avatar', 'item_id' => (int) $row['user_id'], 'item_name' => (string) $row['username'],
					'post_id' => 0, 'topic_id' => 0, 'in_message' => 0, 'user_id' => (int) $row['user_id']];
			}

			list($ok, $msg) = $this->check_file($path);
			$entry += ['status' => $ok ? 'queued' : $this->failed_status($msg), 'message' => $msg, 'size_before' => $ok ? (int) @filesize($path) : 0, 'size_after' => 0, 'log_time' => time()];

			$this->db->sql_return_on_error(true);
			$this->db->sql_query('INSERT INTO ' . $this->log_table . ' ' . $this->db->sql_build_array('INSERT', $entry));
			$dup = $this->db->get_sql_error_triggered();
			$this->db->sql_return_on_error(false);
			if (!$dup)
			{
				if ($ok)
				{
					$queued++;
				}
				else if ($entry['status'] === 'unsupported')
				{
					$unsupported++;
				}
				else
				{
					$broken++;
				}
			}
		}
		return [$queued, $broken, $unsupported];
	}

	/** Counts for the overview: not analysed, ready (queued), broken, converted, ... */
	public function stats($type)
	{
		$out = ['new' => 0, 'queued' => 0, 'broken' => 0, 'unsupported' => 0, 'ok' => 0, 'skipped_animated' => 0, 'skipped_avif' => 0, 'too_large' => 0, 'failed' => 0];
		$result = $this->db->sql_query('SELECT status, COUNT(*) AS c FROM ' . $this->log_table . " WHERE item_type = '" . $this->db->sql_escape($type) . "' GROUP BY status");
		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[$row['status']] = (int) $row['c'];
		}
		$this->db->sql_freeresult($result);

		$result = $this->db->sql_query('SELECT COUNT(*) AS c FROM (' . $this->candidates_sql($type, false, true) . ') t');
		$out['new'] = (int) $this->db->sql_fetchfield('c');
		$this->db->sql_freeresult($result);
		return $out;
	}

	/**
	 * Space saved so far: converted count, bytes before / after, plus the size of what is still ready
	 * to convert and an estimate of the saving there (same ratio as so far).
	 */
	public function savings($type)
	{
		$out = ['count' => 0, 'before' => 0, 'after' => 0, 'saved' => 0, 'percent' => 0, 'ready_size' => 0, 'ready_count' => 0, 'estimate' => 0];
		$result = $this->db->sql_query('SELECT COUNT(*) AS c, SUM(size_before) AS b, SUM(size_after) AS a FROM ' . $this->log_table . " WHERE status = 'ok' AND item_type = '" . $this->db->sql_escape($type) . "'");
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		$out['count'] = (int) $row['c'];
		$out['before'] = (int) $row['b'];
		$out['after'] = (int) $row['a'];
		$out['saved'] = max(0, $out['before'] - $out['after']);
		$out['percent'] = $out['before'] > 0 ? round($out['saved'] / $out['before'] * 100) : 0;

		$result = $this->db->sql_query('SELECT COUNT(*) AS c, SUM(size_before) AS b FROM ' . $this->log_table . " WHERE status = 'queued' AND item_type = '" . $this->db->sql_escape($type) . "'");
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		$out['ready_count'] = (int) $row['c'];
		$out['ready_size'] = (int) $row['b'];
		if ($out['before'] > 0)
		{
			$out['estimate'] = (int) round($out['ready_size'] * ($out['saved'] / $out['before']));
		}
		return $out;
	}

	public function broken_list($limit = 50)
	{
		$rows = [];
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->log_table . " WHERE status = 'broken' ORDER BY log_id ASC", $limit);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}

	/**
	 * Paged list of attachment log rows for the review page.
	 * @param string $filter broken|ready|all
	 * @return array [rows, total]
	 */
	public function review_list($filter, $start, $per_page, $sort = 'id', $dir = 'asc', $type = 'attach')
	{
		$type = ($type === 'avatar') ? 'avatar' : 'attach';
		$where = "l.item_type = '" . $type . "'";
		switch ($filter)
		{
			case 'broken':      $where .= " AND l.status = 'broken'"; break;
			case 'unsupported': $where .= " AND l.status = 'unsupported'"; break;
			case 'ready':       $where .= " AND l.status = 'queued'"; break;
			case 'converted':   $where .= " AND l.status IN ('ok', 'skipped_avif')"; break;
			case 'skipped':     $where .= " AND l.status IN ('skipped_animated', 'too_large')"; break;
			case 'failed':      $where .= " AND l.status = 'failed'"; break;
			default:            $where .= " AND l.status <> 'pending'"; break;
		}
		$result = $this->db->sql_query('SELECT COUNT(*) AS c FROM ' . $this->log_table . ' l WHERE ' . $where);
		$total = (int) $this->db->sql_fetchfield('c');
		$this->db->sql_freeresult($result);

		$cols = ['id' => 'l.item_id', 'name' => 'l.item_name', 'size' => 'l.size_before', 'status' => 'l.status', 'message' => 'l.message', 'where' => 'l.post_id', 'saved' => '(l.size_before - l.size_after)'];
		$order = ($cols[$sort] ?? 'l.item_id') . ($dir === 'desc' ? ' DESC' : ' ASC') . ', l.item_id ASC';

		$rows = [];
		$result = $this->db->sql_query_limit($this->with_preview_sql($where, $order), $per_page, $start);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		return [$rows, $total];
	}

	/** Remove log rows of deleted attachments. */
	public function forget_attachments(array $ids)
	{
		$ids = array_map('intval', $ids);
		if ($ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->log_table . " WHERE item_type = 'attach' AND " . $this->db->sql_in_set('item_id', $ids));
		}
	}

	/** Remove log rows of avatars that were deleted. */
	public function forget_avatars(array $ids)
	{
		$ids = array_map('intval', $ids);
		if ($ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->log_table . " WHERE item_type = 'avatar' AND " . $this->db->sql_in_set('item_id', $ids));
		}
	}

	/* ------------------------------------------------------------ the conversion itself */

	/**
	 * Re-encode one image file as AVIF in place (atomic rename). Never touches the file unless the
	 * new one has been written and decoded back successfully.
	 *
	 * @return array status (ok|skipped_avif|skipped_animated|too_large|failed), message, size_before, size_after
	 */
	public function convert_file($path)
	{
		$r = function ($status, $msg = '', $before = 0, $after = 0) {
			return ['status' => $status, 'message' => $msg, 'size_before' => $before, 'size_after' => $after];
		};

		if (!$this->supported())
		{
			return $r('failed', 'GD without AVIF support');
		}
		if (!is_file($path))
		{
			return $r('failed', 'file missing');
		}
		if (!is_readable($path))
		{
			return $r('failed', 'cannot read file');
		}
		if (!is_writable($path) || !is_writable(dirname($path)))
		{
			return $r('failed', 'file or folder not writable');
		}

		$raw = @file_get_contents($path);
		if ($raw === false || $raw === '')
		{
			return $r('failed', 'cannot read file');
		}
		$before = strlen($raw);

		$info = $this->image_info($raw);
		if (!$info)
		{
			return $r('failed', $this->looks_like_image($raw) ? 'GD cannot decode the image' : 'not a valid image', $before);
		}
		if ($info[2] === IMAGETYPE_AVIF)
		{
			return $r('skipped_avif', 'already AVIF', $before, $before);
		}
		if ($this->is_animated($raw))
		{
			return $r('skipped_animated', 'animated image', $before, $before);
		}
		if (!$this->fits_in_memory((int) $info[0], (int) $info[1]))
		{
			return $r('too_large', $info[0] . 'x' . $info[1] . ' does not fit in memory_limit', $before);
		}

		$quality = $this->quality_for_type((int) $info[2], $raw);
		$img = @imagecreatefromstring($raw);
		if ($img === false)
		{
			return $r('failed', 'GD cannot decode the image', $before);
		}
		unset($raw);

		if ($info[2] === IMAGETYPE_JPEG)
		{
			$img = $this->apply_orientation($img, $path);
		}

		// keep transparency
		imagepalettetotruecolor($img);
		imagealphablending($img, false);
		imagesavealpha($img, true);

		$w = imagesx($img);
		$h = imagesy($img);

		// re-encoding drops all metadata (EXIF, GPS, ICC, XMP)
		$tmp = $path . '.avif.tmp';
		$ok = avif_codec::encode($img, $tmp, $quality);
		unset($img);

		clearstatcache(true, $tmp);
		$after = $ok ? (int) @filesize($tmp) : 0;
		if ($after <= 0)
		{
			@unlink($tmp);
			return $r('failed', 'AVIF encoding failed', $before);
		}

		// decode it back before the original is replaced
		$chk = @getimagesize($tmp);
		$back = avif_codec::decode($tmp);
		$good = $chk && $chk[2] === IMAGETYPE_AVIF && $back !== false && imagesx($back) === $w && imagesy($back) === $h;
		unset($back);
		if (!$good)
		{
			@unlink($tmp);
			return $r('failed', 'verification of the AVIF file failed', $before);
		}

		if (function_exists('chmod') && function_exists('fileperms')) { @chmod($tmp, @fileperms($path) & 0777); }
		if (!@rename($tmp, $path))
		{
			@unlink($tmp);
			return $r('failed', 'cannot replace the original', $before);
		}

		return $r('ok', '', $before, $after);
	}

	/** getimagesize first; if it cannot parse the data, let GD try (it is more tolerant, like browsers). */
	protected function image_info($raw)
	{
		$info = @getimagesizefromstring($raw);
		if ($info)
		{
			return $info;
		}
		$img = @imagecreatefromstring($raw);
		if ($img === false)
		{
			return false;
		}
		$info = [imagesx($img), imagesy($img), 0];
		unset($img);
		return $info;
	}

	/** Magic bytes of common image formats: such a file is an image browsers may still show, even if GD cannot read it. */
	protected function looks_like_image($raw)
	{
		$head = substr(ltrim($raw, "\x00\r\n\t "), 0, 16);
		return strncmp($head, "\xFF\xD8", 2) === 0
			|| strncmp($head, "\x89PNG", 4) === 0
			|| strncmp($head, 'GIF8', 4) === 0
			|| strncmp($head, 'BM', 2) === 0
			|| (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP');
	}

	protected function fits_in_memory($w, $h)
	{
		$limit = ini_get('memory_limit');
		if ($limit === '-1' || $limit === false)
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
		// GD true colour: 4 bytes per pixel, plus a second copy for rotate / palette conversion
		return ($w * $h * 4 * 2 + memory_get_usage()) < ($bytes * 0.9);
	}

	protected function is_animated($raw)
	{
		if (substr($raw, 0, 3) === 'GIF')
		{
			return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $raw) > 1;
		}
		if (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP')
		{
			return strpos($raw, 'ANIM') !== false && strpos($raw, 'ANMF') !== false;
		}
		if (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n")
		{
			return strpos(substr($raw, 0, 4096), 'acTL') !== false;
		}
		return false;
	}

	protected function apply_orientation($img, $path)
	{
		if (!function_exists('exif_read_data'))
		{
			return $img;
		}
		$exif = @exif_read_data($path);
		$o = (int) ($exif['Orientation'] ?? 1);
		switch ($o)
		{
			case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
			case 3: $img = imagerotate($img, 180, 0); break;
			case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
			case 5: $img = imagerotate($img, -90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
			case 6: $img = imagerotate($img, -90, 0); break;
			case 7: $img = imagerotate($img, 90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
			case 8: $img = imagerotate($img, 90, 0); break;
		}
		return $img;
	}
}
