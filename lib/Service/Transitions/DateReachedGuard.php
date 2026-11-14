<?php

/**
 * Dossiq Date Reached Guard evaluator.
 *
 * Guard config shape: `{type: 'dateReached', field: 'opschortendeTermijnEinde'}`.
 * Passes once today is on or after the day the case field holds; with
 * `after: true` only once that day has passed (a closing date: bids still
 * come in on it). Refuses naming the day.
 * The guard computes no dates: a term that ends after some days (a standstill)
 * writes its end day to a case field, and the guard waits for that field.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCP\IL10N;

/**
 * Holds a transition until a day on the case has been reached.
 *
 * Generic: a closing date before evaluation, the end of a standstill term
 * before an award becomes final, any wait a case type declares on one of
 * its date fields.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */
class DateReachedGuard implements GuardEvaluatorInterface {

	/**
	 * Constructor.
	 *
	 * @param IL10N                       $l10n  The app's translations.
	 * @param CaseDateNormaliserInterface $dates Reads a stored date and today in the administrator's time zone.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly CaseDateNormaliserInterface $dates,
	) {
	}//end __construct()

	/**
	 * Evaluate the guard against a case.
	 *
	 * @param array<string, mixed> $guardConfig The guard: `field`, and `after` to wait until the day has passed.
	 * @param array<string, mixed> $case        The case.
	 * @param string               $userId      The acting user (unused).
	 *
	 * @return GuardResult Passed once the day is reached.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$field = (string)($guardConfig['field'] ?? '');
		if ($field === '') {
			return new GuardResult(passed: false, failureMessage: 'Date guard missing field');
		}

		$day = $this->dates->toCalendarDateOrNull(value: $this->valueOf(case: $case, field: $field));
		if ($day === null) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('Date missing: %s', [$field]),
				details: ['field' => $field],
			);
		}

		// Calendar days as Y-m-d compare correctly as strings.
		$after   = (($guardConfig['after'] ?? false) === true);
		$details = ['field' => $field, 'day' => $day, 'after' => $after];
		$order   = strcmp($this->dates->todayAsCalendarDate(), $day);
		if ($after === true && $order <= 0) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('Only possible after %s', [$day]),
				details: $details,
			);
		}

		if ($order < 0) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('Not possible before %s', [$day]),
				details: $details,
			);
		}

		return new GuardResult(passed: true, details: $details);
	}//end evaluate()

	/**
	 * The field's value on the case, or in its answered properties.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $field The field name.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type
	 */
	private function valueOf(array $case, string $field): mixed {
		if (isset($case[$field]) === true) {
			return $case[$field];
		}

		$entries = ($case['properties'] ?? null);
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === true && (string)($entry['name'] ?? '') === $field) {
				return ($entry['value'] ?? null);
			}
		}

		return null;
	}//end valueOf()
}//end class
