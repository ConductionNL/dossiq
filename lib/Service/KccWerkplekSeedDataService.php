<?php

/**
 * Dossiq KCC-Werkplek Seed Data Service.
 *
 * Seeds the default KCC quick-actions (status terugkoppelen, nieuwe zaak,
 * klacht registreren, doorverbinden, bel terug inplannen) and two example
 * belplannen (algemeen gemeentenummer with keuzemenu + vaardigheid routing,
 * and a meldingen-nummer) from `lib/Settings/kcc_werkplek_seed_data.json`
 * into OpenRegister. The seed is idempotent: an existing object with the same
 * id is skipped.
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
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\Contracts\KccWerkplekSeedDataServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the default KCC quick-actions and example belplannen into OpenRegister.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
 */
class KccWerkplekSeedDataService implements KccWerkplekSeedDataServiceInterface {
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
	 * Seed the default KCC quick-actions and example belplannen.
	 *
	 * @return array<string, mixed> Result with 'success' and either 'message' or per-kind counts.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	public function seed(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['success' => false, 'message' => 'OpenRegister is not available'];
		}

		$targets = $this->seedTargets();
		if ($targets['failure'] !== null) {
			return ['success' => false, 'message' => $targets['failure']];
		}

		$seed = $this->seedData();
		if ($seed['failure'] !== null) {
			return ['success' => false, 'message' => $seed['failure']];
		}

		$counts = ['quickActions' => 0, 'belplannen' => 0, 'skipped' => 0, 'failed' => 0];

		$this->seedAsSystem(objectService: $objectService, targets: $targets, data: $seed['data'], counts: $counts);

