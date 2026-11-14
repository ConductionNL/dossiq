<?php

/**
 * UrgencyProfile unit tests.
 *
 * The profile reads every threshold leniently: a value that is absent, not a
 * number, or not finite falls back to its own default rather than taking the
 * whole profile down, and each is clamped into its bounds. withQueueExtras
 * and withCaseTypeThresholds derive a new profile without touching the
 * original.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\UrgencyProfile;
use PHPUnit\Framework\TestCase;

/**
 * Lenient parsing, clamping and the derived profiles.
 *
 * @covers \OCA\Dossiq\Service\Queue\UrgencyProfile
 */
class UrgencyProfileTest extends TestCase {

	public function testADefaultProfileCarriesTheDeclaredDefaults(): void {
		$profile = new UrgencyProfile();

		$this->assertSame(UrgencyProfile::DEFAULT_CRITICAL_DAYS, $profile->toArray()['criticalDays']);
		$this->assertSame(UrgencyProfile::DEFAULT_IN_SIGHT_DAYS, $profile->inSightDays());
		$this->assertSame(UrgencyProfile::DEFAULT_RESIDENT_WAIT_WEIGHT, $profile->residentWaitWeight());
	}//end testADefaultProfileCarriesTheDeclaredDefaults()

	public function testANonFiniteWeightFallsBackToItsDefault(): void {
		// (float) 'INF' is INF, which is not finite: the lenient read must give
		// the default back rather than letting a non-number into the score.
		$profile = new UrgencyProfile(priorityWeight: 'INF', idleWeight: '1e400');

		$this->assertSame(UrgencyProfile::DEFAULT_PRIORITY_WEIGHT, $profile->toArray()['priorityWeight']);
		$this->assertSame(UrgencyProfile::DEFAULT_IDLE_WEIGHT, $profile->toArray()['idleWeight']);
	}//end testANonFiniteWeightFallsBackToItsDefault()

	public function testValuesAreClampedIntoTheirBoundsAndWarningNeverBelowCritical(): void {
		$profile = new UrgencyProfile(criticalDays: 999, warningDays: 1, priorityWeight: 999.0, idleWeight: -4.0);

		$this->assertSame(UrgencyProfile::MAX_CRITICAL_DAYS, $profile->toArray()['criticalDays']);
		// The warning threshold is never below the critical one.
		$this->assertSame(UrgencyProfile::MAX_CRITICAL_DAYS, $profile->toArray()['warningDays']);
		$this->assertSame(UrgencyProfile::MAX_PRIORITY_WEIGHT, $profile->toArray()['priorityWeight']);
		$this->assertSame(0.0, $profile->toArray()['idleWeight']);
	}//end testValuesAreClampedIntoTheirBoundsAndWarningNeverBelowCritical()

	public function testQueueExtrasAreReadBackAndInSightIsNeverBelowWarning(): void {
		$profile = (new UrgencyProfile(warningDays: 20))->withQueueExtras(inSightDays: 5, residentWaitWeight: 12.5);

		// 5 is below the warning threshold of 20, so in-sight holds at 20.
		$this->assertSame(20, $profile->inSightDays());
		$this->assertSame(12.5, $profile->residentWaitWeight());

		$wide = (new UrgencyProfile())->withQueueExtras(inSightDays: 30, residentWaitWeight: 'nonsense');
		$this->assertSame(30, $wide->inSightDays());
		$this->assertSame(UrgencyProfile::DEFAULT_RESIDENT_WAIT_WEIGHT, $wide->residentWaitWeight());
	}//end testQueueExtrasAreReadBackAndInSightIsNeverBelowWarning()

	public function testCaseTypeThresholdsFallBackPerFieldAndDoNotTouchTheOriginal(): void {
		$base = (new UrgencyProfile(criticalDays: 4, warningDays: 9))->withQueueExtras(inSightDays: 15, residentWaitWeight: 6.0);

		// Only the warning threshold is overridden; critical keeps the base.
		$typed = $base->withCaseTypeThresholds(criticalDays: null, warningDays: 12);

		$this->assertSame(4, $typed->toArray()['criticalDays']);
		$this->assertSame(12, $typed->toArray()['warningDays']);
		// The queue extras travel onto the typed profile.
		$this->assertSame(15, $typed->inSightDays());
		$this->assertSame(6.0, $typed->residentWaitWeight());
		// The base is untouched.
		$this->assertSame(9, $base->toArray()['warningDays']);
	}//end testCaseTypeThresholdsFallBackPerFieldAndDoNotTouchTheOriginal()
}//end class
