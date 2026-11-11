<?php

/**
 * Dossiq dossiq:teams:migrate command.
 *
 * Moves cases from an organisation role to the Nextcloud group that role
 * names (`organisatieRol.ncGroupId`), and lists every case it could not move
 * with the reason and, where one exists, a group that looks like the role.
 * The hint is for a person to confirm: the command never applies it.
 *
 * The repair step `MigrateCaseTeamsToGroups` runs the same service on
 * upgrade; this is how an administrator sees what was left and runs it again
 * after binding a role to its group.
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
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Team\CaseTeamMigration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run the team migration and list the cases it could not map.
 *
 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
class MigrateCaseTeamsCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param CaseTeamMigration $migration The one service that moves a case's team.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function __construct(
		private readonly CaseTeamMigration $migration,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:teams:migrate')
			->setDescription(
				'Move cases from an organisation role to the Nextcloud group the role names, and list the cases that could not be moved.'
			)
			->addOption(
				name: 'dry-run',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be moved and write nothing.'
			);
	}//end configure()

	/**
	 * Run the migration (or the dry run) and print the report.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Success when it ran, also when cases were left unmapped; failure when it could not run.
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$report = $this->migration->run(apply: $dryRun === false);

		if ($report['ran'] === false) {
			$output->writeln('<error>Nothing ran: ' . $report['skippedBecause'] . '.</error>');
			return Command::FAILURE;
		}

		$verb = 'Moved';
		if ($dryRun === true) {
			$verb = 'Would move';
		}

		$output->writeln($verb . ' ' . $report['converted'] . ' cases to their group.');
		$this->reportTotals(output: $output, report: $report);

		return $this->reportUnmapped(output: $output, unmapped: $report['unmapped']);
	}//end execute()

	/**
	 * Print how many cases already held a group, and how many could not be written.
	 *
	 * @param OutputInterface      $output The console.
	 * @param array<string, mixed> $report The migration's report.
	 *
	 * @return void
	 */
	private function reportTotals(OutputInterface $output, array $report): void {
		$output->writeln($report['alreadyGroup'] . ' cases already held a group.');
		if ($report['failed'] > 0) {
			$output->writeln('<error>' . $report['failed'] . ' cases could not be written; see the log.</error>');
		}
	}//end reportTotals()

	/**
	 * Print the cases that keep their value, with the hint for each.
	 *
	 * @param OutputInterface                  $output   The console.
	 * @param array<int, array<string, mixed>> $unmapped The cases left as they were.
	 *
	 * @return int The exit code, always success.
	 */
	private function reportUnmapped(OutputInterface $output, array $unmapped): int {
		if ($unmapped === []) {
			$output->writeln('No case was left with an organisation role as its team.');
			return Command::SUCCESS;
		}

		$output->writeln(
			count($unmapped) . ' cases keep their value. Set ncGroupId on the role, then run this command again. '
			. 'A hint is a group that looks like the role; it is not applied.'
		);

		$this->unmappedTable(output: $output, unmapped: $unmapped)->render();

		return Command::SUCCESS;
	}//end reportUnmapped()

	/**
	 * The table of cases left as they were.
	 *
	 * @param OutputInterface                  $output   The console.
	 * @param array<int, array<string, mixed>> $unmapped The cases left as they were.
	 *
	 * @return Table
	 */
	private function unmappedTable(OutputInterface $output, array $unmapped): Table {
		$table = new Table($output);
		$table->setHeaders(['Case', 'Title', 'Stored value', 'Role', 'Reason', 'Hint']);
		foreach ($unmapped as $row) {
			$table->addRow(
				[
					$row['case'],
					$row['title'],
					$row['value'],
					$row['roleName'],
					$row['reason'],
					implode(', ', $row['hints']),
				]
			);
		}

		return $table;
	}//end unmappedTable()
}//end class
