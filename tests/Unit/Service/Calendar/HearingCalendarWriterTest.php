<?php

/**
 * HearingCalendarWriter Unit Tests
 *
 * One write per principal into the first calendar that takes the event (it
 * accepts ICS, is not in the trash, is writable); a calendar that refuses
 * passes the event to the next; a principal whose calendars cannot be read
 * counts as not written; and a warning when nobody's calendar took it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Calendar;

use OCA\Dossiq\Service\Calendar\HearingCalendarWriter;
use OCA\Dossiq\Service\HearingCalendarEvent;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A calendar that takes ICS and says whether it is writable.
 */
interface WriterTestCalendar extends ICreateFromString, ICalendarIsWritable {
}

/**
 * Unit tests for HearingCalendarWriter.
 *
 * @covers \OCA\Dossiq\Service\Calendar\HearingCalendarWriter
 * @uses   \OCA\Dossiq\Service\HearingCalendarEvent
 */
final class HearingCalendarWriterTest extends TestCase {

	/**
	 * Every write, as label:name:ics.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * Every warning message, in order.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A calendar that records its writes.
	 *
	 * @param string          $label    Its label in the write log.
	 * @param bool            $deleted  Whether it is in the trash.
	 * @param bool            $writable Whether it is writable.
	 * @param \Throwable|null $refusal  What createFromString throws.
	 *
	 * @return WriterTestCalendar
	 */
	private function calendar(string $label, bool $deleted=false, bool $writable=true, ?\Throwable $refusal=null): WriterTestCalendar {
		$calendar = $this->createMock(WriterTestCalendar::class);
		$calendar->method('isDeleted')->willReturn($deleted);
		$calendar->method('isWritable')->willReturn($writable);
		$calendar->method('createFromString')->willReturnCallback(
			function (string $name, string $data) use ($label, $refusal): void {
				$this->writes[] = $label . ':' . $name . ':' . $data;
				if ($refusal !== null) {
					throw $refusal;
				}
			}
		);
		return $calendar;
	}//end calendar()

	/**
	 * The writer over a manager answering the given calendars per principal.
	 *
	 * @param array<string, mixed> $calendars Calendars by principal, or a Throwable to throw.
	 *
	 * @return HearingCalendarWriter
	 */
	private function writer(array $calendars): HearingCalendarWriter {
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('getCalendarsForPrincipal')->willReturnCallback(
			static function (string $principal) use ($calendars): array {
				$answer = ($calendars[$principal] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new HearingCalendarWriter(calendarManager: $manager, logger: $logger);
	}//end writer()

	/**
	 * A calendar that takes no ICS, one in the trash and a read-only one are passed over for the first that takes it.
	 *
	 * @return void
	 */
	public function testTheFirstCalendarThatTakesTheEventGetsIt(): void {
		$writer = $this->writer(
			calendars: [
				'principals/users/org' => [
					$this->createMock(ICalendar::class),
					$this->calendar(label: 'trash', deleted: true),
					$this->calendar(label: 'readonly', writable: false),
					$this->calendar(label: 'work'),
					$this->calendar(label: 'second'),
				],
			]
		);

		$written = $writer->writeForAll(event: new HearingCalendarEvent(uid: 'u-1', ics: 'ICS'), principals: ['principals/users/org']);

		$this->assertSame(1, $written);
		$this->assertSame(['work:u-1.ics:ICS'], $this->writes);
		$this->assertSame([], $this->warnings);
	}//end testTheFirstCalendarThatTakesTheEventGetsIt()

	/**
	 * A calendar that refuses passes the event to the next, and an unreadable principal counts as not written.
	 *
	 * @return void
	 */
	public function testARefusalFallsThroughAndAnUnreadablePrincipalIsSkipped(): void {
		$writer = $this->writer(
			calendars: [
				'principals/users/org' => [
					$this->calendar(label: 'full', refusal: new RuntimeException('full')),
					$this->calendar(label: 'spare'),
				],
				'principals/users/alice' => new RuntimeException('dav down'),
			]
		);

		$written = $writer->writeForAll(
			event: new HearingCalendarEvent(uid: 'u-2', ics: 'ICS'),
			principals: ['principals/users/org', 'principals/users/alice']
		);

		$this->assertSame(1, $written);
		$this->assertSame(['full:u-2.ics:ICS', 'spare:u-2.ics:ICS'], $this->writes);
		$this->assertSame(
			[
				'Calendar refused the hearing event for principals/users/org: full',
				'Could not read calendars for principals/users/alice: dav down',
			],
			$this->warnings
		);
	}//end testARefusalFallsThroughAndAnUnreadablePrincipalIsSkipped()

	/**
	 * When no principal's calendar takes the event, that is reported once.
	 *
	 * @return void
	 */
	public function testNobodysCalendarTakingItIsReported(): void {
		$writer = $this->writer(calendars: ['principals/users/org' => [$this->calendar(label: 'readonly', writable: false)]]);

		$written = $writer->writeForAll(
			event: new HearingCalendarEvent(uid: 'u-3', ics: 'ICS'),
			principals: ['principals/users/org', 'principals/users/bob']
		);

		$this->assertSame(0, $written);
		$this->assertSame([], $this->writes);
		$this->assertSame(['No writable calendar found for the hearing, event not stored'], $this->warnings);
	}//end testNobodysCalendarTakingItIsReported()

	/**
	 * Every principal whose calendar takes the event is counted.
	 *
	 * @return void
	 */
	public function testEveryPrincipalThatTookItIsCounted(): void {
		$writer = $this->writer(
			calendars: [
				'principals/users/org' => [$this->calendar(label: 'org')],
				'principals/users/alice' => [$this->calendar(label: 'alice')],
			]
		);

		$written = $writer->writeForAll(
			event: new HearingCalendarEvent(uid: 'u-4', ics: 'ICS'),
			principals: ['principals/users/org', 'principals/users/alice']
		);

		$this->assertSame(2, $written);
		$this->assertSame(['org:u-4.ics:ICS', 'alice:u-4.ics:ICS'], $this->writes);
	}//end testEveryPrincipalThatTookItIsCounted()
}//end class
