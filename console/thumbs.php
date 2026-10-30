<?php
/**
 * AVIF migration - CLI thumbnail repair.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifmigrate\console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class thumbs extends \phpbb\console\command\command
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
			->setName('avifmigrate:thumbs')
			->setDescription($this->user->lang('AVIFMIGRATE_CLI_D_THUMBS'));
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if (!$this->converter->supported())
		{
			$output->writeln('<error>' . $this->user->lang('AVIFMIGRATE_CLI_NO_GD') . '</error>');
			return 1;
		}
		if (function_exists('set_time_limit'))
		{
			@set_time_limit(0);
		}
		$output->writeln(sprintf($this->user->lang('AVIFMIGRATE_CLI_THUMBS'), $this->converter->repair_thumbs()));
		return 0;
	}
}
