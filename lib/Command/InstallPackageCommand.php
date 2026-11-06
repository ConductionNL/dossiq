<?php

/**
 * Dossiq command: list and install case definition packages.
 *
 * `occ dossiq:package:install --list` shows every shipped package with its
 * version and the version installed here. `occ dossiq:package:install <id>`
 * installs one, including a package that only installs on request, and
 * `--all` installs every package. `--root` reads the packages from another
 * directory, which is how CI installs the neutral fixture package.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\SettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists and installs case definition packages.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class InstallPackageCommand extends Command {

	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param PackageInstaller $installer The package installer.
	 * @param SettingsService  $settings  Resolves the OpenRegister object service.
	 */
	public function __construct(
		private readonly PackageInstaller $installer,
		private readonly SettingsService $settings,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, arguments and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:package:install')
			->setDescription('List or install case definition packages (idempotent).')
			->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The package ids to install.')
			->addOption('all', null, InputOption::VALUE_NONE, 'Install every package, including on-request ones.')
			->addOption('list', null, InputOption::VALUE_NONE, 'List the packages and what is installed.')
			->addOption('root', null, InputOption::VALUE_REQUIRED, 'Read the packages from this directory.')
			->addOption(
				'over-local-changes',
				null,
				InputOption::VALUE_NONE,
				'Also give rows the package shipped and somebody changed here the keys the package ships'
				. ' (other keys keep their value), even when the package is current.'
			);
	}//end configure()

	/**
	 * List or install, and exit non-zero when any package was refused or failed.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return integer The exit code.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$installer = $this->installer;
		$root = (string)($input->getOption('root') ?? '');
		if ($root !== '') {
			$installer = $installer->fromRoot(root: (string)realpath($root));
		}

		if ($input->getOption('list') === true) {
			foreach ($installer->catalogue() as $entry) {
				$installed = $entry['installed'];
				if ($installed === '') {
					$installed = '-';
				}

				$valid = '';
				if ($entry['valid'] === false) {
					$valid = ' INVALID';
				}

				$output->writeln("{$entry['id']}\t{$entry['version']}\t{$entry['install']}\tinstalled {$installed}{$valid}");
			}

			return Command::SUCCESS;
		}

		$ids = $this->idsToInstall(input: $input, installer: $installer);
		if ($ids === []) {
			$output->writeln('<error>Name a package id, or pass --all or --list.</error>');
			return Command::FAILURE;
		}

		// An occ run has no session, and OpenRegister refuses `create` for 'Anonymous'.
		$exit = Command::FAILURE;
		$this->withSystemIdentity(
			objectService: $this->settings->getObjectService(),
			work: function () use ($installer, $ids, $input, $output, &$exit): void {
				$exit = $this->installAll(
					installer: $installer,
					ids: $ids,
					overLocalChanges: ($input->getOption('over-local-changes') === true),
					output: $output
				);
			}
		);

		return $exit;
	}//end execute()

	/**
	 * The ids the input asks for.
	 *
	 * @param InputInterface   $input     The input.
	 * @param PackageInstaller $installer The installer, for --all.
	 *
	 * @return array<int, string> The ids.
	 */
	private function idsToInstall(InputInterface $input, PackageInstaller $installer): array {
		if ($input->getOption('all') === true) {
			return array_column($installer->catalogue(), 'id');
		}

		return array_values(array_map('strval', (array)$input->getArgument('ids')));
	}//end idsToInstall()

	/**
	 * Install each id and report it.
	 *
	 * @param PackageInstaller   $installer        The installer.
	 * @param array<int, string> $ids              The ids.
	 * @param boolean            $overLocalChanges Whether rows changed here take the package's keys.
	 * @param OutputInterface    $output           The output.
	 *
	 * @return integer The exit code.
	 */
	private function installAll(PackageInstaller $installer, array $ids, bool $overLocalChanges, OutputInterface $output): int {
		$exit = Command::SUCCESS;
		foreach ($ids as $id) {
			$outcome = match ($overLocalChanges) {
				true => $installer->installOverLocalChanges(id: $id),
				false => $installer->install(id: $id),
			};
			$output->writeln("{$outcome['package']} {$outcome['version']}: {$outcome['status']}. {$outcome['message']}");
			if (isset($outcome['refreshed']) === true) {
				$kept = array_map('strval', (array)($outcome['kept'] ?? []));
				$keptIds = '';
				if ($kept !== []) {
					$keptIds = ': ' . implode(', ', $kept);
				}

				$output->writeln('  ' . (int)$outcome['refreshed'] . ' row(s) refreshed, ' . count($kept) . ' kept as this instance holds them' . $keptIds);
			}

			foreach ((array)($outcome['errors'] ?? []) as $error) {
				$output->writeln('  ' . $error);
			}

			if ($outcome['status'] !== PackageInstaller::INSTALLED && $outcome['status'] !== PackageInstaller::CURRENT) {
				$exit = Command::FAILURE;
			}
		}

		return $exit;
	}//end installAll()
}//end class
