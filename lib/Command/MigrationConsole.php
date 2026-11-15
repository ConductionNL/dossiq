<?php

/**
 * Dossiq migration console.
 *
 * The console side the migrate commands share: their `--user` and
 * `--dry-run` options, the acting user they refuse to run without, and the
 * summary report whose exit code tells a caller whether every row moved.
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
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use Closure;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Options, acting user and report of one migrate command's run.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
final class MigrationConsole {

	/**
	 * Resolves the acting user.
	 *
	 * @var IUserManager
	 */
	public IUserManager $userManager;

	/**
	 * Console output.
	 *
	 * @var OutputInterface
	 */
	public OutputInterface $output;

	/**
	 * The command name the report opens with.
	 *
	 * @var string
	 */
	public string $command = '';

	/**
	 * What the report adds to the name on a dry run.
	 *
	 * @var string
	 */
	public string $dryRunNote = ' (dry run — nothing was written)';

	/**
	 * What separates a row's marker from its detail.
	 *
	 * @var string
	 */
	public string $rowSeparator = ' — ';

	/**
	 * Add `--user` and `--dry-run` to a migrate command.
	 *
	 * `--user` is REQUIRED and has no default. The created flows inherit that
	 * user's identity and organisation permanently, so guessing an owner would
	 * hand every migrated flow to whoever the guess landed on.
	 *
	 * @param Command $command The command being configured.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function addOptions(Command $command): void {
		$command
			->addOption(
				name: 'user',
				mode: InputOption::VALUE_REQUIRED,
				description: 'UID the created flows belong to; also supplies the active organisation.'
			)
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be created or updated, and write nothing.'
			);
	}//end addOptions()

	/**
	 * Run one migration as the `--user`, and report it.
	 *
	 * @param InputInterface $input   Console input.
	 * @param Closure        $migrate The migrator's migrate(IUser $user, bool $dryRun): array.
	 *
	 * @return int Symfony command exit code: INVALID without a known user.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function run(InputInterface $input, Closure $migrate): int {
		$user = $this->actingUser(input: $input);
		if ($user === null) {
			return Command::INVALID;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$summary = $migrate($user, $dryRun);

		return $this->report(summary: $summary, dryRun: $dryRun);
	}//end run()

	/**
	 * The user `--user` names, or null after saying why there is none.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	private function actingUser(InputInterface $input): ?IUser {
		$uid = (string)($input->getOption('user') ?? '');
		if ($uid === '') {
			$this->output->writeln('<error>--user is required: a flow needs an owner and an organisation.</error>');
			return null;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			$this->output->writeln('<error>No such user: ' . $uid . '</error>');
			return null;
		}

		return $user;
	}//end actingUser()

	/**
	 * Print the summary, and decide the exit code from it.
	 *
	 * A failed row exits non-zero. Reporting success over a partial migration is
	 * how a caller ends up believing data moved that did not.
	 *
	 * @param array<string, mixed> $summary The migrator's summary.
	 * @param bool                 $dryRun  Whether this was a dry run.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	private function report(array $summary, bool $dryRun): int {
		if (isset($summary['note']) === true && $summary['note'] !== '') {
			$this->output->writeln('<comment>' . (string)$summary['note'] . '</comment>');
			return Command::SUCCESS;
		}

		$this->printSummary(summary: $summary, dryRun: $dryRun);

		if ($summary['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end report()

	/**
	 * Print the counts and one line per row.
	 *
	 * @param array<string, mixed> $summary The migrator's summary.
	 * @param bool                 $dryRun  Whether this was a dry run.
	 *
	 * @return void
	 */
	private function printSummary(array $summary, bool $dryRun): void {
		$prefix = $this->command;
		if ($dryRun === true) {
			$prefix .= $this->dryRunNote;
		}

		$this->output->writeln('<info>' . $prefix . '</info>');
		foreach (['total', 'created', 'updated', 'skipped', 'failed'] as $key) {
			$this->output->writeln('  ' . str_pad($key, 8) . ' = ' . (string)$summary[$key]);
		}

		foreach ($summary['rows'] as $row) {
			$this->output->writeln('  [' . $row['outcome'] . '] ' . $row['marker'] . $this->rowSeparator . $row['detail']);
		}
	}//end printSummary()
}//end class
