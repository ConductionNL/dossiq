<?php

/**
 * Pins the whole timer configuration TermijnTimerService arms, key for key.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test of the configurations handed to the engine on arm.
 *
 * @covers \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\ThresholdShares
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermEndRoll
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class TermijnTimerArmGoldenTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * Where the golden configurations live.
	 */
	private const GOLDEN = __DIR__ . '/../Fixtures/termijn-timer-arm-golden.json';

	/**
	 * Every arm call, in order, matches the golden file key for key.
	 *
	 * @return void
	 */
	public function testArmedConfigurationsMatchTheGoldenFile(): void {
		$engine = new FlowTimerEngineFake();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($engine);
		$service = new TermijnTimerService(
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
			dates: $this->caseDates(),
		);
		$instance = [
			'id' => 'ti-1',
			'case' => 'Z/2026/1',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-06-01T10:00:00+00:00',
			'endDateCurrent' => '2026-07-27',
		];

		$uuids = [
			$service->armBeslistermijn(instance: $instance, definitie: ['standardDurationDays' => 56, 'countExtensions' => 3, 'legalBasis' => 'Wabo']),
			$service->armBeslistermijn(instance: $instance, definitie: ['countingMode' => 'workingDays', 'escalationLadder' => [['share' => 0.5, 'priority' => 'high']]]),
			$service->armBeslistermijn(instance: ['id' => 'ti-2', 'startDate' => '2026-10-22T07:15:00+00:00', 'endDateCurrent' => '2026-11-01'], definitie: []),
			$service->armBeslistermijn(instance: ['id' => 'ti-4', 'startDate' => '2026-06-01', 'endDateCurrent' => '2026-05-01'], definitie: ['countExtensions' => 0, 'standardDurationDays' => 20]),
			$service->armBeslistermijn(instance: ['id' => '', 'startDate' => '2026-06-01'], definitie: []),
			$service->armBeslistermijn(instance: ['id' => 'ti-5'], definitie: []),
			$service->armHersteltermijn(instance: $instance, durationDays: 14, chaseOffsets: [7, 0, 2]),
			$service->armHersteltermijn(instance: ['id' => 'ti-3'], durationDays: 3),
			$service->armHersteltermijn(instance: ['id' => 'ti-3'], durationDays: 0),
			$service->armHersteltermijn(instance: [], durationDays: 5),
		];

		$actual = json_encode(
			['uuids' => $uuids, 'arm' => $this->normalised(calls: $engine->calls['arm'])],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
		);
		if (getenv('DOSSIQ_WRITE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $actual . "\n");
		}

		self::assertSame(trim((string)file_get_contents(self::GOLDEN)), $actual);
	}//end testArmedConfigurationsMatchTheGoldenFile()

	/**
	 * The arm calls with every date written out, so the JSON holds them.
	 *
	 * @param array<int, array<string, mixed>> $calls The recorded calls.
	 *
	 * @return array<int, array<string, mixed>> The calls, dates as text.
	 */
	private function normalised(array $calls): array {
		array_walk_recursive(
			$calls,
			static function (mixed &$value): void {
				if ($value instanceof DateTimeInterface) {
					$value = $value->format(DateTimeInterface::ATOM);
				}
			}
		);

		return $calls;
	}//end normalised()
}//end class
