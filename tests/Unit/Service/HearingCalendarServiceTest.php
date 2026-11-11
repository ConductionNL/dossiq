<?php

/**
 * HearingCalendarService Unit Tests
 *
 * Pins what writing a hearing into the calendars of its participants builds,
 * writes, skips and logs.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\HearingCalendarService;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A calendar that takes ICS and says whether it is writable.
 */
interface HearingWritableCalendar extends ICreateFromString, ICalendarIsWritable {
}//end interface

/**
 * @covers \OCA\Dossiq\Service\HearingCalendarService
 */
class HearingCalendarServiceTest extends TestCase {

	/**
	 * Every builder call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $built = [];

	/**
	 * Every log call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * Every calendar write, in order.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * A user stub.
	 *
	 * @param string $uid   The uid.
	 * @param string $email The address.
	 *
	 * @return IUser
	 */
	private function user(string $uid, string $email): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn('Name ' . $uid);
		return $user;
	}//end user()

	/**
	 * A calendar that records its writes.
	 *
	 * @param string          $label    Its label in the write log.
	 * @param bool            $deleted  Whether it is in the trash.
	 * @param bool            $writable Whether it is writable.
	 * @param \Throwable|null $refusal  What createFromString throws.
	 *
	 * @return HearingWritableCalendar
	 */
	private function calendar(string $label, bool $deleted=false, bool $writable=true, ?\Throwable $refusal=null): HearingWritableCalendar {
		$calendar = $this->createMock(HearingWritableCalendar::class);
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
	 * The service over recording stubs.
	 *
	 * @param IUser|null              $organiser The session user.
	 * @param array<string, IUser>    $users     Accounts by id.
	 * @param array<string, mixed>    $calendars Calendars by principal, or a Throwable to throw.
	 * @param string                  $ics       What the builder serialises to.
	 *
	 * @return HearingCalendarService
	 */
	private function service(?IUser $organiser, array $users, array $calendars, string $ics): HearingCalendarService {
		$builder = $this->createMock(ICalendarEventBuilder::class);
		foreach (['setSummary', 'setStartDate', 'setEndDate', 'setDescription', 'setLocation', 'setOrganizer', 'addAttendee'] as $method) {
			$builder->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method, $builder): ICalendarEventBuilder {
					$this->built[] = array_merge([$method], array_map(static fn ($arg): mixed => ($arg instanceof \DateTimeInterface) ? $arg->format('c') : $arg, $args));
					return $builder;
				}
			);
		}

		$builder->method('toIcs')->willReturn($ics);

		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('createEventBuilder')->willReturn($builder);
		$manager->method('getCalendarsForPrincipal')->willReturnCallback(
			static function (string $principal) use ($calendars): array {
				$answer = ($calendars[$principal] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($users[$uid] ?? null));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($organiser);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new HearingCalendarService($manager, $userManager, $session, $logger);
	}//end service()

	/**
	 * Without participants, or with an unreadable date, nothing is built or written.
	 *
	 * @return void
	 */
	public function testNoParticipantsOrNoDateWritesNothing(): void {
		$service = $this->service(organiser: null, users: [], calendars: [], ics: "UID:u-1\n");

		$this->assertSame('', $service->createEvent(['participants' => []]));
		$this->assertSame('', $service->createEvent(['participants' => 'alice']));
		$this->assertSame('', $service->createEvent(['date' => '2026-01-01T10:00:00+00:00']));
		$this->assertSame([], $this->logged);
		$this->assertSame('', $service->createEvent(['participants' => ['alice'], 'date' => 'not a date']));

		$this->assertSame([], $this->built);
		$this->assertSame(
			[['warning', 'Hearing has no readable date, no calendar event written: not a date', ['app' => 'dossiq']]],
			$this->logged
		);
	}//end testNoParticipantsOrNoDateWritesNothing()

	/**
	 * An event that carries no UID is not written anywhere.
	 *
	 * @return void
	 */
	public function testAnEventWithoutUidIsNotWritten(): void {
		$service = $this->service(organiser: null, users: ['alice' => $this->user('alice', 'a@x.nl')], calendars: [], ics: "BEGIN:VEVENT\n");

		$this->assertSame('', $service->createEvent(['participants' => ['alice'], 'date' => '2026-01-01T10:00:00+00:00', 'complaint' => 'K']));
		$this->assertSame([], $this->writes);
		$this->assertSame([['warning', 'Calendar event for hearing carried no UID, nothing written', ['app' => 'dossiq']]], $this->logged);
	}//end testAnEventWithoutUidIsNotWritten()

	/**
	 * The full path: one event built, written once per principal into the first calendar that takes it.
	 *
	 * @return void
	 */
	public function testTheHearingIsWrittenForTheOrganiserAndEveryAddressableParticipant(): void {
		$organiser = $this->user('org', 'org@x.nl');
		$users     = [
			'alice' => $this->user('alice', 'alice@x.nl'),
			'bob' => $this->user('bob', ''),
			'org' => $organiser,
		];
		$plain     = $this->createMock(ICalendar::class);
		$calendars = [
			'principals/users/org' => [
				$plain,
				$this->calendar(label: 'trash', deleted: true),
				$this->calendar(label: 'readonly', writable: false),
				$this->calendar(label: 'refusing', refusal: new \RuntimeException('full')),
				$this->calendar(label: 'org-main'),
				$this->calendar(label: 'org-second'),
			],
			'principals/users/alice' => new \RuntimeException('dav down'),
		];
		$service = $this->service(organiser: $organiser, users: $users, calendars: $calendars, ics: "BEGIN:VEVENT\nUID: u-1 \nEND:VEVENT");

		$uid = $service->createEvent(
			[
				'participants' => ['alice', ' ', 'Bare Name', 42, 'bob', 'carol', ' org '],
				'date' => '2026-03-02T09:30:00+01:00',
				'complaint' => 'K-7',
				'type' => 'online',
				'talkRoomUrl' => 'https://talk/x',
				'location' => 'Stadhuis',
			]
		);

		$this->assertSame('u-1', $uid);
		$this->assertSame(
			[
				['setSummary', 'Hoorgesprek klacht K-7'],
				['setStartDate', '2026-03-02T09:30:00+01:00'],
				['setEndDate', '2026-03-02T10:30:00+01:00'],
				['setDescription', "Hoorgesprek over klacht K-7\nVorm: online\nGespreksruimte: https://talk/x\nDeelnemers: alice, Bare Name, bob, carol, org"],
				['setLocation', 'Stadhuis'],
				['setOrganizer', 'org@x.nl', 'Name org'],
				['addAttendee', 'alice@x.nl', 'Name alice'],
				['addAttendee', 'org@x.nl', 'Name org'],
			],
			$this->built
		);
		$this->assertSame(
			["refusing:u-1.ics:BEGIN:VEVENT\nUID: u-1 \nEND:VEVENT", "org-main:u-1.ics:BEGIN:VEVENT\nUID: u-1 \nEND:VEVENT"],
			$this->writes
		);
		$this->assertSame(
			[
				['warning', 'Calendar refused the hearing event for principals/users/org: full', ['app' => 'dossiq']],
				['warning', 'Could not read calendars for principals/users/alice: dav down', ['app' => 'dossiq']],
				['info', 'Hearing written to 1 calendars for 7 participants', ['app' => 'dossiq', 'calendarEventId' => 'u-1']],
			],
			$this->logged
		);
	}//end testTheHearingIsWrittenForTheOrganiserAndEveryAddressableParticipant()

	/**
	 * Without an organiser, location, type, room or names the event is bare, and no calendar taking it is reported.
	 *
	 * @return void
	 */
	public function testABareHearingWithNoWritableCalendarIsReported(): void {
		$service = $this->service(
			organiser: null,
			users: ['alice' => $this->user('alice', 'alice@x.nl')],
			calendars: ['principals/users/alice' => [$this->calendar(label: 'ro', writable: false)]],
			ics: "UID:u-2\n"
		);

		$this->assertSame('', $service->createEvent(['participants' => [7, 'alice'], 'date' => '2026-03-02T09:30:00+00:00']));
		$this->assertSame(
			[
				['setSummary', 'Hoorgesprek klacht '],
				['setStartDate', '2026-03-02T09:30:00+00:00'],
				['setEndDate', '2026-03-02T10:30:00+00:00'],
				['setDescription', "Hoorgesprek over klacht \nDeelnemers: alice"],
				['addAttendee', 'alice@x.nl', 'Name alice'],
			],
			$this->built
		);
		$this->assertSame([], $this->writes);
		$this->assertSame([['warning', 'No writable calendar found for the hearing, event not stored', ['app' => 'dossiq']]], $this->logged);
	}//end testABareHearingWithNoWritableCalendarIsReported()
}//end class
