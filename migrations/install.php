<?php
/**
 * AVIF migration - installation migration.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\migrations;

class install extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'avifmigrate_log');
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'avifmigrate_log' => [
					'COLUMNS' => [
						'log_id'      => ['UINT', null, 'auto_increment'],
						'item_type'   => ['VCHAR:10', ''],
						'item_id'     => ['UINT', 0],
						'item_name'   => ['VCHAR_UNI:255', ''],
						'status'      => ['VCHAR:20', ''],
						'message'     => ['VCHAR_UNI:255', ''],
						'size_before' => ['BINT', 0],
						'size_after'  => ['BINT', 0],
						'post_id'     => ['UINT', 0],
						'topic_id'    => ['UINT', 0],
						'in_message'  => ['BOOL', 0],
						'user_id'     => ['UINT', 0],
						'log_time'    => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'log_id',
					'KEYS' => [
						'item' => ['UNIQUE', ['item_type', 'item_id']],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [$this->table_prefix . 'avifmigrate_log'],
		];
	}

	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_AVIFMIGRATE']],
			['module.add', ['acp', 'ACP_AVIFMIGRATE', [
				'module_basename' => '\slamb\avifmigrate\acp\main_module',
				'modes'           => ['main'],
			]]],
		];
	}
}
