<?php

/**
 * A term whose pause ran out resumes by itself and tells the handler.
 *
 * Pins what DeadlinePauseService::resumeAfterExpiry writes: the end date the
 * pause pushed out stands, the pause is cleared, the engine timer resumes,
 * and the `hervat` event carries the notice the notification rule fires on.
 * And pins that TermijnService::recordEvent keeps only the declared notice
 * fields, and none that is empty.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 * @covers \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\Term\DayCount
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class TermResumedAfterExpiryTest extends TestCase {

	/**
	 * The store both services write through.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The term service over that store.
	 *
	 * @var TermijnService
	 */
	private TermijnService $terms;

	/**
	 * Build the term service over an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
				default => '',
			}
		);
		$this->terms = new TermijnService($settings, new NullLogger());
	}//end setUp()

	/**
	 * The pause is used up, so the end date it pushed out stands; the term
	 * runs again, the timer resumes and the event tells the handler.
	 *
	 * @return void
	 */
	public function testTheTermResumesOnTheEndThePauseSet(): void {
		$id = $this->seed(['status' => 'paused', 'endDateCurrent' => '2026-07-29', 'pauseDeadline' => '2026-07-15', 'pauseTimerId' => 'helper-1', 'pauzeStartDatum' => '2026-07-01', 'pauzeDuurDagen' => 14, 'pauseWaitingOn' => 'applicant', 'lastChasedAt' => '2026-07-10']);
		$resumed = [];
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnArgument(0);
		$timers->expects(self::once())->method('resumeBeslistermijn')->willReturnCallback(
			static function (array $instance, string $reason) use (&$resumed): bool {
				$resumed = [$instance['id'], $reason];
				return true;
			}
		);
		$moment = new DateTimeImmutable('2026-07-15T00:05:00+02:00');

		$result = (new DeadlinePauseService(termService: $this->terms, timerService: $timers))->resumeAfterExpiry(
			termInstanceId: $id,
			notice: ['notify' => 'pieter', 'caseTitle' => 'Melding Jeugdwet', 'endDate' => '1999-01-01'],
			moment: $moment,
		);

		self::assertSame([$id, DeadlinePauseService::EXPIRED_RESUME_REASON], $resumed);
		self::assertSame('lopend', $result['status']);
		self::assertSame('2026-07-29', $result['endDateCurrent']);
		self::assertNull($result['pauseDeadline']);
		self::assertNull($result['pauseTimerId']);
		self::assertSame('', $result['pauseWaitingOn']);
		self::assertNull($result['lastChasedAt']);

		$events = $this->events();
		self::assertCount(1, $events);
		self::assertSame('hervat', $events[0]['type']);
		self::assertSame('AWB 4:15', $events[0]['basis']);
		self::assertSame(DeadlinePauseService::EXPIRED_RESUME_REASON, $events[0]['rationale']);
		self::assertSame(0, $events[0]['daysImpact']);
		self::assertSame($moment->format('Y-m-d\TH:i:sP'), $events[0]['moment']);
		self::assertSame('pieter', $events[0]['notify']);
		self::assertSame('Melding Jeugdwet', $events[0]['caseTitle']);
		self::assertSame('2026-07-29', $events[0]['endDate'], 'the end date is the resumed one, not the caller\'s');
	}//end testTheTermResumesOnTheEndThePauseSet()

	/**
	 * The administered calendar decides the day the resumed end lands on.
	 *
	 * @return void
	 */
	public function testTheResumedEndIsRolledByTheCalendar(): void {
		$id = $this->seed(['status' => 'paused', 'endDateCurrent' => '2026-08-01']);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnCallback(
			static fn (DateTimeImmutable $date): DateTimeImmutable => $date->modify('+2 days')
		);

		$result = (new DeadlinePauseService(termService: $this->terms, timerService: $timers))->resumeAfterExpiry(termInstanceId: $id);

		self::assertSame('2026-08-03', $result['endDateCurrent']);
		self::assertSame('2026-08-03', $this->events()[0]['endDate']);
		self::assertArrayNotHasKey('notify', $this->events()[0], 'no notice given, nobody told');
	}//end testTheResumedEndIsRolledByTheCalendar()

	/**
	 * A missing instance and one that is no longer paused are refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testOnlyAPausedTermResumes(): void {
		$running = $this->seed(['status' => 'lopend', 'endDateCurrent' => '2026-08-01']);
		$service = new DeadlinePauseService(termService: $this->terms);
		$messages = [];
		foreach (['missing', $running] as $id) {
			try {
				$service->resumeAfterExpiry(termInstanceId: $id, notice: ['notify' => 'pieter']);
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(['TermijnInstance not found: missing', 'TermijnInstance not in gepauzeerd state: ' . $running], $messages);
		self::assertSame([], $this->events());
		self::assertSame('2026-08-01', $this->objects->store['deadlineInstance'][$running]['endDateCurrent']);
	}//end testOnlyAPausedTermResumes()

	/**
	 * An event keeps only the declared notice fields, trimmed, and none that
	 * is empty; the base fields cannot be overwritten through the notice.
	 *
	 * @return void
	 */
	public function testAnEventKeepsOnlyTheDeclaredNoticeFields(): void {
		$id = $this->seed(['status' => 'lopend']);

		$this->terms->recordEvent(
			termInstanceId: $id,
			type: 'verleng',
			basis: 'AWB 4:14',
			rationale: 'r',
			daysImpact: 7,
			notice: ['notify' => ' pieter ', 'caseTitle' => '', 'type' => 'hervat', 'actor' => 'mallory'],
		);
		$this->terms->recordEvent(termInstanceId: $id, type: 'pause', basis: 'AWB 4:5', rationale: 'r', daysImpact: 3);

		[$told, $plain] = $this->events();
		self::assertSame('pieter', $told['notify']);
		self::assertArrayNotHasKey('caseTitle', $told);
		self::assertArrayNotHasKey('endDate', $told);
		self::assertSame('verleng', $told['type']);
		self::assertSame('system', $told['actor']);
		self::assertSame([], array_intersect(TermijnService::NOTICE_FIELDS, array_keys($plain)));
	}//end testAnEventKeepsOnlyTheDeclaredNoticeFields()

	/**
	 * Seed one term instance, returning its id.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return string
	 */
	private function seed(array $fields): string {
		$row = $this->objects->seed('deadlineInstance', (['case' => 'case-1'] + $fields));

		return (string)$row['id'];
	}//end seed()

	/**
	 * Every recorded event, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function events(): array {
		return array_values($this->objects->store['termijnGebeurtenis'] ?? []);
	}//end events()
}//end class
