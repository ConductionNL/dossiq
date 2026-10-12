<?php

/**
 * Dossiq LHS Lookup Service
 *
 * Pure lookup on the Landelijke Handhavingsstrategie 4×4 matrix
 * (Beoordeling gedrag × Mogelijke gevolgen) returning the recommended
 * interventieladder step from `lhsMatrixCell` objects in OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for LHS matrix lookups.
 *
 * Reads `lhsMatrixCell` records from OpenRegister to resolve the
 * recommended intervention for a given gedrag (behaviour) + gevolg (impact)
 * combination. Falls back to the embedded seed table when OpenRegister is
 * unavailable or the matrix has not been seeded yet.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */
class LhsLookupService {

	use SearchesObjects;

	/**
	 * Valid gedrag values (behaviour axis of the LHS matrix).
	 */
	private const VALID_GEDRAG = ['A', 'B', 'C', 'D'];

	/**
	 * Valid gevolg values (impact axis of the LHS matrix).
	 */
	private const VALID_GEVOLG = ['1', '2', '3', '4'];

	/**
	 * The stored decision table that answers the lookup (seeded by the LHS
	 * package): inputs `behaviour` (A-D) and `consequence` (1-4), outputs
	 * `interventionStep` and optionally `description`.
	 */
	public const DECISION_TABLE = 'dossiq.lhs-intervention';

	/**
	 * Embedded fallback table (behaviour:gevolg → interventieStep).
	 *
	 * Used when neither the stored table nor the matrix cells answer. It holds
	 * the same 16 interventions as the shipped grid cells, which are the
	 * reference (LhsLookupAnswersTheGridTest compares them cell by cell).
	 *
	 * @var array<string, string>
	 */
	private const FALLBACK_MATRIX = [
		'A:1' => 'Aanspreken / informeren',
		'A:2' => 'Waarschuwen',
		'A:3' => 'Bestuurlijke waarschuwing',
		'A:4' => 'Last onder dwangsom',
		'B:1' => 'Waarschuwen',
		'B:2' => 'Bestuurlijke waarschuwing',
		'B:3' => 'Last onder dwangsom',
		'B:4' => 'Last onder dwangsom + Proces-verbaal',
		'C:1' => 'Bestuurlijke waarschuwing',
		'C:2' => 'Last onder dwangsom',
		'C:3' => 'Bestuursdwang + Proces-verbaal',
		'C:4' => 'Bestuursdwang + Proces-verbaal',
		'D:1' => 'Last onder dwangsom',
		'D:2' => 'Bestuursdwang + Proces-verbaal',
		'D:3' => 'Bestuursdwang + Proces-verbaal',
		'D:4' => 'Bestuursdwang + Proces-verbaal',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge
	 * @param LoggerInterface $logger Logger
	 * @param StoredDecisionTables|null $tables The stored LHS intervention table in OpenRegister.
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-8
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?StoredDecisionTables $tables = null,
	) {
	}//end __construct()

	/**
	 * Look up the recommended intervention for a gedrag + gevolg combination.
	 *
	 * @param string $behaviour Behaviour axis: A, B, C, or D
	 * @param string $gevolg Impact axis: 1, 2, 3, or 4
	 *
	 * @return array<string, mixed> Cell data with interventieStep and description
	 *
	 * @throws RuntimeException If gedrag or gevolg are invalid
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-8
	 */
	public function lookup(string $behaviour, string $gevolg): array {
		$behaviour = strtoupper(string: trim(string: $behaviour));
		$gevolg = trim(string: $gevolg);

		if (in_array(needle: $behaviour, haystack: self::VALID_GEDRAG, strict: true) === false) {
			throw new RuntimeException('Invalid gedrag value: ' . $behaviour . '. Must be A, B, C or D.');
		}

		if (in_array(needle: $gevolg, haystack: self::VALID_GEVOLG, strict: true) === false) {
			throw new RuntimeException('Invalid gevolg value: ' . $gevolg . '. Must be 1, 2, 3 or 4.');
		}

		// The stored decision table first (the LHS package seeds it), then the
		// legacy matrix cells, then the embedded table.
		$cell = $this->lookupFromDecisionTable(behaviour: $behaviour, gevolg: $gevolg);
		if ($cell !== null) {
			return $cell;
		}

		$cell = $this->lookupFromRegister(behaviour: $behaviour, gevolg: $gevolg);
		if ($cell !== null) {
			return $cell;
		}

		// Fallback to embedded table. The validated $behaviour/$gevolg pair is
		// guaranteed to be a key in FALLBACK_MATRIX (4x4 = 16 cells), so no
		// null-coalescing default is required.
		$key = $behaviour . ':' . $gevolg;
		$intervention = self::FALLBACK_MATRIX[$key];

		return [
			'behaviourRow' => $behaviour,
			'consequenceColumn' => $gevolg,
			'interventionStep' => $intervention,
			'description' => '',
			'source' => 'fallback',
		];
	}//end lookup()

	/**
	 * Evaluate the stored LHS intervention table in OpenRegister's register.
	 *
	 * @param string $behaviour Validated behaviour row (A-D).
	 * @param string $gevolg Validated consequence column (1-4).
	 *
	 * @return array<string, mixed>|null The cell, or null when the store or the table is absent or does not answer.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	private function lookupFromDecisionTable(string $behaviour, string $gevolg): ?array {
		$answer = null;
		try {
			$answer = $this->tables?->evaluate(
				reference: self::DECISION_TABLE,
				inputs: ['behaviour' => $behaviour, 'consequence' => $gevolg]
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'LHS lookup: the stored decision table did not answer: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'table' => self::DECISION_TABLE]
			);
		}

		$intervention = ($answer['outputs']['interventionStep'] ?? null);
		if (is_string($intervention) === false || $intervention === '') {
			return null;
		}

		return [
			'behaviourRow' => $behaviour,
			'consequenceColumn' => $gevolg,
			'interventionStep' => $intervention,
			'description' => (string)($answer['outputs']['description'] ?? ''),
			'source' => 'decision-table',
		];
	}//end lookupFromDecisionTable()

	/**
	 * Look up a cell from the OpenRegister lhsMatrixCell schema.
	 *
	 * @param string $behaviour Behaviour value
	 * @param string $gevolg Impact value
	 *
	 * @return array<string, mixed>|null Cell data or null when not found
	 */
	private function lookupFromRegister(string $behaviour, string $gevolg): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			return null;
		}

		try {
			$results = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: 'lhsMatrixCell',
				filters: ['behaviourRow' => $behaviour, 'consequenceColumn' => $gevolg, '_limit' => 1]
			);

			// The outer is_array() is always true ($results is typed array); the inner
			// one on $results[0] is NOT redundant and stays.
			if (isset($results[0]) === true && is_array($results[0]) === true) {
				return array_merge($results[0], ['source' => 'register']);
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'LHS matrix lookup from register failed, using fallback: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return null;
		}
	}//end lookupFromRegister()
}//end class
