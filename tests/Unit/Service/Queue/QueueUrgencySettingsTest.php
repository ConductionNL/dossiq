<?php

/**
 * Tests for the queue urgency settings reader and its profile.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * A stored setting never breaks the queue: it reads as a number in bounds.
 *
 * @covers \OCA\Dossiq\Service\Queue\QueueUrgencySettings
 * @covers \OCA\Dossiq\Service\Queue\UrgencyProfile
 *
 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueUrgencySettingsTest extends TestCase {

	/**
	 * Read the profile over a given app config.
	 *
	 * @param array<string, string> $config The stored values.
	 *
	 * @return UrgencyProfile The profile read.
	 */
	private function read(array $config): UrgencyProfile {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return (new QueueUrgencySettings(settings: $settings))->profile();
	}//end read()

	/**
	 * Nothing stored reads as the defaults.
	 *
	 * @return void
	 */
	public function testNothingStoredReadsAsTheDefaults(): void {
		$this->assertSame(
			['criticalDays' => 3, 'warningDays' => 7, 'priorityWeight' => 10.0, 'idleWeight' => 0.5],
			$this->read(config: [])->toArray()
		);
	}//end testNothingStoredReadsAsTheDefaults()

	/**
	 * Stored values are read.
	 *
	 * @return void
	 */
	public function testStoredValuesAreRead(): void {
		$profile = $this->read(
			config: [
				'queue_critical_days' => '5',
				'queue_warning_days' => '10',
				'queue_priority_weight' => '20',
				'queue_idle_weight' => '1.25',
			]
		);

		$this->assertSame(['criticalDays' => 5, 'warningDays' => 10, 'priorityWeight' => 20.0, 'idleWeight' => 1.25], $profile->toArray());
	}//end testStoredValuesAreRead()

	/**
	 * A broken value reads as its default.
	 *
	 * @return void
	 */
	public function testABrokenValueReadsAsTheDefault(): void {
		$profile = $this->read(config: ['queue_critical_days' => 'abc', 'queue_idle_weight' => 'NaN']);

		$this->assertSame(3, $profile->criticalDays);
		$this->assertSame(0.5, $profile->idleWeight);
	}//end testABrokenValueReadsAsTheDefault()

	/**
	 * Values out of bounds are clamped.
	 *
	 * @return void
	 */
	public function testOutOfBoundsValuesAreClamped(): void {
		$profile = $this->read(
			config: [
				'queue_critical_days' => '-4',
				'queue_warning_days' => '500',
				'queue_priority_weight' => '80',
				'queue_idle_weight' => '9',
			]
		);

		$this->assertSame(['criticalDays' => 0, 'warningDays' => 120, 'priorityWeight' => 50.0, 'idleWeight' => 1.5], $profile->toArray());
	}//end testOutOfBoundsValuesAreClamped()

	/**
	 * A warning threshold below the critical one reads as equal to it.
	 *
	 * @return void
	 */
	public function testWarningBelowCriticalReadsAsEqual(): void {
		$profile = $this->read(config: ['queue_critical_days' => '5', 'queue_warning_days' => '2']);

		$this->assertSame(5, $profile->warningDays);
	}//end testWarningBelowCriticalReadsAsEqual()

	/**
	 * A case type's override replaces only what it sets.
	 *
	 * @return void
	 */
	public function testACaseTypeOverrideReplacesOnlyWhatItSets(): void {
		$base = $this->read(config: ['queue_priority_weight' => '15']);

		$both = $base->withCaseTypeThresholds(criticalDays: 10, warningDays: '20');
		$one = $base->withCaseTypeThresholds(criticalDays: null, warningDays: 12);
		$none = $base->withCaseTypeThresholds(criticalDays: '', warningDays: null);

		$this->assertSame([10, 20, 15.0], [$both->criticalDays, $both->warningDays, $both->priorityWeight]);
		$this->assertSame([3, 12], [$one->criticalDays, $one->warningDays]);
		$this->assertSame([3, 7], [$none->criticalDays, $none->warningDays]);
	}//end testACaseTypeOverrideReplacesOnlyWhatItSets()
}//end class
