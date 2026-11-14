<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A planned item is written to the first writable calendar of its owner,
 * tagged so the queue finds it again, and read back for the next two weeks.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Queue\PersonalAgendaItemService;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\PersonalAgendaItemService
 */
class PersonalAgendaPlanTest extends TestCase {

	/**
	 * The calendar manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $calendars;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * What the event builder was told.
	 *
	 * @var array<string,mixed>
	 */
	private array $built = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calendars = $this->createMock(IManager::class);
		$this->users     = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'els' ? $this->createMock(IUser::class) : null);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A planned item is tagged, timed by its template and written to the first writable calendar.
	 *
	 * @return void
	 */
	public function testAPlannedItemIsWrittenToTheFirstWritableCalendar(): void {
		$this->builder(ics: "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:abc-123\r\nSUMMARY:Bel terug\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
		$readOnly = $this->calendar(writable: false);
		$readOnly->expects($this->never())->method('createFromString');
		$failing = $this->calendar(writable: true);
		$failing->method('createFromString')->willThrowException(new RuntimeException('quota'));
		$written = [];
		$target  = $this->calendar(writable: true);
		$target->method('createFromString')->willReturnCallback(
			static function (string $name, string $data) use (&$written): void {
				$written = [$name, $data];
			}
		);
		$this->calendars->method('getCalendarsForPrincipal')->with('principals/users/els')
			->willReturn([$this->createMock(ICalendar::class), $readOnly, $failing, $target]);
		$this->logger->expects($this->once())->method('warning')->with('Dossiq: a planned item could not be written: quota');

		$planned = $this->service()->plan(userId: 'els', title: '  Bel terug ', startsAt: '2026-11-20T09:00:00+01:00', template: 'call-back');

		$this->assertSame(['uid' => 'abc-123', 'startsAt' => '2026-11-20T09:00:00+01:00', 'minutes' => 15], $planned);
		$this->assertSame('Bel terug', $this->built['summary']);
		$this->assertSame('2026-11-20T09:15:00+01:00', $this->built['end']->format(DateTimeInterface::ATOM));
		$this->assertSame('Planned from your queue. It is not attached to a case.', $this->built['description']);
		$this->assertSame('abc-123.ics', $written[0]);
		$this->assertStringContainsString("BEGIN:VEVENT\r\nCATEGORIES:DOSSIQ-PLANNED\r\nUID:abc-123", $written[1]);
	}//end testAPlannedItemIsWrittenToTheFirstWritableCalendar()

	/**
	 * An event without a UID cannot be written.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAUidIsRefused(): void {
		$this->builder(ics: "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
		$this->calendars->expects($this->never())->method('getCalendarsForPrincipal');

		$this->expectExceptionObject(new RuntimeException('The planned item could not be written.'));
		$this->service()->plan(userId: 'els', title: 'x', startsAt: '2026-11-20T09:00:00+01:00');
	}//end testAnEventWithoutAUidIsRefused()

	/**
	 * The upcoming items are this person's tagged events in the next two weeks, each once.
	 *
	 * @return void
	 */
	public function testUpcomingItemsAreReadFromEveryCalendarOnce(): void {
		$now   = new DateTimeImmutable('2026-11-13T08:00:00+01:00');
		$start = new DateTimeImmutable('2026-11-14T10:00:00+01:00');
		$first = $this->createMock(ICalendar::class);
		$first->expects($this->once())->method('search')
			->with('DOSSIQ-PLANNED', ['CATEGORIES'], ['timerange' => ['start' => $now, 'end' => $now->modify('+14 days')]], 100)
			->willReturn(
				[
					['UID' => ['u-1'], 'objects' => [['DTSTART' => [$start], 'SUMMARY' => [' Site visit ']]]],
					['uid' => 'u-2', 'summary' => 'Catch up'],
					['UID' => [''], 'objects' => []],
				]
			);
		$second = $this->createMock(ICalendar::class);
		$second->method('search')->willReturn([['UID' => ['u-1'], 'objects' => [['DTSTART' => [$start], 'SUMMARY' => ['Site visit (copy)']]]]]);
		$this->calendars->method('getCalendarsForPrincipal')->willReturn([$first, $second]);

		$this->assertSame(
			[
				['uid' => 'u-1', 'title' => 'Site visit (copy)', 'startsAt' => '2026-11-14T10:00:00+01:00'],
				['uid' => 'u-2', 'title' => 'Catch up', 'startsAt' => ''],
			],
			$this->service()->upcomingFor(userId: 'els', now: $now)
		);
	}//end testUpcomingItemsAreReadFromEveryCalendarOnce()

	/**
	 * An unknown person has no items; a failing calendar search is raised.
	 *
	 * @return void
	 */
	public function testUnknownPeopleAndFailingSearches(): void {
		$failing = $this->createMock(ICalendar::class);
		$failing->method('search')->willThrowException(new \LogicException('dav offline'));
		$this->calendars->method('getCalendarsForPrincipal')->willReturn([$failing]);
		$service = $this->service();

		$this->assertSame([], $service->upcomingFor(userId: 'nobody'));
		$this->expectExceptionObject(new RuntimeException('dav offline'));
		$service->upcomingFor(userId: 'els');
	}//end testUnknownPeopleAndFailingSearches()

	/**
	 * The service under test.
	 *
	 * @return PersonalAgendaItemService
	 */
	private function service(): PersonalAgendaItemService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PersonalAgendaItemService(calendars: $this->calendars, users: $this->users, l10n: $l10n, logger: $this->logger);
	}//end service()

	/**
	 * Let the manager hand out an event builder that renders the given ICS.
	 *
	 * @param string $ics The rendered event.
	 *
	 * @return void
	 */
	private function builder(string $ics): void {
		$builder = $this->createMock(ICalendarEventBuilder::class);
		foreach (['setSummary' => 'summary', 'setStartDate' => 'start', 'setEndDate' => 'end', 'setDescription' => 'description'] as $method => $key) {
			$builder->method($method)->willReturnCallback(
				function (mixed $value) use ($builder, $key): ICalendarEventBuilder {
					$this->built[$key] = $value;
					return $builder;
				}
			);
		}

		$builder->method('toIcs')->willReturn($ics);
		$this->calendars->method('createEventBuilder')->willReturn($builder);
	}//end builder()

	/**
	 * A calendar that can be written to from a string, writable or not.
	 *
	 * @param bool $writable Whether it is writable.
	 *
	 * @return ICalendar&ICreateFromString&ICalendarIsWritable&MockObject
	 */
	private function calendar(bool $writable): ICalendar&ICreateFromString&ICalendarIsWritable&MockObject {
		$calendar = $this->createMockForIntersectionOfInterfaces([ICreateFromString::class, ICalendarIsWritable::class]);
		$calendar->method('isWritable')->willReturn($writable);
		return $calendar;
	}//end calendar()
}//end class
