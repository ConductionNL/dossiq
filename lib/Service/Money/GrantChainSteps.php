<?php

/**
 * Dossiq GrantChainSteps.
 *
 * What the grant actions of a case page need to take their next step: every
 * execution of the case's grants, with the amount its decision granted, the
 * advances paid on it, its interim reports and its settlements. An execution
 * names its application and the application names the case, so the case is
 * two references away; this read walks them once, server-side, so a dialog
 * does not.
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
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Kernel\Contracts\GrantChainStepsInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsRegisterRows;

/**
 * The executions of a case's grants with what their next step needs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link    https://conduction.nl
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
class GrantChainSteps implements GrantChainStepsInterface {

	use ReadsRegisterRows;

	/**
	 * The field of each schema that names the row one step closer to the case.
	 *
	 * @var array<string, string>
	 */
	private const REFERENCE_FIELD = [
		'subsidieAanvraag' => 'case',
		'subsidieBeschikking' => 'subsidieaanvraag',
		'subsidieUitvoering' => 'subsidieaanvraag',
		'interimReport' => 'subsidieuitvoering',
		'subsidieVaststelling' => 'subsidieuitvoering',
	];

	private const LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings      Resolves the ObjectService.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a row as data plus uuid.
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * The executions of the case's grants, in the order of their applications.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{empty: bool, executions: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	public function forCase(string $caseId): array {
		$executions = [];
		foreach ($this->rows(schema: 'subsidieAanvraag', value: $caseId) as $application) {
			$applicationId = (string)$application['id'];
			$granted       = $this->grantedAmounts(applicationId: $applicationId);
			foreach ($this->rows(schema: 'subsidieUitvoering', value: $applicationId) as $execution) {
				$executions[] = $this->executionOf(execution: $execution, application: $application, granted: $granted);
			}
		}

		return [
			'empty' => $executions === [],
			'executions' => $executions,
		];
	}//end forCase()

	/**
	 * One execution as the grant dialogs read it.
	 *
	 * @param array<string, mixed> $execution   The execution row.
	 * @param array<string, mixed> $application The application it executes.
	 * @param array<string, float> $granted     Granted amount per decision id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function executionOf(array $execution, array $application, array $granted): array {
		$executionId = (string)$execution['id'];

		return [
			'id' => $executionId,
			'applicationId' => (string)$application['id'],
			'applicationNumber' => (string)($application['aanvraagnummer'] ?? ''),
			'status' => (string)($execution['status'] ?? ''),
			'grantedAmount' => $this->grantedFor(decisionId: (string)($execution['subsidyDecision'] ?? ''), granted: $granted),
			'advancesPaid' => $this->amountOf(value: $execution['betaaldeVoorschotten'] ?? null),
			'interimReports' => array_map(
				fn (array $report): array => $this->reportOf(report: $report),
				$this->rows(schema: 'interimReport', value: $executionId)
			),
			'settlements' => array_map(
				fn (array $settlement): array => $this->settlementOf(settlement: $settlement),
				$this->rows(schema: 'subsidieVaststelling', value: $executionId)
			),
		];
	}//end executionOf()

	/**
	 * The amount each decision of the application granted, by decision id.
	 *
	 * @param string $applicationId The application id.
	 *
	 * @return array<string, float>
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function grantedAmounts(string $applicationId): array {
		$granted = [];
		foreach ($this->rows(schema: 'subsidieBeschikking', value: $applicationId) as $decision) {
			$amount = $this->amountOf(value: $decision['grantedAmount'] ?? null);
			if ($amount !== null) {
				$granted[(string)$decision['id']] = $amount;
			}
		}

		return $granted;
	}//end grantedAmounts()

	/**
	 * The amount granted for an execution: its own decision's, else the only
	 * decision of its application that names an amount, else none.
	 *
	 * @param string               $decisionId The execution's decision id.
	 * @param array<string, float> $granted    Granted amount per decision id.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function grantedFor(string $decisionId, array $granted): ?float {
		if (isset($granted[$decisionId]) === true) {
			return $granted[$decisionId];
		}

		if (count($granted) === 1) {
			return array_values($granted)[0];
		}

		return null;
	}//end grantedFor()

	/**
	 * One interim report as the assess dialog lists it.
	 *
	 * @param array<string, mixed> $report The interim report row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function reportOf(array $report): array {
		$status = (string)($report['status'] ?? '');

		return [
			'id' => (string)$report['id'],
			'number' => (string)($report['rapportagenummer'] ?? ''),
			'periodStart' => substr((string)($report['rapportagePeriodStart'] ?? ''), 0, 10),
			'periodEnd' => substr((string)($report['rapportagePeriodEnd'] ?? ''), 0, 10),
			'status' => $status,
			'opinion' => (string)($report['beoordelingsoordeel'] ?? ''),
			'assessed' => in_array($status, ['approved', 'rejected', 'gedeeltelijk_approved'], true),
		];
	}//end reportOf()

	/**
	 * One settlement as the settle dialog lists it.
	 *
	 * @param array<string, mixed> $settlement The settlement row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function settlementOf(array $settlement): array {
		return [
			'id' => (string)$settlement['id'],
			'actualCostTotal' => $this->amountOf(value: $settlement['actualCostTotal'] ?? null),
			'determinedAmount' => $this->amountOf(value: $settlement['determinedAmount'] ?? null),
			'determined' => ($settlement['status'] ?? '') === 'determined',
		];
	}//end settlementOf()

	/**
	 * A stored amount as a number, or null when it holds none.
	 *
	 * @param mixed $value The stored value (number or numeric text).
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function amountOf(mixed $value): ?float {
		if (is_int($value) === false && is_float($value) === false && (is_string($value) === false || is_numeric($value) === false)) {
			return null;
		}

		return round((float)$value, 2);
	}//end amountOf()

	/**
	 * The rows of a schema that name the given row, each with its id.
	 *
	 * @param string $schema The schema slug.
	 * @param string $value  The id of the row they name.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function rows(string $schema, string $value): array {
		$found = [];
		foreach ($this->registerRows(id: $value, filters: ['schema' => $schema, self::REFERENCE_FIELD[$schema] => $value]) as $row) {
			$normalised = $this->rowNormaliser->normalise(row: $row);
			$data       = $normalised['data'];
			$id         = ($data['id'] ?? ($data['uuid'] ?? $normalised['uuid']));
			if (is_string($id) === false || $id === '') {
				continue;
			}

			$data['id'] = $id;
			$found[]    = $data;
		}

		return $found;
	}//end rows()
}//end class
