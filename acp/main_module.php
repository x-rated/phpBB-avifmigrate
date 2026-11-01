<?php
/**
 * AVIF migration - ACP module (overview, review list, AJAX batches).
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\acp;

class main_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	/** @var \slamb\avifmigrate\core\converter */
	protected $conv;

	/** Seconds one AJAX request may work before it returns (stays far below any gateway timeout). */
	const AJAX_BUDGET = 20;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user, $phpbb_root_path, $phpEx;

		$this->conv = $phpbb_container->get('slamb.avifmigrate.converter');

		$user->add_lang_ext('slamb/avifmigrate', 'common');
		$this->tpl_name = 'acp_avifmigrate';
		$this->page_title = $user->lang('ACP_AVIFMIGRATE');

		$form_key = 'slamb_avifmigrate';
		add_form_key($form_key);

		$this->conv->normalize();

		if ($request->is_ajax())
		{
			$this->ajax();
		}

		$view = $request->variable('view', '') === 'list';
		$ltype = $request->variable('type', 'attach') === 'avatar' ? 'avatar' : 'attach';

		if ($view)
		{
			$this->review($ltype);
			return;
		}

		$this->overview();
	}

	/* ------------------------------------------------------------ helpers */

	protected function lang($key)
	{
		global $user;
		return isset($user->lang[$key]) ? $user->lang[$key] : null;
	}

	/** Log message (stored in English) as a translated, HTML-safe string. */
	protected function message($msg)
	{
		global $user;
		$msg = (string) $msg;
		if ($msg === '')
		{
			return '';
		}
		if (preg_match('/^(\d+)x(\d+) does not fit in memory_limit$/', $msg, $m))
		{
			return utf8_htmlspecialchars(sprintf($user->lang('AVIFMIGRATE_MSG_TOO_LARGE'), $m[1] . ' x ' . $m[2]));
		}
		$key = 'AVIFMIGRATE_MSG_' . strtoupper(trim(preg_replace('/[^a-z0-9]+/i', '_', $msg), '_'));
		$t = $this->lang($key);
		return utf8_htmlspecialchars($t !== null ? $t : $msg);
	}

	protected function status_label($status)
	{
		global $user;
		$t = $this->lang('AVIFMIGRATE_STATUS_' . strtoupper($status));
		return utf8_htmlspecialchars($t !== null ? $t : $status);
	}

	/** Template / JSON variables of one log row. */
	protected function row(array $r)
	{
		global $user, $phpbb_root_path, $phpEx;

		$id = (int) $r['item_id'];
		$url = '';
		$where = '';
		$img = '';
		if ($r['item_type'] === 'avatar')
		{
			$url = append_sid($phpbb_root_path . 'memberlist.' . $phpEx, 'mode=viewprofile&amp;u=' . $id);
			$where = $user->lang('AVIFMIGRATE_WHERE_PROFILE');
			if (!empty($r['user_avatar']))
			{
				$img = append_sid($phpbb_root_path . 'download/file.' . $phpEx, 'avatar=' . rawurlencode($r['user_avatar']));
			}
		}
		else
		{
			if (!empty($r['in_message']))
			{
				$where = $user->lang('AVIFMIGRATE_WHERE_PM');
			}
			else if (!empty($r['post_id']))
			{
				$url = append_sid($phpbb_root_path . 'viewtopic.' . $phpEx, 't=' . (int) $r['topic_id'] . '&amp;p=' . (int) $r['post_id']) . '#p' . (int) $r['post_id'];
				$where = $user->lang('AVIFMIGRATE_WHERE_POST');
			}
			$img = append_sid($phpbb_root_path . 'download/file.' . $phpEx, 'id=' . $id . (!empty($r['thumbnail']) ? '&amp;t=1' : '') . '&amp;mode=view');
		}
		$full = ($r['item_type'] === 'attach') ? append_sid($phpbb_root_path . 'download/file.' . $phpEx, 'id=' . $id . '&amp;mode=view') : $img;

		$before = (int) $r['size_before'];
		$after = (int) $r['size_after'];
		$ok = in_array($r['status'], \slamb\avifmigrate\core\converter::DONE, true);

		return [
			'ID'        => $id,
			'TYPE'      => $r['item_type'] === 'avatar' ? $user->lang('AVIFMIGRATE_TYPE_AVATAR') : $user->lang('AVIFMIGRATE_TYPE_ATTACH'),
			'NAME'      => utf8_htmlspecialchars((string) $r['item_name']),
			'STATUS'    => $r['status'],
			'STATUS_L'  => $this->status_label($r['status']),
			'OK'        => $ok,
			'MESSAGE'   => $this->message($r['message']),
			'BEFORE'    => $before ? get_formatted_filesize($before) : '',
			'AFTER'     => $ok ? get_formatted_filesize($after) : '',
			'SAVED'     => ($ok && $before > 0) ? round((1 - $after / $before) * 100) . ' %' : '',
			'WHERE'     => $where,
			'U_WHERE'   => $url,
			'U_IMG'     => $img,
			'U_FULL'    => $full,
			'TIME'      => $user->format_date((int) ($r['log_time'] ?? time())),
		];
	}

	protected function url($extra = '')
	{
		return $this->u_action . ($extra !== '' ? '&amp;' . $extra : '');
	}

	/* ------------------------------------------------------------ AJAX (analysis and conversion in small steps) */

	protected function ajax()
	{
		global $request, $user;

		$response = new \phpbb\json_response();

		if (!check_form_key('slamb_avifmigrate'))
		{
			$response->send(['error' => $user->lang('FORM_INVALID')]);
		}

		$type = $request->variable('item_type', '');
		if ($type !== 'attach' && $type !== 'avatar')
		{
			$response->send(['error' => $user->lang('AVIFMIGRATE_ERR_TYPE')]);
		}

		if (function_exists('set_time_limit'))
		{
			@set_time_limit(0);
		}
		$do = $request->variable('do', '');

		if ($do === 'analyze')
		{
			$res = $this->conv->analyze($type, 200, self::AJAX_BUDGET);
			$st = $this->conv->stats($type);
			$response->send([
				'ready' => $res[0], 'broken' => $res[1], 'unsupported' => $res[2],
				'left'  => $st['new'],
			]);
		}

		if ($do === 'convert_one')
		{
			if (!$this->conv->supported())
			{
				$response->send(['error' => $user->lang('AVIFMIGRATE_ERR_NO_AVIF')]);
			}
			$id = $request->variable('item_id', 0);
			$r = $this->conv->run_one($type, $id);
			$rows = ($r && $r['status'] !== 'busy') ? $this->conv->rows_by_ids($type, [$id]) : [];
			if (!$rows)
			{
				$response->send(['error' => $user->lang('AVIFMIGRATE_ERR_NOT_READY')]);
			}
			$response->send(['item' => $this->row($rows[0])]);
		}

		if ($do === 'convert')
		{
			if (!$this->conv->supported())
			{
				$response->send(['error' => $user->lang('AVIFMIGRATE_ERR_NO_AVIF')]);
			}
			$limit = max(1, min(50, $request->variable('limit', 10)));
			$ids = [];
			$saved = 0;
			foreach ($this->conv->run($type, $limit, self::AJAX_BUDGET) as $r)
			{
				if ($r['status'] === 'busy')
				{
					continue;
				}
				$ids[] = (int) $r['item_id'];
				if (in_array($r['status'], \slamb\avifmigrate\core\converter::DONE, true))
				{
					$saved += max(0, (int) $r['size_before'] - (int) $r['size_after']);
				}
			}
			$items = [];
			foreach ($this->conv->rows_by_ids($type, $ids) as $r)
			{
				$items[] = $this->row($r);
			}
			$response->send([
				'items' => $items,
				'saved' => $saved,
				'left'  => $this->conv->remaining($type),
			]);
		}

		$response->send(['error' => 'unknown action']);
	}

	/* ------------------------------------------------------------ overview */

	protected function overview()
	{
		global $template, $user, $phpbb_root_path, $db;

		// environment checks
		$checks = [];
		$upload_dir = $phpbb_root_path . $this->config('upload_path');
		$avatar_dir = $phpbb_root_path . $this->config('avatar_path');
		$checks[] = ['avif', $this->conv->supported() ? 'ok' : 'fail', $this->conv->supported() ? \slamb\avifmigrate\core\avif_codec::engine() : $user->lang('AVIFMIGRATE_CHECK_AVIF_FAIL')];

		$res = $db->sql_query('SELECT COUNT(*) AS c FROM ' . EXTENSIONS_TABLE . " WHERE extension = 'avif'");
		$allowed = (int) $db->sql_fetchfield('c') > 0;
		$db->sql_freeresult($res);
		$checks[] = ['ext', $allowed ? 'ok' : 'fail', $allowed ? '' : $user->lang('AVIFMIGRATE_CHECK_EXT_FAIL')];

		$checks[] = ['upload', (is_dir($upload_dir) && is_writable($upload_dir)) ? 'ok' : 'fail', ''];
		$checks[] = ['avatar', (is_dir($avatar_dir) && is_writable($avatar_dir)) ? 'ok' : 'warn', ''];

		$free = function_exists('disk_free_space') ? @disk_free_space($upload_dir) : false; // some hosts disable it
		$checks[] = ['disk', ($free === false) ? 'warn' : ($free > 50 * 1048576 ? 'ok' : 'warn'), ($free === false) ? '' : get_formatted_filesize($free)];
		$checks[] = ['exif', function_exists('exif_read_data') ? 'ok' : 'warn', function_exists('exif_read_data') ? '' : $user->lang('AVIFMIGRATE_CHECK_EXIF_WARN')];
		$checks[] = ['memory', 'ok', (string) ini_get('memory_limit')];

		foreach ($checks as $c)
		{
			$template->assign_block_vars('checks', [
				'LABEL'  => $user->lang('AVIFMIGRATE_CHECK_' . strtoupper($c[0])),
				'STATE'  => $c[1],
				'DETAIL' => utf8_htmlspecialchars($c[2]),
			]);
		}

		// status table
		$st = ['attach' => $this->conv->stats('attach'), 'avatar' => $this->conv->stats('avatar')];
		$rows = [
			['new',         'new',         null],
			['ready',       'queued',      'ready'],
			['converted',   'ok',          'converted'],
			['skipped',     null,          'skipped'],
			['unsupported', 'unsupported', 'unsupported'],
			['broken',      'broken',      'broken'],
			['failed',      'failed',      'failed'],
		];
		foreach ($rows as $r)
		{
			$vars = ['LABEL' => $user->lang('AVIFMIGRATE_ST_' . strtoupper($r[0])), 'HINT' => $user->lang('AVIFMIGRATE_HINT_' . strtoupper($r[0]))];
			foreach (['attach' => 'A', 'avatar' => 'V'] as $t => $p)
			{
				if ($r[0] === 'skipped')
				{
					$n = $st[$t]['skipped_animated'] + $st[$t]['too_large'] + $st[$t]['not_smaller'];
				}
				else if ($r[0] === 'converted')
				{
					$n = $st[$t]['ok'] + $st[$t]['optimized'] + $st[$t]['skipped_avif']; // already AVIF and optimized PNGs count as converted
				}
				else
				{
					$n = $st[$t][$r[1]];
				}
				$vars[$p . '_COUNT'] = $n;
				$vars[$p . '_URL'] = ($r[2] !== null && $n > 0) ? $this->url('view=list&amp;filter=' . $r[2] . '&amp;type=' . $t) : '';
			}
			$template->assign_block_vars('status', $vars);
		}

		// savings
		$tot = ['count' => 0, 'before' => 0, 'after' => 0, 'saved' => 0, 'ready_count' => 0, 'ready_size' => 0, 'estimate' => 0];
		foreach (['attach', 'avatar'] as $t)
		{
			$s = $this->conv->savings($t);
			foreach ($tot as $k => $v)
			{
				$tot[$k] += $s[$k];
			}
			$template->assign_block_vars('savings', $this->savings_vars($t === 'avatar' ? $user->lang('AVIFMIGRATE_TYPE_AVATAR') : $user->lang('AVIFMIGRATE_TYPE_ATTACH'), $s));
		}
		$tot['percent'] = $tot['before'] > 0 ? round($tot['saved'] / $tot['before'] * 100) : 0;
		$template->assign_vars([
			'SAV_TOTAL_SAVED'   => get_formatted_filesize($tot['saved']),
			'SAV_TOTAL_PERCENT' => $tot['percent'],
			'SAV_TOTAL_COUNT'   => $tot['count'],
			'SAV_TOTAL_BEFORE'  => get_formatted_filesize($tot['before']),
			'SAV_TOTAL_AFTER'   => get_formatted_filesize($tot['after']),
			'SAV_TOTAL_EST'     => get_formatted_filesize($tot['estimate']),
			'S_SAV_ANY'         => $tot['count'] > 0,
			'S_SAV_EST'         => $tot['estimate'] > 0,
		]);

		foreach ($this->conv->recent(30) as $r)
		{
			$template->assign_block_vars('recent', $this->row($r));
		}

		$template->assign_vars([
			'S_AVIF_SUPPORTED' => $this->conv->supported(),
			'REMAIN_ATTACH'    => $this->conv->remaining('attach'),
			'REMAIN_AVATAR'    => $this->conv->remaining('avatar'),
			'NEW_ATTACH'       => $st['attach']['new'],
			'NEW_AVATAR'       => $st['avatar']['new'],
			'READY_ATTACH'     => $st['attach']['queued'],
			'READY_AVATAR'     => $st['avatar']['queued'],
			'U_ACTION'         => $this->u_action,
			'U_REVIEW'         => $this->url('view=list&amp;filter=ready&amp;type=attach'),
		]);
	}

	protected function savings_vars($label, array $s)
	{
		return [
			'LABEL'   => $label,
			'COUNT'   => $s['count'],
			'BEFORE'  => get_formatted_filesize($s['before']),
			'AFTER'   => get_formatted_filesize($s['after']),
			'SAVED'   => get_formatted_filesize($s['saved']),
			'PERCENT' => $s['percent'],
			'READY'   => $s['ready_count'],
			'EST'     => get_formatted_filesize($s['estimate']),
		];
	}

	protected function config($name)
	{
		global $config;
		return $config[$name];
	}

	/* ------------------------------------------------------------ review and delete */

	protected function review($ltype)
	{
		global $request, $template, $user, $phpbb_container, $db;

		$this->tpl_name = 'acp_avifmigrate_list';

		$filters = ['ready', 'converted', 'skipped', 'unsupported', 'broken', 'failed', 'all'];
		$filter = $request->variable('filter', 'ready');
		$filter = in_array($filter, $filters, true) ? $filter : 'ready';
		$start = max(0, $request->variable('start', 0));
		$per_page = 50;
		$sort = $request->variable('sort', 'id');
		$sort = in_array($sort, ['id', 'name', 'size', 'status', 'message', 'where', 'saved'], true) ? $sort : 'id';
		$dir = $request->variable('dir', 'asc') === 'desc' ? 'desc' : 'asc';
		$sq = '&amp;sort=' . $sort . '&amp;dir=' . $dir . '&amp;type=' . $ltype;

		if ($request->is_set_post('delete_selected'))
		{
			$ids = array_filter(array_map('intval', $request->variable('mark', [0])));
			if ($ids)
			{
				if (confirm_box(true))
				{
					if ($ltype === 'avatar')
					{
						/** @var \phpbb\avatar\manager $avm */
						$avm = $phpbb_container->get('avatar.manager');
						$res = $db->sql_query('SELECT user_id, user_avatar, user_avatar_type, user_avatar_width, user_avatar_height FROM ' . USERS_TABLE . ' WHERE ' . $db->sql_in_set('user_id', $ids));
						while ($u = $db->sql_fetchrow($res))
						{
							if ($u['user_avatar'] !== '')
							{
								$avm->handle_avatar_delete($db, $user, $avm->clean_row($u, 'user'), USERS_TABLE, 'user_');
							}
						}
						$db->sql_freeresult($res);
						$this->conv->forget_avatars($ids);
					}
					else
					{
						$phpbb_container->get('attachment.manager')->delete('attach', $ids);
						$this->conv->forget_attachments($ids);
					}
					trigger_error(sprintf($user->lang($ltype === 'avatar' ? 'AVIFMIGRATE_DELETED_AV' : 'AVIFMIGRATE_DELETED'), count($ids)) . adm_back_link($this->u_action . '&amp;view=list&amp;filter=' . $filter . '&amp;start=' . $start . $sq));
				}
				else
				{
					confirm_box(false, sprintf($user->lang($ltype === 'avatar' ? 'AVIFMIGRATE_DELETE_CONFIRM_AV' : 'AVIFMIGRATE_DELETE_CONFIRM'), count($ids)), build_hidden_fields([
						'view' => 'list', 'filter' => $filter, 'start' => $start, 'sort' => $sort, 'dir' => $dir, 'type' => $ltype,
						'mark' => $ids, 'delete_selected' => 1,
					]));
				}
			}
		}

		list($rows, $total) = $this->conv->review_list($filter, $start, $per_page, $sort, $dir, $ltype);
		foreach ($rows as $r)
		{
			$template->assign_block_vars('items', $this->row($r));
		}

		$phpbb_container->get('pagination')->generate_template_pagination($this->u_action . '&amp;view=list&amp;filter=' . $filter . $sq, 'pagination', 'start', $total, $per_page, $start);

		foreach ($filters as $f)
		{
			$template->assign_block_vars('filters', [
				'LABEL'  => $user->lang('AVIFMIGRATE_F_' . strtoupper($f)),
				'URL'    => $this->u_action . '&amp;view=list&amp;filter=' . $f . '&amp;type=' . $ltype,
				'ACTIVE' => $f === $filter,
			]);
		}

		foreach (['id', 'name', 'size', 'status', 'where', 'saved'] as $c)
		{
			$d = ($sort === $c && $dir === 'asc') ? 'desc' : 'asc';
			$template->assign_vars([
				'U_SORT_' . strtoupper($c) => $this->u_action . '&amp;view=list&amp;filter=' . $filter . '&amp;sort=' . $c . '&amp;dir=' . $d . '&amp;type=' . $ltype,
				'ARR_' . strtoupper($c)    => ($sort === $c) ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '',
			]);
		}

		$template->assign_vars([
			'TOTAL'           => $total,
			'U_BACK'          => $this->u_action,
			'U_T_ATTACH'      => $this->u_action . '&amp;view=list&amp;filter=' . $filter . '&amp;type=attach',
			'U_T_AVATAR'      => $this->u_action . '&amp;view=list&amp;filter=' . $filter . '&amp;type=avatar',
			'S_AVATARS'       => $ltype === 'avatar',
			'S_DELETE_ACTION' => $this->u_action . '&amp;view=list&amp;filter=' . $filter . '&amp;start=' . $start . $sq,
		]);
	}
}
