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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
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
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-01-schemas-and-seed/tasks.md
	 */
	public function seed(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'message' => 'OpenRegister is not available'];
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($register === '' || $schema === '') {
			return ['success' => false, 'message' => 'Termijn schemas not configured'];
		}

		$seedPath = __DIR__ . '/../Settings/termijnbewaking_seed_data.json';
		if (file_exists($seedPath) === false) {
			return ['success' => false, 'message' => 'Seed file not found'];
		}

		$data = json_decode((string)file_get_contents($seedPath), true);
		if (is_array($data) === false) {
			return ['success' => false, 'message' => 'Invalid seed JSON'];
		}

		$existing = $this->existingDefinitions(objectService: $objectService, register: $register, schema: $schema);

		$counts = $this->insertDefinitions(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			data: $data,
			existing: $existing,
		);

		$this->logger->info('Dossiq termijnbewaking: seed complete', $counts);

		return array_merge(['success' => true], $counts);
	}//end seed()

	/**
	 * Persist the seed rows that are not present yet.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string, mixed> $data The decoded seed file.
	 * @param array<string, array<string, mixed>> $existing Already-seeded definitions, by id.
	 *
	 * @return array<string, int> Per-kind counts.
	 */
	private function insertDefinitions(
		object $objectService,
		string $register,
		string $schema,
		array $data,
		array $existing,
	): array {
		$counts = ['definities' => 0, 'skipped' => 0, 'backfilled' => 0, 'failed' => 0];

		foreach (($data['termijnDefinities'] ?? []) as $row) {
			$rowId = (string)($row['id'] ?? '');
			$kind = 'definities';
			if ($rowId !== '' && isset($existing[$rowId]) === true) {
				// Only the keys the stored row lacks. An administrator's edit of
				// a key the seed also ships is theirs and stays.
				$missing = array_diff_key($row, $existing[$rowId]);
				if ($missing === []) {
					$counts['skipped']++;
					continue;
				}

				$row = array_merge($existing[$rowId], $missing);
				$kind = 'backfilled';
			}

			try {
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $row);
				$counts[$kind]++;
			} catch (\Throwable $e) {
				// COUNTED, not merely logged. A refused row that only logs
				// leaves the summary success-shaped, which is how a fresh
				// install shipped zero TermijnDefinities while reporting
				// "0 definities (0 overgeslagen)" as if that were fine.
				$counts['failed']++;
				$this->logger->warning(
					'Dossiq termijnbewaking seed: row failed',
					['id' => $rowId, 'error' => $e->getMessage()]
				);
			}
		}

		return $counts;
	}//end insertDefinitions()

	/**
	 * Collect existing TermijnDefinitie rows, by id, for skip and backfill detection.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function existingDefinitions(object $objectService, string $register, string $schema): array {
		if (method_exists($objectService, 'findObjects') === false) {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema);
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
