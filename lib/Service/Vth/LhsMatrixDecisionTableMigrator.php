<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Vth
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project each LHS matrix onto a decision table.
 *
 * The Landelijke Handhavingsstrategie matrix is a three-axis lookup: severity
 * by behaviour by actor type, yielding an intervention. That is a decision
 * table, and openregister#3186 gave the fleet one evaluator for those, whose
 * own suite proves this exact shape evaluates
 * (`testTheLhsMatrixShapeEvaluates`).
 *
 * dossiq meanwhile indexes the cells by hand into a
 * "severity:behaviour:actorType" dictionary and throws when the triple misses.
 * That hand-rolled lookup is what let the shipped matrix label all twelve of
 * its government cells `government` while the axis said `overheid`, leaving a
 * quarter of the strategy unreachable and nothing to notice (dossiq#1596). A
 * decision table cannot hide that the same way: its inputs are declared, and
 * the evaluator refuses a table it cannot resolve rather than silently missing.
 *
 * 🔴 THE PROJECTION ARRIVES DISABLED, like its two siblings. The matrix still
 * drives recommendations through LhsRecommendationService; a table that also
 * answered would be a second source of truth for an enforcement decision.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class LhsMatrixDecisionTableMigrator {

	use SearchesObjects;

	/**
	 * Provenance marker written into the projected table's description.
	 *
	 * Resolved by marker rather than by name: a name is editable, and a re-run
	 * matching on one would mint a second table the moment somebody renamed
	 * the first.
	 *
	 * @var string
	 */
	public const MARKER_PREFIX = 'dossiq:lhsMatrix:';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Project every stored matrix onto a decision table.
	 *
	 * @param IUser   $user   The owner the tables are written as.
	 * @param boolean $dryRun Report without writing.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function migrate(IUser $user, bool $dryRun): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['note' => 'OpenRegister is not available.', 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];
		}

		if (method_exists($objectService, 'runAs') === false) {
			return ['note' => 'OpenRegister exposes no runAs(); the migration needs an owner.', 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];
		}

		return $objectService->runAs(
			$user,
			fn (): array => $this->migrateAll(objectService: $objectService, dryRun: $dryRun)
		);

	}//end migrate()

	/**
	 * Project every matrix, returning the summary.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param boolean $dryRun        Report only.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function migrateAll(object $objectService, bool $dryRun): array {
		$register = (string)$this->settingsService->getConfigValue('register');
		$tableSchema = (string)$this->settingsService->getConfigValue('decision_table_schema');
		$matrices = $this->fetchMatrices(objectService: $objectService, register: $register);

		$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
		$rows = [];

		foreach ($matrices as $matrix) {
			$row = $this->migrateOne(
				matrix: $matrix,
				objectService: $objectService,
				register: $register,
				tableSchema: $tableSchema,
				dryRun: $dryRun,
			);

			if (array_key_exists($row['outcome'], $counts) === true) {
				$counts[$row['outcome']] = ($counts[$row['outcome']] + 1);
			}

			$rows[] = $row;
		}

		return ($counts + ['total' => count($matrices), 'rows' => $rows]);

	}//end migrateAll()

	/**
	 * Project one matrix.
	 *
	 * @param array<string, mixed> $matrix        The stored matrix.
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $tableSchema   The decision-table schema.
	 * @param boolean              $dryRun        Report only.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function migrateOne(array $matrix, object $objectService, string $register, string $tableSchema, bool $dryRun): array {
		$id = (string)($matrix['id'] ?? ($matrix['@self']['id'] ?? ''));
		$marker = (self::MARKER_PREFIX . $id);

		if ($id === '' || $tableSchema === '') {
			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => 'no matrix id, or no decision-table schema configured'];
		}

		$table = $this->tableFor(matrix: $matrix, marker: $marker);
		if ($table === null) {
			// A matrix whose cells name values absent from its own axes cannot
			// be projected honestly: the rule would be unreachable in the table
			// exactly as the cell is unreachable in the matrix, and projecting
			// it would carry the defect across while looking like a migration
			// that worked. dossiq#1596 is that defect.
			return ['outcome' => 'skipped', 'marker' => $marker, 'detail' => 'a cell names a value that is not on its axis'];
		}

		if ($dryRun === true) {
			return ['outcome' => 'created', 'marker' => $marker, 'detail' => sprintf('%d rule(s)', count($table['rules']))];
		}

		try {
			$objectService->saveObject(object: $table, register: $register, schema: $tableSchema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not project an LHS matrix onto a decision table',
				['app' => Application::APP_ID, 'marker' => $marker, 'exception' => $e->getMessage()]
			);

			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => $e->getMessage()];
		}

		return ['outcome' => 'created', 'marker' => $marker, 'detail' => sprintf('%d rule(s)', count($table['rules']))];

	}//end migrateOne()

	/**
	 * Build the decision table for one matrix, or null when it is inconsistent.
	 *
	 * @param array<string, mixed> $matrix The stored matrix.
	 * @param string               $marker The provenance marker.
	 *
	 * @return array<string, mixed>|null The table.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function tableFor(array $matrix, string $marker): ?array {
		$axes = [
			'severity' => $this->decode(value: ($matrix['severityAxis'] ?? null)),
			'behaviour' => $this->decode(value: ($matrix['behaviourAxis'] ?? null)),
			'actorType' => $this->decode(value: ($matrix['actorTypeAxis'] ?? null)),
		];
		$cells = $this->decode(value: ($matrix['cells'] ?? null));
		if ($cells === [] || in_array([], $axes, true) === true) {
			return null;
		}

		$rules = [];
		foreach ($cells as $cell) {
			foreach ($axes as $key => $allowed) {
				if (in_array(($cell[$key] ?? null), $allowed, true) === false) {
					return null;
				}
			}

			$rules[] = [
				'id' => sprintf('%s:%s:%s', $cell['severity'], $cell['behaviour'], $cell['actorType']),
				'annotation' => (string)($cell['note'] ?? ''),
				'inputEntries' => [
					(string)$cell['severity'],
					(string)$cell['behaviour'],
					(string)$cell['actorType'],
				],
				'outputEntries' => [(string)($cell['intervention'] ?? '')],
			];
		}//end foreach

		$version = (string)($matrix['version'] ?? '1');

		return [
			'name' => (string)($matrix['name'] ?? 'LHS'),
			'key' => ('lhs-matrix-' . $version),
			'description' => sprintf(
				'Projected from the LHS matrix "%s" (version %s). %s It arrives disabled: the matrix '
				. 'still drives recommendations, and a table that also answered would be a second '
				. 'source of truth for an enforcement decision.',
				(string)($matrix['name'] ?? ''),
				$version,
				$marker
			),
			// UNIQUE: the matrix is a grid, so exactly one cell answers a
			// triple. Declaring UNIQUE means an overlapping pair is REFUSED
			// rather than silently resolved by declaration order, which is the
			// property a hand-indexed dictionary quietly gave up.
			'hitPolicy' => 'UNIQUE',
			'inputs' => [
				['name' => 'severity', 'type' => 'string'],
				['name' => 'behaviour', 'type' => 'string'],
				['name' => 'actorType', 'type' => 'string'],
			],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => $rules,
			'enabled' => false,
		];

	}//end tableFor()

	/**
	 * Decode a field the schema may store as a JSON string.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, mixed> The list.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function decode(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end decode()

	/**
	 * Read the stored matrices.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 *
	 * @return array<int, array<string, mixed>> The matrices.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function fetchMatrices(object $objectService, string $register): array {
		$schema = (string)$this->settingsService->getConfigValue('lhs_matrix_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: []
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not list LHS matrices',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);

			return [];
		}//end try

	}//end fetchMatrices()

}//end class
