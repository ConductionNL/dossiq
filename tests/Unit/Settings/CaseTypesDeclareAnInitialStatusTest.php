<?php

/**
 * Every shipped case type with a status list starts its cases in the first status.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The case schema's lifecycle starts a case in its case type's `initialStatus`,
 * and OpenRegister refuses any other start state, so a case type with statuses
 * but no initial status cannot start a case in any of them.
 *
 * @coversNothing
 */
class CaseTypesDeclareAnInitialStatusTest extends TestCase {

	/**
	 * Case types shipped without any status of their own. This list only shrinks:
	 * a case type that gains statuses must also declare its initial status.
	 */
	private const WITHOUT_STATUSES = [
		'dossiq_register.json:leverancier-contractverlenging-verzoek',
		'dossiq_register.json:leverancier-iban-wijziging',
		'dossiq_register.json:leverancier-accreditatie-verificatie',
		'dossiq_register.json:leverancier-mutatie',
		'30-beschikking.json:wmo-melding',
		'50-subsidie.json:zaaktype-innovatiefonds-2026',
		'50-subsidie.json:zaaktype-cultuur-subsidie-2026',
		'zuiddrecht.json:zaaktype-omgevingsvergunning-regulier-v1',
		'zuiddrecht.json:zaaktype-omgevingsvergunning-regulier-v2',
		'zuiddrecht.json:zaaktype-omgevingsvergunning-regulier-v3',
	];

	/**
	 * Each case type with statuses names the lowest-order one of its own list.
	 *
	 * @return void
	 */
	public function testEveryCaseTypeStartsInItsFirstStatus(): void {
		$caseTypes = SeededCaseTypeStatuses::all();
		$this->assertGreaterThan(30, count($caseTypes), 'too few case types were read for this to mean anything');

		$wrong = [];
		foreach ($caseTypes as $caseType) {
			if ($caseType['statuses'] === []) {
				continue;
			}

			$first = SeededCaseTypeStatuses::firstStatusHandles(statuses: $caseType['statuses']);
			if (in_array($caseType['initialStatus'], $first, true) === false) {
				$wrong[] = $caseType['name'] . ' (initialStatus ' . var_export($caseType['initialStatus'], true) . ')';
			}
		}

		$this->assertSame([], $wrong, "These case types do not start in the first status of their own list:\n  " . implode("\n  ", $wrong));
	}//end testEveryCaseTypeStartsInItsFirstStatus()

	/**
	 * The case types without statuses are exactly the listed ones.
	 *
	 * @return void
	 */
	public function testOnlyTheListedCaseTypesHaveNoStatuses(): void {
		$without = [];
		foreach (SeededCaseTypeStatuses::all() as $caseType) {
			if ($caseType['statuses'] === []) {
				$without[] = $caseType['name'];
			}
		}

		$this->assertEqualsCanonicalizing(self::WITHOUT_STATUSES, $without);
	}//end testOnlyTheListedCaseTypesHaveNoStatuses()

	/**
	 * The reference forms the sources use reduce to the handle they name.
	 *
	 * @return void
	 */
	public function testReferencesReduceToTheirHandle(): void {
		$this->assertSame('a-slug', SeededCaseTypeStatuses::handle(ref: '@ref:a-slug'));
		$this->assertSame('a-slug', SeededCaseTypeStatuses::handle(ref: '@ref:statusType:a-slug'));
		$this->assertSame('a-key', SeededCaseTypeStatuses::handle(ref: '{{object:a-key.id}}'));
		$this->assertSame('c4e7e000-0000-4000-a000-000000000111', SeededCaseTypeStatuses::handle(ref: 'c4e7e000-0000-4000-a000-000000000111'));
		$this->assertNull(SeededCaseTypeStatuses::handle(ref: null));
	}//end testReferencesReduceToTheirHandle()
}//end class
