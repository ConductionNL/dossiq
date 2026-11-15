<?php

/**
 * Waking slept triage items, and reading their wake date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use DateTime;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\TriageSleep;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\TriageSleep
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class TriageSleepWakeTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The service on a log answering these entries, with today 2026-09-14.
	 *
	 * @param array<int, array<string, mixed>>    $entries The stored triage items.
	 * @param array<int, array<string, mixed>>    $amends  Filled with every amendment asked for.
	 * @param array<int, string>                  $refuse  Entry ids whose amendment does not land.
	 *
	 * @return TriageSleep
	 */
	private function sleepService(array $entries, array &$amends = [], array $refuse = []): TriageSleep {
		$log = $this->createMock(IntakeLog::class);
		$log->method('search')->willReturnCallback(
			static fn (array $filters = []): array => array_values(
				array_filter($entries, static fn (array $entry): bool => ($entry['outcome'] ?? '') === ($filters['outcome'] ?? null))
			)
		);
		$log->method('amend')->willReturnCallback(
			static function (string $entryId, array $changes) use (&$amends, $refuse): bool {
				$amends[] = ['id' => $entryId, 'changes' => $changes];
				return in_array($entryId, $refuse, true) === false;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14 11:00:00'));

		return new TriageSleep(log: $log, dates: $this->caseDates(), time: $time);
	}//end sleepService()

	/**
	 * Due items are woken by their id; one without any id is passed over, one still asleep is left.
	 *
	 * @return void
	 */
	public function testDueItemsAreWokenByTheirIdAndAnItemWithoutIdIsPassedOver(): void {
		$amends  = [];
		$service = $this->sleepService(
			entries: [
				['outcome' => IntakeLog::OUTCOME_QUARANTINED, 'sleepUntil' => '2026-09-01'],
				['@self' => ['id' => 'self-1'], 'id' => 'plain-1', 'outcome' => IntakeLog::OUTCOME_QUARANTINED, 'sleepUntil' => '2026-09-14'],
				['id' => 'later-1', 'outcome' => IntakeLog::OUTCOME_INBOX, 'sleepUntil' => '2026-09-20'],
				['id' => 'inbox-1', 'outcome' => IntakeLog::OUTCOME_INBOX, 'sleepUntil' => '2026-09-10'],
				['id' => 'refused-1', 'outcome' => IntakeLog::OUTCOME_INBOX, 'sleepUntil' => '2026-09-10'],
				['id' => 'awake-1', 'outcome' => IntakeLog::OUTCOME_INBOX, 'sleepUntil' => ''],
			],
			amends: $amends,
			refuse: ['refused-1']
		);

		self::assertSame(['self-1', 'inbox-1'], $service->wakeDue());
		self::assertSame(
			[
				['id' => 'self-1', 'changes' => ['sleepUntil' => '', 'sleptBy' => '']],
				['id' => 'inbox-1', 'changes' => ['sleepUntil' => '', 'sleptBy' => '']],
				['id' => 'refused-1', 'changes' => ['sleepUntil' => '', 'sleptBy' => '']],
			],
			$amends
		);
	}//end testDueItemsAreWokenByTheirIdAndAnItemWithoutIdIsPassedOver()

	/**
	 * The wake date is the calendar day of `sleepUntil`, and empty when it does not read as one.
	 *
	 * @return void
	 */
	public function testTheWakeDateIsTheCalendarDayOrEmpty(): void {
		$service = $this->sleepService(entries: []);

		self::assertSame('2026-09-20', $service->wakeDateOf(entry: ['sleepUntil' => '2026-09-20']));
		self::assertSame('', $service->wakeDateOf(entry: ['sleepUntil' => 'next week']));
		self::assertSame('', $service->wakeDateOf(entry: []));
	}//end testTheWakeDateIsTheCalendarDayOrEmpty()

	/**
	 * Only a value the normaliser reads as a date reads as one.
	 *
	 * @return void
	 */
	public function testReadsAsDate(): void {
		$service = $this->sleepService(entries: []);

		self::assertTrue($service->readsAsDate(value: '2026-09-20'));
		self::assertFalse($service->readsAsDate(value: 'next week'));
		self::assertFalse($service->readsAsDate(value: ''));
	}//end testReadsAsDate()
}//end class
