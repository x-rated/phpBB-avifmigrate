<?php
/**
 * AVIF migration - ACP module info.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\acp;

class main_info
{
	public function module()
	{
		return [
			'filename' => '\slamb\avifmigrate\acp\main_module',
			'title'    => 'ACP_AVIFMIGRATE',
			'modes'    => [
				'main' => [
					'title' => 'ACP_AVIFMIGRATE_MAIN',
					'auth'  => 'ext_slamb/avifmigrate && acl_a_board',
					'cat'   => ['ACP_AVIFMIGRATE'],
				],
			],
		];
	}
}
