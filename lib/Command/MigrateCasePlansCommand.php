<?php

/**
 * `occ dossiq:cmmn:migrate-case-plans`: drain `casePlanState` blobs into
 * OpenRegister plan-item rows.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\CasePlanDrain\CasePlanMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the drain for the named cases, or for every CMMN case.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */
class MigrateCasePlansCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param CasePlanMigrationService $migration The drain.
	 */
	public function __construct(
		private readonly CasePlanMigrationService $migration,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, arguments and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:cmmn:migrate-case-plans')
			->setDescription(
				'Move every CMMN case\'s `casePlanState` blob into OpenRegister plan-item rows. '
				. 'Creates only missing rows, verifies every recorded state, then clears the blob. '
				. 'A case it cannot map keeps its blob and is reported.'
			)
			->addArgument(
				name: 'case',
				mode: InputArgument::IS_ARRAY,
				description: 'Case uuids to migrate; every CMMN case when none is named.'
			)
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be migrated, and write nothing.'
			)
			->addOption(
				name: 'strict',
				mode: InputOption::VALUE_NONE,
				description: 'Exit non-zero when any case is unmappable.'
			);
	}//end configure()

	/**
	 * Run the drain and print one line per case.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return integer 0, or 1 under --strict when a case was unmappable.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ($input->getOption('dry-run') === true);
		$cases = $input->getArgument('case');
		if (is_array($cases) === false) {
			$cases = [];
		}

		$reports = [];
		foreach ($cases as $caseId) {
			$reports[] = $this->migration->migrateCaseById(caseId: (string)$caseId, dryRun: $dryRun);
		}

		if ($cases === []) {
			$reports = $this->migration->migrateAll(dryRun: $dryRun);
		}

		$unmappable = 0;
		foreach ($reports as $report) {
			if ($report['outcome'] === CasePlanMigrationService::UNMAPPABLE) {
				$unmappable++;
			}

			$output->writeln(
				sprintf(
					'%s %s (created %d, existing %d): %s',
					$report['outcome'],
					$report['caseId'],
					$report['created'] ?? 0,
					$report['existing'] ?? 0,
					$report['reason']
				)
			);
		}

		$output->writeln(sprintf('%d case(s) reported, %d unmappable.', count($reports), $unmappable));
		if ($unmappable > 0 && $input->getOption('strict') === true) {
			return 1;
		}

		return 0;
	}//end execute()
}//end class
