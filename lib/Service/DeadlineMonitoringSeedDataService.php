<?php

/**
 * Dossiq Termijnbewaking Seed Data Service.
 *
 * Seeds the three demo `TermijnDefinitie` rows
 * (Omgevingsvergunning-regulier 56d, Wmo-aanvraag 42d, Woo-verzoek 28d
 * with €15/day max €500 regime) from
 * `lib/Settings/termijnbewaking_seed_data.json` into OpenRegister. The
 * seed is idempotent: an existing definition with the same id is skipped,
 * except that a key the seed declares and the stored row lacks is added to
 * it (never overwritten), so a setting a later release adds to a shipped
 * definition reaches the installs that seeded it before.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Seeds three demo TermijnDefinitie rows into OpenRegister.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class DeadlineMonitoringSeedDataService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the termijn-definitie example data.
	 *
	 * @return array<string, mixed> Result with 'success' and either 'message' or per-kind counts.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
	 */
	public function seed(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'message' => 'OpenRegister is not available'];
		}

		$run = $this->configuredRun(objectService: $objectService);
		if ($run === null) {
			return ['success' => false, 'message' => 'Termijn schemas not configured'];
		}

		$data = $this->seedData();
		if (is_string($data) === true) {
			return ['success' => false, 'message' => $data];
		}

		$run->existing = $this->existingDefinitions(run: $run);
		$this->insertDefinitions(run: $run, rows: ($data['termijnDefinities'] ?? []));

		$this->logger->info('Dossiq termijnbewaking: seed complete', $run->counts);

		return array_merge(['success' => true], $run->counts);
	}//end seed()

	/**
	 * The pass over the configured register and schema, or null when either is unset.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 *
	 * @return DefinitionSeedRun|null The pass.
	 */
	private function configuredRun(object $objectService): ?DefinitionSeedRun {
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		$run = new DefinitionSeedRun(objectService: $objectService, register: $register);
		$run->schema = $schema;

		return $run;
	}//end configuredRun()

	/**
	 * The decoded seed file, or why it could not be read.
	 *
	 * @return array<string, mixed>|string The seed, or the message saying what is wrong with it.
	 */
	private function seedData(): array|string {
		$seedPath = __DIR__ . '/../Settings/termijnbewaking_seed_data.json';
		if (file_exists($seedPath) === false) {
			return 'Seed file not found';
		}

		$data = json_decode((string)file_get_contents($seedPath), true);
		if (is_array($data) === false) {
			return 'Invalid seed JSON';
		}

		return $data;
	}//end seedData()

	/**
	 * Persist the seed rows that are not present yet, counting each on the run.
	 *
	 * @param DefinitionSeedRun                $run  The pass.
	 * @param array<int, array<string, mixed>> $rows The seed's definitions.
	 *
	 * @return void
	 */
	private function insertDefinitions(DefinitionSeedRun $run, array $rows): void {
		foreach ($rows as $row) {
			$this->insertDefinition(run: $run, row: $row);
		}
	}//end insertDefinitions()

	/**
	 * Write one seed row, backfill a stored one with the keys it lacks, or skip it.
	 *
	 * @param DefinitionSeedRun    $run The pass.
	 * @param array<string, mixed> $row The seed row.
	 *
	 * @return void
	 */
	private function insertDefinition(DefinitionSeedRun $run, array $row): void {
		$rowId = (string)($row['id'] ?? '');
		$kind = 'definities';
		if ($rowId !== '' && isset($run->existing[$rowId]) === true) {
			$row = $this->backfilled(stored: $run->existing[$rowId], row: $row);
			$kind = 'backfilled';
		}

		if ($row === null) {
			$run->counts['skipped']++;
			return;
		}

		if ($this->saved(run: $run, row: $row) === true) {
			$run->counts[$kind]++;
			return;
		}

		$run->counts['failed']++;
	}//end insertDefinition()

	/**
	 * A stored row with only the keys it lacks added, or null when it lacks none.
	 *
	 * Only the keys the stored row lacks. An administrator's edit of a key the
	 * seed also ships is theirs and stays.
	 *
	 * @param array<string, mixed> $stored The stored definition.
	 * @param array<string, mixed> $row    The seed row.
	 *
	 * @return array<string, mixed>|null The row to write, or null to skip.
	 */
	private function backfilled(array $stored, array $row): ?array {
		$missing = array_diff_key($row, $stored);
		if ($missing === []) {
			return null;
		}

		return array_merge($stored, $missing);
	}//end backfilled()

	/**
	 * Save one row, logging a refusal.
	 *
	 * A refusal is COUNTED by the caller, not merely logged. A refused row
	 * that only logs leaves the summary success-shaped, which is how a fresh
	 * install shipped zero TermijnDefinities while reporting
	 * "0 definities (0 overgeslagen)" as if that were fine.
	 *
	 * @param DefinitionSeedRun    $run The pass.
	 * @param array<string, mixed> $row The row to write.
	 *
	 * @return bool Whether the row was written.
	 */
	private function saved(DefinitionSeedRun $run, array $row): bool {
		try {
			$this->saveObjectAsArray(objectService: $run->objectService, register: $run->register, schema: $run->schema, object: $row);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq termijnbewaking seed: row failed',
				['id' => (string)($row['id'] ?? ''), 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end saved()

	/**
	 * Collect existing TermijnDefinitie rows, by id, for skip and backfill detection.
	 *
	 * @param DefinitionSeedRun $run The pass.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function existingDefinitions(DefinitionSeedRun $run): array {
		if (method_exists($run->objectService, 'findObjects') === false) {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $run->objectService, register: $run->register, schema: $run->schema);
		} catch (\Throwable $e) {
			return [];
		}

		$byId = [];
		foreach ($rows as $row) {
			$rowId = (string)($row['id'] ?? '');
			if ($rowId !== '') {
				$byId[$rowId] = $row;
			}
		}

		return $byId;
	}//end existingDefinitions()
}//end class
