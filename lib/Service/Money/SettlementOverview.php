<?php

/**
 * Dossiq settlement overview.
 *
 * The settlements (vaststellingen) of a case. A settlement does not point at
 * the case: it hangs off the execution, which hangs off the application, and
 * only the application names the case. This reads that chain for one case,
 * so the case page can show the settlement on its Overview tab. Reads only.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\SettingsService;

/**
 * The settlements of one case, reached through its applications and their executions.
 *
 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
 */
class SettlementOverview {

	/**
	 * The schema of an application; it names the case.
	 */
	public const APPLICATION_SCHEMA = 'subsidieAanvraag';

	/**
	 * The schema of an execution; it names the application.
	 */
	public const EXECUTION_SCHEMA = 'subsidieUitvoering';

	/**
	 * The schema of a settlement; it names the execution.
	 */
	public const SETTLEMENT_SCHEMA = 'subsidieVaststelling';

	/**
	 * Rows read per step.
	 */
	private const LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings      Reaches OpenRegister's ObjectService.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row as an array.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * The settlements of the case, the most recent settlement date first, one
	 * without a date (a draft) before them all.
	 *
	 * `empty` is true while the case has no settlement, so a card can say so.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{empty: bool, settlements: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
	 */
	public function forCase(string $caseId): array {
		$settlements = [];
		foreach ($this->idsOf(rows: $this->rows(schema: self::APPLICATION_SCHEMA, field: 'case', value: $caseId)) as $applicationId) {
			foreach ($this->idsOf(rows: $this->rows(schema: self::EXECUTION_SCHEMA, field: 'subsidieaanvraag', value: $applicationId)) as $executionId) {
				foreach ($this->rows(schema: self::SETTLEMENT_SCHEMA, field: 'subsidieuitvoering', value: $executionId) as $settlement) {
					$settlements[] = $this->rowOf(settlement: $settlement, applicationId: $applicationId, executionId: $executionId);
				}
			}
		}

		usort($settlements, fn (array $left, array $right): int => $this->compareByDate(left: $left, right: $right));

		return [
			'empty' => $settlements === [],
			'settlements' => $settlements,
		];
	}//end forCase()

	/**
	 * One settlement as the case page reads it.
	 *
	 * @param array<string, mixed> $settlement    The settlement.
	 * @param string               $applicationId The application it settles.
	 * @param string               $executionId   The execution it settles.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(array $settlement, string $applicationId, string $executionId): array {
		return [
			'id' => $this->idOf(row: $settlement),
			'applicationId' => $applicationId,
			'executionId' => $executionId,
			'determinationDate' => substr((string)($settlement['determinationDate'] ?? ''), 0, 10),
			'actualCostTotal' => $this->amountOf(value: $settlement['actualCostTotal'] ?? null),
			'determinedAmount' => $this->amountOf(value: $settlement['determinedAmount'] ?? null),
			'recovery' => ($settlement['recoveryTrigger'] ?? false) === true,
			'status' => $this->statusOf(settlement: $settlement),
		];
	}//end rowOf()

	/**
	 * A settlement's status: `determined` or `draft`.
	 *
	 * @param array<string, mixed> $settlement The settlement.
	 *
	 * @return string
	 */
	private function statusOf(array $settlement): string {
		if (($settlement['status'] ?? '') === 'determined') {
			return 'determined';
		}

		return 'draft';
	}//end statusOf()

	/**
	 * An amount in euros, or null when none is set yet.
	 *
	 * @param mixed $value The stored amount.
	 *
	 * @return float|null
	 */
	private function amountOf(mixed $value): ?float {
		if (is_int($value) === false && is_float($value) === false && (is_string($value) === false || is_numeric($value) === false)) {
			return null;
		}

		return round((float)$value, 2);
	}//end amountOf()

	/**
	 * Order two settlements: undated first, then the latest date first.
	 *
	 * @param array<string, mixed> $left  A settlement.
	 * @param array<string, mixed> $right Another.
	 *
	 * @return int
	 */
	private function compareByDate(array $left, array $right): int {
		$leftDay  = (string)$left['determinationDate'];
		$rightDay = (string)$right['determinationDate'];
		if ($leftDay === '' || $rightDay === '') {
			return (int)($leftDay !== '') - (int)($rightDay !== '');
		}

		return strcmp($rightDay, $leftDay);
	}//end compareByDate()

	/**
	 * The ids of rows, skipping a row without one.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return list<string>
	 */
	private function idsOf(array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			$id = $this->idOf(row: $row);
			if ($id !== '' && in_array($id, $ids, true) === false) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsOf()

	/**
	 * A row's id, or its uuid when it has no id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()

	/**
	 * The rows of a schema whose field holds the value.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The reference field.
	 * @param string $value  The id it must hold.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, string $field, string $value): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null || $value === '') {
			return [];
		}

		$rows = $objectService->findAll(
			[
				'filters' => [
					'register' => AppIdentity::REGISTER_SLUG,
					'schema' => $schema,
					$field => $value,
				],
				'limit' => self::LIMIT,
			]
		);

		$found = [];
		foreach ((array)$rows as $row) {
			$normalised = $this->rowNormaliser->normalise(row: $row);
			$data       = $normalised['data'];
			if ($this->idOf(row: $data) === '') {
				$data['id'] = $normalised['uuid'];
			}

			$found[] = $data;
		}

		return $found;
	}//end rows()
}//end class