		return $this->seedOutcome(counts: $counts);
	}//end seed()

	/**
	 * The register and schemas to seed into, or the reason they are missing.
	 *
	 * @return array{failure: string|null, register: string, quickActionSchema: string, belplanSchema: string} The targets.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	private function seedTargets(): array {
		$targets = ['failure' => null, 'register' => '', 'quickActionSchema' => '', 'belplanSchema' => ''];

		$register = (string)$this->settingsService->getConfigValue('register');
		if ($register === '') {
			return array_merge($targets, ['failure' => 'Dossiq register not configured']);
		}

		$quickActionSchema = (string)$this->settingsService->getConfigValue('kcc_quick_action_schema');
		$belplanSchema = (string)$this->settingsService->getConfigValue('belplan_schema');
		if ($quickActionSchema === '' || $belplanSchema === '') {
			return array_merge($targets, ['failure' => 'KCC-werkplek schemas not configured']);
		}

		return [
			'failure' => null,
			'register' => $register,
			'quickActionSchema' => $quickActionSchema,
			'belplanSchema' => $belplanSchema,
		];
	}//end seedTargets()

	/**
	 * The decoded seed file, or the reason it could not be read.
	 *
	 * @return array{failure: string|null, data: array<mixed>} The seed data.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	private function seedData(): array {
		$seedPath = __DIR__ . '/../Settings/kcc_werkplek_seed_data.json';
		if (file_exists($seedPath) === false) {
			return ['failure' => 'Seed file not found', 'data' => []];
		}

		$data = json_decode((string)file_get_contents($seedPath), true);
		if (is_array($data) === false) {
			return ['failure' => 'Invalid seed JSON', 'data' => []];
		}

		return ['failure' => null, 'data' => $data];
	}//end seedData()

	/**
	 * Seed the quick-actions and belplannen, elevated to the system user.
	 *
	 * @param object                                                                  $objectService OpenRegister ObjectService.
	 * @param array{register: string, quickActionSchema: string, belplanSchema: string} $targets       The register and schemas.
	 * @param array<mixed>                                                            $data          The decoded seed file.
	 * @param array<string, int>                                                      $counts        Counter accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	private function seedAsSystem(object $objectService, array $targets, array $data, array &$counts): void {
		$register = $targets['register'];
		$quickActionSchema = $targets['quickActionSchema'];
		$belplanSchema = $targets['belplanSchema'];

		// This service is only ever invoked from boot-time repair steps
		// (SeedKccWerkplekData, SeedDeadlineMonitoringData) — never from a live
		// user request — so it is safe to elevate the whole seed for the
		// duration of this call. Anonymous callers are otherwise fail-closed
		// by OpenRegister RBAC (#1955) on every boot.
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $quickActionSchema, $belplanSchema, $data, &$counts): void {
				$this->seedRows(
					target: new KccSeedTarget(
						objectService: $objectService,
						register: $register,
						schema: $quickActionSchema,
						counterKey: 'quickActions'
					),
					rows: (array)($data['kccQuickActions'] ?? []),
					counts: $counts,
				);

				$this->seedRows(
					target: new KccSeedTarget(
						objectService: $objectService,
						register: $register,
						schema: $belplanSchema,
						counterKey: 'belplannen'
					),
					rows: (array)($data['belplannen'] ?? []),
					counts: $counts,
				);
			}
		);
	}//end seedAsSystem()

	/**
	 * The seed result, failed when any row was refused.
	 *
	 * @param array<string, int> $counts The per-kind counts.
	 *
	 * @return array<string, mixed> Result with 'success' and either 'message' or per-kind counts.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/specs.md
	 */
	private function seedOutcome(array $counts): array {
		// A SEED THAT SEEDED NOTHING MUST NOT REPORT SUCCESS. Every row failure
		// below is counted rather than only logged, and one failure makes the
		// whole call unsuccessful — otherwise "five quick-actions refused" and
		// "five quick-actions already present" produce the same
		// `success: true, quickActions: 0` line, and only one of them means the
		// install is healthy.
		if ($counts['failed'] > 0) {
			$this->logger->error('Dossiq KCC-werkplek: seed refused rows', $counts);

			return array_merge(
				[
					'success' => false,
					'message' => $counts['failed'] . ' row(s) refused; see the log for each refusal',
				],
				$counts
			);
		}

		$this->logger->info('Dossiq KCC-werkplek: seed complete', $counts);

		return array_merge(['success' => true], $counts);
	}//end seedOutcome()

	/**
	 * Seed a list of rows into one schema, skipping ids that already exist.
	 *
	 * @param KccSeedTarget      $target The object service, register, schema and counter key.
	 * @param array<int, mixed>  $rows   Seed rows.
	 * @param array<string, int> $counts Counter accumulator (by reference).
	 *
	 * @return void
	 */
	private function seedRows(KccSeedTarget $target, array $rows, array &$counts): void {
		$schema = $target->schema;
		$existingIds = $this->existingIds(target: $target);

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$rowId = (string)($row['id'] ?? '');
			if ($rowId !== '' && in_array($rowId, $existingIds, true) === true) {
				$counts['skipped']++;
				continue;
			}

			try {
				// ObjectService::saveObject()'s first parameter is the
				// object payload, not the register — the previous
				// positional call passed $register/$schema/$row into
				// $object/$extend/$register, which either threw a
				// TypeError or silently wrote the wrong data. Named
				// arguments make the mapping unambiguous.
				$target->objectService->saveObject(object: $row, register: $target->register, schema: $schema);
				$counts[$target->counterKey]++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$this->logger->error(
					'Dossiq KCC-werkplek seed: row failed',
					['id' => $rowId, 'schema' => $schema, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end seedRows()

	/**
	 * Collect existing object ids for idempotent skip-detection.
	 *
	 * @param KccSeedTarget $target The object service, register and schema.
	 *
	 * @return array<int, string>
	 */
	private function existingIds(KccSeedTarget $target): array {
		$schema = $target->schema;
		try {
			$rows = $this->searchObjectsAsArrays(objectService: $target->objectService, register: $target->register, schema: $schema);
		} catch (Throwable $e) {
			// An unreadable schema is reported, not assumed empty: "no rows
			// exist" and "the read was refused" both returned [], and the second
			// one makes the seed re-insert every row it already holds.
			$this->logger->warning(
				'Dossiq KCC-werkplek seed: could not read existing rows; treating the schema as empty',
				['schema' => $schema, 'error' => $e->getMessage()]
			);
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$rowId = '';
			if (isset($row['id']) === true) {
				$rowId = (string)$row['id'];
			}

			if ($rowId !== '') {
				$ids[] = $rowId;
			}
		}//end foreach

		return $ids;
	}//end existingIds()
}//end class
