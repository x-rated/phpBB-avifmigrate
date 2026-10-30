<?php
/**
 * AVIF migration - CLI analysis.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class analyze extends \phpbb\console\command\command
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
			->setName('avifmigrate:analyze')
			->setDescription($this->user->lang('AVIFMIGRATE_CLI_D_ANALYZE'))
			->addArgument('type', InputArgument::REQUIRED, $this->user->lang('AVIFMIGRATE_CLI_A_TYPE'))
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, $this->user->lang('AVIFMIGRATE_CLI_O_LIMIT'), 500)
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
		$type = ($arg === 'avatars') ? 'avatar' : 'attach';
		$this->converter->normalize();
		$all = (bool) $input->getOption('all');
		$left = $all ? PHP_INT_MAX : max(1, (int) $input->getOption('limit'));
		if (function_exists('set_time_limit'))
		{
			@set_time_limit(0);
		}

		$ready = 0;
		$broken = 0;
		$unsupported = 0;
		while ($left > 0)
		{
			$res = $this->converter->analyze($type, min(500, $left));
			if ($res[0] + $res[1] + $res[2] === 0)
			{
				break;
			}
			$ready += $res[0];
			$broken += $res[1];
			$unsupported += $res[2];
			$left -= $res[0] + $res[1] + $res[2];
		}
		$output->writeln(sprintf($this->user->lang('AVIFMIGRATE_CLI_ANALYZED'), $ready, $broken, $unsupported));
		return 0;
	}
}
