<?php

/**
 * Dossiq MoveDecisionTablesToOpenRegister repair step.
 *
 * Copies the decision tables dossiq stored in its own `decisionTable` schema
 * into OpenRegister's `decision-tables` register (openregister#4572), keyed by
 * {@see StoredDecisionTables::keyFor()} and owned by dossiq, so that flow steps
 * name them by `tableRef` and recommendations evaluate them through
 * OpenRegister's DecisionTableStore.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves dossiq's stored decision tables into OpenRegister's register.
 *
 * A COPY, not a cut: the old rows stay where they are, so a refused table
 * loses nothing and a second run moves what the first could not. A table
 * already stored under its key is kept as it is: an administrator may have
 * edited it in OpenRegister since. A DISABLED table is not moved: dossiq never
 * evaluated it, and OpenRegister has no switch, so moving it would switch it on.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */
class MoveDecisionTablesToOpenRegister implements IRepairStep {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and the old schema.
	 * @param StoredDecisionTables $tables OpenRegister's register.
	 * @param LoggerInterface $logger Names every table that did not move.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StoredDecisionTables $tables,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function getName(): string {
		return 'Move dossiq decision tables into OpenRegister\'s decision-tables register';
	}//end getName()

	/**
	 * Run the step.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function run(IOutput $output): void {
		$rows = $this->oldRows();
		if ($rows === []) {
			$output->info('MoveDecisionTablesToOpenRegister: no dossiq decision tables to move.');
			return;
		}

		try {
			// Probe before writing: an OpenRegister that has not imported the
			// register yet answers a throw here, and the next upgrade retries.
			$this->tables->idOf(storedKey: StoredDecisionTables::KEY_PREFIX . 'probe');
		} catch (Throwable $e) {
			$this->logger->warning(
				'MoveDecisionTablesToOpenRegister: OpenRegister\'s decision-tables register is not readable; the tables stay in dossiq until the next upgrade.',
				['exception' => $e->getMessage()]
			);
			$output->warning('MoveDecisionTablesToOpenRegister: OpenRegister\'s decision-tables register is not there yet; nothing moved.');
			return;
		}

		$counts = ['moved' => 0, 'kept' => 0, 'disabled' => 0, 'failed' => 0];
		foreach ($rows as $row) {
			++$counts[$this->moveOne(row: $row)];
		}

		$output->info(
			sprintf(
				'MoveDecisionTablesToOpenRegister: %d moved, %d already in OpenRegister, %d disabled and left in dossiq, %d refused.',
				$counts['moved'],
				$counts['kept'],
				$counts['disabled'],
				$counts['failed']
			)
		);
		if ($counts['failed'] > 0) {
			$output->warning('MoveDecisionTablesToOpenRegister: ' . $counts['failed'] . ' table(s) were refused (see the log); they stay in dossiq.');
		}
	}//end run()

	/**
	 * Move one table.
	 *
	 * @param array<string, mixed> $row The stored dossiq table.
	 *
	 * @return string moved, kept, disabled or failed.
	 */
	private function moveOne(array $row): string {
		if (($row['enabled'] ?? true) === false) {
			return 'disabled';
		}

		$key = $this->tables->keyFor((string)($row['key'] ?? ''));
		if ($key === '') {
			$this->logger->warning('MoveDecisionTablesToOpenRegister: a table without a key cannot be named; left in dossiq.', ['name' => $row['name'] ?? '']);
			return 'failed';
		}

		try {
			if ($this->tables->idOf(storedKey: $key) !== null) {
				return 'kept';
			}

			$this->tables->save(table: ['key' => $key] + $row);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MoveDecisionTablesToOpenRegister: OpenRegister refused a table; left in dossiq.',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'moved';
	}//end moveOne()

	/**
	 * The tables in dossiq's own schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function oldRows(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('decision_table_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => 500],
			);
		} catch (Throwable $e) {
			$this->logger->warning('MoveDecisionTablesToOpenRegister: the dossiq decision tables could not be read.', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end oldRows()
}//end class
