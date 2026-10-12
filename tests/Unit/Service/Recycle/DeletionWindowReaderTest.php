<?php

/**
 * DeletionWindowReader unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Recycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use DateTimeImmutable;
use OCA\Dossiq\Service\Recycle\DeletionWindowReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The days a removed case has left, read off its deletion marker.
 *
 * @covers \OCA\Dossiq\Service\Recycle\DeletionWindowReader
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class DeletionWindowReaderTest extends TestCase {
	/**
	 * The window of an entity whose marker ends this many days from today.
	 *
	 * @param int $days Days ahead, negative for the past.
	 *
	 * @return array<string, mixed>|null
	 */
	private function windowEndingIn(int $days): ?array {
		$ends = (new DateTimeImmutable('today'))->modify(sprintf('%+d days', $days))->format('Y-m-d');
		$entity = new class($ends) {
			/**
			 * @param string $ends The day the case may be destroyed.
			 */
			public function __construct(
				private string $ends,
			) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getDeleted(): array {
				return ['destroyableFrom' => $this->ends . 'T00:00:00+00:00', 'retentionPeriod' => 30];
			}
		};

		$reader = new DeletionWindowReader(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: new NullLogger(),
		);

		return $reader->fromMarker(entity: $entity);
	}//end windowEndingIn()

	/**
	 * The days remaining count down to the day the case may be destroyed.
	 *
	 * @return void
	 */
	public function testDaysRemainingCountDownToTheEnd(): void {
		$this->assertSame(expected: 12, actual: $this->windowEndingIn(days: 12)['daysRemaining']);
		$this->assertSame(expected: 1, actual: $this->windowEndingIn(days: 1)['daysRemaining']);
		$this->assertFalse($this->windowEndingIn(days: 1)['lapsed']);
	}//end testDaysRemainingCountDownToTheEnd()

	/**
	 * On the end day and after it the window has lapsed, and never reads negative.
	 *
	 * @return void
	 */
	public function testALapsedWindowReadsZero(): void {
		foreach ([0, -1, -40] as $days) {
			$window = $this->windowEndingIn(days: $days);

			$this->assertSame(expected: 0, actual: $window['daysRemaining']);
			$this->assertTrue($window['lapsed']);
		}
	}//end testALapsedWindowReadsZero()
}//end class
