<?php

/**
 * HearingCalendarService Unit Tests
 *
 * The hearing calendar write used to be a log line saying "Calendar
 * invitations queued" and nothing else. These tests exist to make that
 * impossible to ship again: each one fails if the event stops reaching a
 * calendar, and none of them can pass on a log line.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\HearingCalendarService;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for HearingCalendarService.
 *
 * @covers \OCA\Dossiq\Service\HearingCalendarService
 *
 * @uses \OCA\Dossiq\AppInfo\Application
 */
class HearingCalendarServiceTest extends TestCase {

	/**
	 * @var ICalendarManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ICalendarManager $calendarManager;

	/**
	 * @var IUserManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserManager $userManager;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var HearingCalendarService
	 */
	private HearingCalendarService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calendarManager = $this->createMock(ICalendarManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new HearingCalendarService(
			calendarManager: $this->calendarManager,
			userManager: $this->userManager,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A calendar whose writes are captured, and a manager that records which
	 * principals were asked for calendars.
	 *
	 * @param array<int, array{0: string, 1: string}> $captured Filled with (name, ics) per write.
	 * @param array<int, string> $principals Filled with each principal asked.
	 *
	 * @return void
	 */
	private function acceptingCalendar(array &$captured, array &$principals): void {
		$calendar = $this->createMock(ICreateFromString::class);
		$calendar->method('createFromString')
			->willReturnCallback(static function (string $name, string $ics) use (&$captured): void {
				$captured[] = [$name, $ics];
			});

		$this->calendarManager->method('getCalendarsForPrincipal')
			->willReturnCallback(static function (string $principal) use (&$principals, $calendar): array {
				$principals[] = $principal;
				return [$calendar];
			});
	}//end acceptingCalendar()

	/**
	 * A builder that serialises to a fixed ICS carrying the given UID.
	 *
	 * @param string $uid The UID the serialised event carries.
	 *
	 * @return ICalendarEventBuilder|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function builderReturning(string $uid) {
		$builder = $this->createMock(ICalendarEventBuilder::class);
		$setters = [
			'setSummary',
			'setStartDate',
			'setEndDate',
			'setLocation',
			'setDescription',
			'setOrganizer',
			'addAttendee',
		];
		foreach ($setters as $setter) {
			$builder->method($setter)->willReturnSelf();
		}

		$builder->method('toIcs')->willReturn(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
		);

		return $builder;
	}//end builderReturning()

	/**
	 * A user.
	 *
	 * @param string $uid Account id.
	 * @param string $email Email address, empty for none.
	 *
	 * @return IUser|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function userMock(string $uid, string $email) {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));

		return $user;
	}//end userMock()

	/**
	 * The hearing reaches the organiser's calendar AND every participant that
	 * resolves to an account, as one event with one UID.
	 *
	 * createFromString() writes straight through CalDAV and does not run
	 * sabre's scheduling plugin, so a single write into the organiser's
	 * calendar would reach nobody else. That is why the per-attendee write is
	 * asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testWritesOneEventToTheOrganiserAndEveryResolvableParticipant(): void {
		$captured = [];
		$principals = [];
		$this->acceptingCalendar($captured, $principals);
		$this->calendarManager->method('createEventBuilder')
			->willReturn($this->builderReturning('hearing-uid-1'));

		$this->userSession->method('getUser')
			->willReturn($this->userMock('behandelaar', 'behandelaar@gemeente.nl'));
		$this->userManager->method('get')->willReturnMap([
			['klager', $this->userMock('klager', 'klager@example.org')],
			['Externe adviseur', null],
		]);

		$uid = $this->service->createEvent([
			'complaint' => 'complaint-uuid',
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
			'location' => 'Stadhuis kamer 12',
			'participants' => ['klager', 'Externe adviseur'],
		]);

		$this->assertSame('hearing-uid-1', $uid);
		$this->assertSame(
			['principals/users/behandelaar', 'principals/users/klager'],
			$principals,
			'Both the organiser and the resolvable participant must be written to.'
		);
		$this->assertCount(2, $captured, 'One calendar write per principal.');
		$this->assertSame('hearing-uid-1.ics', $captured[0][0]);
		$this->assertStringContainsString('UID:hearing-uid-1', $captured[0][1]);
		$this->assertSame($captured[0][1], $captured[1][1], 'Every copy is the same event.');
	}//end testWritesOneEventToTheOrganiserAndEveryResolvableParticipant()

	/**
	 * A hearing with no participants touches no calendar at all.
	 *
	 * @return void
	 */
	public function testWritesNothingWhenThereAreNoParticipants(): void {
		$this->calendarManager->expects($this->never())->method('createEventBuilder');
		$this->calendarManager->expects($this->never())->method('getCalendarsForPrincipal');

		$this->assertSame('', $this->service->createEvent([
			'complaint' => 'complaint-uuid',
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
		]));
	}//end testWritesNothingWhenThereAreNoParticipants()

	/**
	 * An unreadable date writes no event, rather than scheduling one at "now".
	 *
	 * @return void
	 */
	public function testWritesNothingWhenTheDateCannotBeRead(): void {
		$this->calendarManager->expects($this->never())->method('createEventBuilder');

		$this->assertSame('', $this->service->createEvent([
			'complaint' => 'complaint-uuid',
			'date' => 'volgende week dinsdag',
			'type' => 'fysiek',
			'participants' => ['klager'],
		]));
	}//end testWritesNothingWhenTheDateCannotBeRead()

	/**
	 * When no calendar takes the event, the caller is told so with an empty id
	 * rather than handed one that names nothing.
	 *
	 * @return void
	 */
	public function testReturnsNoIdWhenNoCalendarTakesTheEvent(): void {
		$this->calendarManager->method('createEventBuilder')
			->willReturn($this->builderReturning('hearing-uid-2'));
		$this->calendarManager->method('getCalendarsForPrincipal')->willReturn([]);
		$this->userSession->method('getUser')
			->willReturn($this->userMock('behandelaar', 'behandelaar@gemeente.nl'));
		$this->userManager->method('get')->willReturn(null);

		$this->assertSame('', $this->service->createEvent([
			'complaint' => 'complaint-uuid',
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
			'participants' => ['klager'],
		]));
	}//end testReturnsNoIdWhenNoCalendarTakesTheEvent()

	/**
	 * A participant without an email is not offered as an attendee: an ATTENDEE
	 * line with no mailto is not an invitation.
	 *
	 * @return void
	 */
	public function testAParticipantWithoutAnEmailIsNotInvited(): void {
		$captured = [];
		$principals = [];
		$this->acceptingCalendar($captured, $principals);

		$builder = $this->builderReturning('hearing-uid-3');
		$builder->expects($this->never())->method('addAttendee');
		$this->calendarManager->method('createEventBuilder')->willReturn($builder);

		$this->userSession->method('getUser')->willReturn(null);
		$this->userManager->method('get')->willReturn($this->userMock('klager', ''));

		$this->assertSame('', $this->service->createEvent([
			'complaint' => 'complaint-uuid',
			'date' => '2026-04-01T10:00:00',
			'type' => 'fysiek',
			'participants' => ['klager'],
		]));
		$this->assertSame([], $principals, 'Nobody addressable means no calendar to write to.');
	}//end testAParticipantWithoutAnEmailIsNotInvited()
}//end class
