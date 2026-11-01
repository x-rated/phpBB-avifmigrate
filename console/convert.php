<?php
/**
 * AVIF migration - CLI conversion.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class convert extends \phpbb\console\command\command
{
	/** @var \slamb\avifmigrate\core\converter */
	protected $converter;

	public function __construct(\phpbb\user $user, \slamb\avifmigrate\core\converter $converter)
	{
		$user->add_lang_ext('slamb/avifmigrate', 'common');
		parent::__construct($user);
		$this->converter = $converter;
	}

	protected function configure()
	{
		$this
			->setName('avifmigrate:convert')
			->setDescription($this->user->lang('AVIFMIGRATE_CLI_D_CONVERT'))
			->addArgument('type', InputArgument::REQUIRED, $this->user->lang('AVIFMIGRATE_CLI_A_TYPE'))
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, $this->user->lang('AVIFMIGRATE_CLI_O_LIMIT'), 5)
			->addOption('all', null, InputOption::VALUE_NONE, $this->user->lang('AVIFMIGRATE_CLI_O_ALL'));
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$arg = $input->getArgument('type');
		if (!in_array($arg, ['attachments', 'avatars'], true))
		{
			$output->writeln('<error>' . $this->user->lang('AVIFMIGRATE_CLI_BAD_TYPE') . '</error>');
			return 2;
		}
		if (!$this->converter->supported())
		{
			$output->writeln('<error>' . $this->user->lang('AVIFMIGRATE_CLI_NO_GD') . '</error>');
			return 1;
		}

		$type = ($arg === 'avatars') ? 'avatar' : 'attach';
		$this->converter->normalize();
		$all = (bool) $input->getOption('all');
		$left = $all ? PHP_INT_MAX : max(1, (int) $input->getOption('limit'));
		if (function_exists('set_time_limit'))
		{
			@set_time_limit(0);
		}

		$done = 0;
		$saved = 0;
		while ($left > 0)
		{
			$batch = $this->converter->run($type, min(50, $left));
			if (!$batch)
			{
				break;
			}
			foreach ($batch as $r)
			{
				$left--;
				$done++;
				if (in_array($r['status'], \slamb\avifmigrate\core\converter::DONE, true))
				{
					$saved += $r['size_before'] - $r['size_after'];
					$output->writeln(sprintf('<info>%s</info>   %s #%d %s  %d -> %d B', $this->user->lang('AVIFMIGRATE_CLI_OK'), $r['item_type'], $r['item_id'], $r['item_name'], $r['size_before'], $r['size_after']));
				}
				else
				{
					$output->writeln(sprintf('<comment>%s</comment> %s #%d %s %s', $r['status'], $r['item_type'], $r['item_id'], $r['item_name'], $this->msg($r['message'])));
				}
			}
		}
		$output->writeln(sprintf($this->user->lang('AVIFMIGRATE_CLI_PROCESSED'), $done, $saved, $this->converter->remaining($type)));
		return 0;
	}

	protected function msg($message)
	{
		$key = 'AVIFMIGRATE_MSG_' . strtoupper(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $message), '_'));
		return isset($this->user->lang[$key]) ? $this->user->lang[$key] : $message;
	}
}
