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
		$run = $this->runFor(input: $input, output: $output);
		if ($input->getOption('list') === true) {
			return $this->printCatalogue(run: $run);
		}

		$run->ids = $this->idsToInstall(input: $input, installer: $run->installer);
		if ($run->ids === []) {
			$output->writeln('<error>Name a package id, or pass --all or --list.</error>');
			return Command::FAILURE;
		}

		return $this->installAsSystem(run: $run);
	}//end execute()

	/**
	 * The run: the installer (over `--root` when given), the overwrite flag and the console.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console.
	 *
	 * @return PackageInstallRun
	 */
	private function runFor(InputInterface $input, OutputInterface $output): PackageInstallRun {
		$run = new PackageInstallRun();
		$run->installer = $this->installer;
		$root = (string)($input->getOption('root') ?? '');
		if ($root !== '') {
			$run->installer = $run->installer->fromRoot(root: (string)realpath($root));
		}

		$run->overLocalChanges = ($input->getOption('over-local-changes') === true);
		$run->output = $output;
		return $run;
	}//end runFor()

	/**
	 * Print one line per package in the catalogue.
	 *
	 * @param PackageInstallRun $run The run.
	 *
	 * @return int The exit code, always success.
	 */
	private function printCatalogue(PackageInstallRun $run): int {
		foreach ($run->installer->catalogue() as $entry) {
			$run->output->writeln($this->catalogueLine(entry: $entry));
		}

		return Command::SUCCESS;
	}//end printCatalogue()

	/**
	 * A catalogue entry as its line: id, version, install mode, installed version, and INVALID when it is.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string
	 */
	private function catalogueLine(array $entry): string {
		$installed = $entry['installed'];
		if ($installed === '') {
			$installed = '-';
		}

		$valid = '';
		if ($entry['valid'] === false) {
			$valid = ' INVALID';
		}

		return "{$entry['id']}\t{$entry['version']}\t{$entry['install']}\tinstalled {$installed}{$valid}";
	}//end catalogueLine()

	/**
	 * Install as the system identity: an occ run has no session, and
	 * OpenRegister refuses `create` for 'Anonymous'.
	 *
	 * @param PackageInstallRun $run The run.
	 *
	 * @return int The exit code.
	 */
	private function installAsSystem(PackageInstallRun $run): int {
		$exit = Command::FAILURE;
		$this->withSystemIdentity(
			objectService: $this->settings->getObjectService(),
			work: function () use ($run, &$exit): void {
				$exit = $this->installAll(run: $run);
			}
		);

		return $exit;
	}//end installAsSystem()

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
	 * Every package is attempted; one that did not install fails the run.
	 *
	 * @param PackageInstallRun $run The run: installer, ids, overwrite flag and console.
	 *
	 * @return integer The exit code.
	 */
	private function installAll(PackageInstallRun $run): int {
		$exit = Command::SUCCESS;
		foreach ($run->ids as $id) {
			if ($this->installOne(run: $run, id: $id) === false) {
				$exit = Command::FAILURE;
			}
		}

		return $exit;
	}//end installAll()

	/**
	 * Install one package and print what happened to it.
	 *
	 * @param PackageInstallRun $run The run.
	 * @param string            $id  The package.
	 *
	 * @return bool True when it is installed or already current.
	 */
	private function installOne(PackageInstallRun $run, string $id): bool {
		$outcome = match ($run->overLocalChanges) {
			true => $run->installer->installOverLocalChanges(id: $id),
			false => $run->installer->install(id: $id),
		};
		$run->output->writeln("{$outcome['package']} {$outcome['version']}: {$outcome['status']}. {$outcome['message']}");
		$this->printRefresh(output: $run->output, outcome: $outcome);
		foreach ((array)($outcome['errors'] ?? []) as $error) {
			$run->output->writeln('  ' . $error);
		}

		return $outcome['status'] === PackageInstaller::INSTALLED || $outcome['status'] === PackageInstaller::CURRENT;
	}//end installOne()

	/**
	 * Print how many rows were refreshed and which were kept, when the installer says.
	 *
	 * @param OutputInterface      $output  The console.
	 * @param array<string, mixed> $outcome The installer's outcome.
	 *
	 * @return void
	 */
	private function printRefresh(OutputInterface $output, array $outcome): void {
		if (isset($outcome['refreshed']) === false) {
			return;
		}

		$kept = array_map('strval', (array)($outcome['kept'] ?? []));
		$keptIds = '';
		if ($kept !== []) {
			$keptIds = ': ' . implode(', ', $kept);
		}

		$output->writeln('  ' . (int)$outcome['refreshed'] . ' row(s) refreshed, ' . count($kept) . ' kept as this instance holds them' . $keptIds);
	}//end printRefresh()
}//end class
