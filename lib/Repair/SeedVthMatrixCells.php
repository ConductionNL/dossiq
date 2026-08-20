<?php

/**
 * Procest Seed VTH LHS Matrix Cells Repair Step
 *
 * Idempotent repair step that seeds the 16 default LHS matrix cells
 * (gedrag A-D × gevolg 1-4) as `lhsMatrixCell` objects in OpenRegister.
 * Re-runs are no-ops once cells exist.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds the default 16-cell LHS matrix into OpenRegister.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */
class SeedVthMatrixCells implements IRepairStep {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-8
	 */
	public function getName(): string {
		return 'Seed default LHS matrix cells (16 cells: gedrag A-D × gevolg 1-4) for Procest VTH module';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-8
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding VTH LHS matrix cells...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister not available. Skipping VTH LHS matrix cell seed.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('ObjectService unavailable. Skipping VTH LHS matrix cell seed.');
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			$output->warning('Register not configured. Skipping VTH LHS matrix cell seed.');
			return;
		}

		// Check if cells already exist (idempotent). Repair steps run without
		// a Nextcloud user session, so both this read and the writes below
		// are wrapped in runAsSystem() — anonymous callers are otherwise
		// fail-closed by OpenRegister RBAC (#1955) on every boot.
		if ($this->alreadySeeded(objectService: $objectService, register: $register) === true) {
			$output->info('LHS matrix cells already seeded. Skipping.');
			return;
		}

		$data = $this->loadSeedData(output: $output);
		if ($data === null) {
			return;
		}

		$seeded = 0;
		foreach ($data['cells'] as $cell) {
			if (is_array($cell) === false) {
				continue;
			}

			try {
				$this->runAsSystemIfAvailable(
					objectService: $objectService,
					operation: function () use ($objectService, $register, $cell): void {
						$objectService->saveObject(
							register: $register,
							schema: 'lhsMatrixCell',
							object: $cell
						);
					}
				);
				$seeded++;
			} catch (Throwable $e) {
				$output->warning('Failed to seed LHS cell ' . $cell['behaviourRow'] . ':' . $cell['consequenceColumn'] . ': ' . $e->getMessage());
				$this->logger->warning(
					'VTH LHS cell seed failed',
					['exception' => $e->getMessage(), 'cell' => $cell]
				);
			}
		}//end foreach

		$output->info('VTH LHS matrix cells seeded: ' . $seeded . ' of ' . count($data['cells']) . ' cells.');
	}//end run()

	/**
	 * Test whether at least one lhsMatrixCell already exists, so the seed is a no-op.
	 *
	 * The schema may not exist yet on this instance; that is reported as "not seeded" so the seed
	 * proceeds, exactly as before this check was extracted.
	 *
	 * @param object $objectService OpenRegister object service handle
	 * @param string $register The Procest register slug
	 *
	 * @return bool True when cells are already present.
	 */
	private function alreadySeeded(object $objectService, string $register): bool {
		try {
			$existing = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: function () use ($objectService, $register): array {
					return $this->searchObjectsAsArrays(
						objectService: $objectService,
						register: $register,
						schema: 'lhsMatrixCell',
						filters: ['_limit' => 1]
					);
				}
			);
		} catch (Throwable) {
			// Schema may not exist yet; proceed with seeding.
			return false;
		}

		return (is_array($existing) === true && count($existing) > 0);
	}//end alreadySeeded()

	/**
	 * Read and decode the bundled LHS matrix seed file, warning and returning null when it is
	 * missing, unparseable, or carries no `cells` key.
	 *
	 * @param IOutput $output Output interface for progress reporting
	 *
	 * @return array<string, mixed>|null The decoded seed payload, or null.
	 */
	private function loadSeedData(IOutput $output): ?array {
		$seedPath = __DIR__ . '/../Settings/lhs_matrix_seed.json';
		if (file_exists($seedPath) === false) {
			$output->warning('VTH LHS matrix seed file not found: ' . $seedPath);
			return null;
		}

		$raw = (string)file_get_contents($seedPath);
		$data = json_decode($raw, true);
		if (is_array($data) === false || isset($data['cells']) === false) {
			$output->warning('VTH LHS matrix seed file is invalid JSON or missing cells key.');
			return null;
		}

		return $data;
	}//end loadSeedData()
}//end class
