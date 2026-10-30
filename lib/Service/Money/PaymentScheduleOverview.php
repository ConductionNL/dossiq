<?php

/**
 * Dossiq payment schedule overview.
 *
 * The instalments of the payment schedules on a case's decisions, each with
 * the state a handler reads off it, and the totals the case page shows beside
 * them. Reads only; raising an instalment is {@see PaymentScheduleRaiser}'s.
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
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\SettingsService;

/**
 * The instalments and totals of the payment schedules on one case.
 *
 * An instalment is `paid` once the finance app holds its payable, `ready`
 * while it waits only for the next run of the raising job (its condition is
 * met, or it has none and its day has come), `waiting` when its day has come
 * but its condition is not met, and `planned` otherwise.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
 */
class PaymentScheduleOverview {
	/**
	 * Decisions read for one case.
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
	 * The instalments of every decision on the case, in date order, and their totals.
	 *
	 * `empty` is true while the case has no instalment, so a card can say so
	 * rather than show zeros.
	 *
	 * @param string            $caseId The case UUID.
	 * @param DateTimeImmutable $today  The day the states count from.
	 *
	 * @return array{empty: bool, instalments: list<array<string, mixed>>, totals: array<string, float>}
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
	 */
	public function forCase(string $caseId, DateTimeImmutable $today): array {
		$instalments = [];
		foreach ($this->decisionsOf(caseId: $caseId) as $decision) {
			foreach ((array)($decision['paymentSchedule']['instalments'] ?? []) as $instalment) {
				$instalments[] = $this->rowOf(decision: $decision, instalment: (array)$instalment, today: $today);
			}
		}

		usort($instalments, fn (array $left, array $right): int => $this->compareByDueDate(left: $left, right: $right));

		return [
			'empty' => $instalments === [],
			'instalments' => $instalments,
			'totals' => $this->totalsOf(instalments: $instalments),
		];
	}//end forCase()

	/**
	 * One instalment as the case page reads it.
	 *
	 * @param array<string, mixed> $decision   The decision that holds it.
	 * @param array<string, mixed> $instalment The instalment.
	 * @param DateTimeImmutable    $today      The day the state counts from.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(array $decision, array $instalment, DateTimeImmutable $today): array {
		return [
			'decisionId' => (string)($decision['id'] ?? $decision['uuid'] ?? ''),
			'label' => (string)($instalment['label'] ?? ''),
			'dueOn' => substr((string)($instalment['dueOn'] ?? ''), 0, 10),
			'amount' => round((int)($instalment['amountCents'] ?? 0) / 100, 2),
			'condition' => (string)($instalment['condition'] ?? ''),
			'state' => $this->stateOf(instalment: $instalment, today: $today),
			'raisedAt' => (string)($instalment['raisedAt'] ?? ''),
		];
	}//end rowOf()

	/**
	 * The state of one instalment: paid, ready, waiting or planned.
	 *
	 * @param array<string, mixed> $instalment The instalment.
	 * @param DateTimeImmutable    $today      The day the state counts from.
	 *
	 * @return string
	 */
	private function stateOf(array $instalment, DateTimeImmutable $today): string {
		if ((string)($instalment['payableId'] ?? '') !== '') {
			return 'paid';
		}

		$dueOn      = substr((string)($instalment['dueOn'] ?? ''), 0, 10);
		$hasCome    = $dueOn !== '' && $dueOn <= $today->format('Y-m-d');
		$conditions = (string)($instalment['condition'] ?? '') !== '';
		$met        = ($instalment['conditionMet'] ?? false) === true;

		if ($conditions === true && $met === true) {
			return 'ready';
		}

		if ($hasCome === false) {
			return 'planned';
		}

		if ($conditions === true) {
			return 'waiting';
		}

		return 'ready';
	}//end stateOf()

	/**
	 * The schedule's total and how much of it is paid, ready and still to pay.
	 *
	 * @param list<array<string, mixed>> $instalments The instalments.
	 *
	 * @return array<string, float>
	 */
	private function totalsOf(array $instalments): array {
		$totals = ['total' => 0.0, 'paid' => 0.0, 'ready' => 0.0, 'toPay' => 0.0];
		foreach ($instalments as $instalment) {
			$totals['total'] += $instalment['amount'];
			if ($instalment['state'] === 'paid' || $instalment['state'] === 'ready') {
				$totals[$instalment['state']] += $instalment['amount'];
				continue;
			}

			$totals['toPay'] += $instalment['amount'];
		}

		return array_map(static fn (float $amount): float => round($amount, 2), $totals);
	}//end totalsOf()

	/**
	 * Order two instalments by due date; one without a date comes last.
	 *
	 * @param array<string, mixed> $left  An instalment.
	 * @param array<string, mixed> $right Another.
	 *
	 * @return int
	 */
	private function compareByDueDate(array $left, array $right): int {
		return strcmp($this->sortDay(instalment: $left), $this->sortDay(instalment: $right));
	}//end compareByDueDate()

	/**
	 * The day an instalment sorts on; one without a date sorts after every date.
	 *
	 * @param array<string, mixed> $instalment An instalment.
	 *
	 * @return string
	 */
	private function sortDay(array $instalment): string {
		$day = (string)$instalment['dueOn'];
		if ($day === '') {
			return '9999-99-99';
		}

		return $day;
	}//end sortDay()

	/**
	 * The decisions on the case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function decisionsOf(string $caseId): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null || $caseId === '') {
			return [];
		}

		$rows = $objectService->findAll(
			[
				'filters' => [
					'register' => Application::REGISTER_SLUG,
					'schema' => PaymentScheduleRaiser::DECISION_SCHEMA,
					'caseId' => $caseId,
				],
				'limit' => self::LIMIT,
			]
		);

		$decisions = [];
		foreach ((array)$rows as $row) {
			$decisions[] = $this->rowNormaliser->normalise(row: $row)['data'];
		}

		return $decisions;
	}//end decisionsOf()
}//end class
