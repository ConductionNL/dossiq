<?php

/**
 * Dossiq occ command: import another app's records as cases
 *
 * `occ dossiq:case:import-records <caseType> [--import=<key>] [--dry-run]`
 * runs the record imports a case type declares under `recordImports`
 * (case-record-import) and prints, per import, what it imported, what had
 * already moved, what failed and why, and how many records have not moved.
 * A source app that is not installed is said so, answers zeros and exits 0.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Import\CaseRecordImport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a case type's declared record imports from occ.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */
class ImportCaseRecordsCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param CaseRecordImport $import The generic import.
	 */
	public function __construct(
		private readonly CaseRecordImport $import,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, argument and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:case:import-records')
			->setDescription(description: 'Import another app\'s stored records as cases, by the case type\'s recordImports declaration.')
			->addArgument(name: 'caseType', mode: InputArgument::REQUIRED, description: 'The case type, by uuid or identifier.')
			->addOption(
				name: 'import',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'Run only the declared import with this key.',
				default: ''
			)
			->addOption(name: 'dry-run', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Count what has not moved; write nothing.');
	}//end configure()

	/**
	 * Run the imports and print their numbers.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0, or 1 when a record failed or the case type was not found.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$caseType = (string)$input->getArgument('caseType');
		$importKey = (string)$input->getOption('import');
		$answer = match ((bool)$input->getOption('dry-run')) {
			true => $this->import->dryRun(caseType: $caseType, importKey: $importKey),
			false => $this->import->run(caseType: $caseType, importKey: $importKey),
		};

		if ($answer['caseType'] === '') {
			$output->writeln('<error>Case type ' . $caseType . ' was not found, or OpenRegister is not available.</error>');
			return 1;
		}

		if ($answer['imports'] === []) {
			$output->writeln('Case type ' . $caseType . ' declares no record import to run.');
			return 0;
		}

		$failed = false;
		foreach ($answer['imports'] as $import) {
			$failed = ($this->report(output: $output, import: $import) || $failed);
		}

		if ($failed === true) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Print one import's numbers.
	 *
	 * @param OutputInterface      $output The output.
	 * @param array<string, mixed> $import One import's answer.
	 *
	 * @return bool True when a record failed.
	 */
	private function report(OutputInterface $output, array $import): bool {
		$key = (string)$import['key'];
		if ($import['installed'] === false) {
			$output->writeln($key . ': ' . (string)$import['sourceApp'] . ' is not installed, nothing to import.');
			return false;
		}

		$output->writeln(
			sprintf(
				'%s: %d imported, %d already imported, %d failed, %d not moved.',
				$key,
				$import['imported'],
				$import['alreadyImported'],
				count($import['failed']),
				$import['unmigrated']
			)
		);
		foreach ($import['migrated'] as $row) {
			$output->writeln('  moved ' . $row['reference'] . ' to case ' . $row['caseId'] . ' (source timer ' . $row['sourceTimer'] . ' still runs)');
		}

		foreach ($import['failed'] as $row) {
			$output->writeln('  <error>failed ' . $row['reference'] . ' (' . $row['requestId'] . '): ' . $row['reason'] . '</error>');
		}

		return $import['failed'] !== [];
	}//end report()
}//end class
