<?php

/**
 * The fixtures the term-binding tests share.
 *
 * Three test classes bind clocks against the same mocked store and the same
 * declaration shape. Writing the shape out three times is how one copy of it
 * drifts and its test keeps passing against a shape the reader no longer
 * produces, so it lives here once and {@see TermDeclarationReader::forCaseType()}
 * is the thing it has to match.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

/**
 * Shared fixtures for the term-binding tests.
 */
trait BindsTermFixtures {
	/**
	 * Every instance the mocked store was handed, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The instances the mocked store answers a read with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $instances = [];

	/**
	 * A case type's declarations, with everything undeclared by default.
	 *
	 * @param array<string, mixed> $overrides What this case type declares.
	 *
	 * @return array<string, mixed> The shape `TermDeclarationReader::forCaseType()` answers.
	 */
	private function declared(array $overrides): array {
		return array_merge(
			[
				'leadTimeDays' => 0,
				'fixedEndDate' => '',
				'plannedLeadTimeDays' => 0,
				'internalTargetDays' => 0,
				'maxSuspensionDays' => 0,
				'extensionPeriodDays' => 0,
				'statutoryWarningDays' => 0,
				'plannedWarningDays' => 0,
				'chainTermDays' => 0,
				'suspensionAllowed' => true,
				'extensionAllowed' => true,
			],
			$overrides
		);
	}//end declared()

	/**
	 * One term instance row.
	 *
	 * @param string $kind The kind it carries.
	 * @param string $end Its current end date.
	 * @param string $status Its lifecycle status.
	 * @param string $start Its start date.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function instanceOf(
		string $kind,
		string $end,
		string $status = 'lopend',
		string $start = '2026-09-01T00:00:00+00:00',
	): array {
		return [
			'id' => ($kind . '-1'),
			'case' => 'c1',
			'kind' => $kind,
			'startDate' => $start,
			'endDateCalculated' => $end,
			'endDateCurrent' => $end,
			'status' => $status,
		];
	}//end instanceOf()
}//end trait
