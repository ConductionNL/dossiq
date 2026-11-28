<?php

/**
 * A planned session opens as a meeting in the decision app.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use OCA\Decidiq\Event\MeetingRequestedEvent;
use OCA\Dossiq\Service\Session\SessionMeeting;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionMeeting
 */
class SessionMeetingTest extends TestCase {

	/**
	 * A planned session as the register stores it.
	 *
	 * @var array<string, mixed>
	 */
	private const SESSION = [
		'id'            => 'session-1',
		'title'         => 'Hearing',
		'scheduledDate' => '2026-10-20T10:00:00+00:00',
		'location'      => 'Room 2',
		'videoCallUrl'  => 'https://talk.example/abc',
	];

	/**
	 * The commands dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * A dispatcher that answers like the decision app, or not at all.
	 *
	 * @param string|null $meetingId The id the decision app answers, null when it does not handle the command.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(?string $meetingId): IEventDispatcher {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($meetingId): void {
				$this->dispatched[] = $event;
				if ($meetingId !== null) {
					$event->answer(meetingId: $meetingId, created: true);
				}
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * The command names the session as its subject and carries title, start, place and mode.
	 *
	 * @return void
	 */
	public function testTheSessionIsTheMeetingsSubject(): void {
		$meetings = new SessionMeeting(
			eventDispatcher: $this->dispatcher(meetingId: 'meeting-9'),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		self::assertSame(expected: 'meeting-9', actual: $meetings->open(session: self::SESSION, location: ['dossiq', 'hearingSession']));

		self::assertCount(expectedCount: 1, haystack: $this->dispatched);
		$event = $this->dispatched[0];
		self::assertInstanceOf(expected: MeetingRequestedEvent::class, actual: $event);
		self::assertSame(expected: ['register' => 'dossiq', 'schema' => 'hearingSession', 'id' => 'session-1'], actual: $event->getSubject());
		self::assertSame(
			expected: [
				'title'           => 'Hearing',
				'scheduledDate'   => '2026-10-20T10:00:00+00:00',
				'location'        => 'Room 2',
				'virtualLocation' => 'https://talk.example/abc',
				'meetingMode'     => 'hybrid',
			],
			actual: $event->getMeeting()
		);
	}//end testTheSessionIsTheMeetingsSubject()

	/**
	 * A session with only a video link is digital; one with only a place leaves the mode to the decision app.
	 *
	 * @return void
	 */
	public function testTheModeFollowsWhereTheSessionIsHeld(): void {
		$meetings = new SessionMeeting(
			eventDispatcher: $this->dispatcher(meetingId: 'meeting-9'),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$meetings->open(session: ['location' => ''] + self::SESSION, location: ['dossiq', 'hearingSession']);
		$meetings->open(session: ['videoCallUrl' => ''] + self::SESSION, location: ['dossiq', 'hearingSession']);

		self::assertSame(expected: 'digital', actual: $this->dispatched[0]->getMeeting()['meetingMode']);
		self::assertArrayNotHasKey(key: 'meetingMode', array: $this->dispatched[1]->getMeeting());
	}//end testTheModeFollowsWhereTheSessionIsHeld()

	/**
	 * Without the decision app, unhandled, failing or without a session id: no meeting, nothing thrown.
	 *
	 * @return void
	 */
	public function testWithoutAMeetingThePlanStands(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$absent = new SessionMeeting(
			eventDispatcher: $this->dispatcher(meetingId: 'meeting-9'),
			logger: $logger,
			eventClass: '\\OCA\\Nowhere\\MeetingRequestedEvent'
		);
		self::assertSame(expected: '', actual: $absent->open(session: self::SESSION, location: ['dossiq', 'hearingSession']));
		self::assertSame(expected: [], actual: $this->dispatched);

		$unhandled = new SessionMeeting(eventDispatcher: $this->dispatcher(meetingId: null), logger: $logger);
		self::assertSame(expected: '', actual: $unhandled->open(session: self::SESSION, location: ['dossiq', 'hearingSession']));

		$failing = $this->createMock(originalClassName: IEventDispatcher::class);
		$failing->method('dispatchTyped')->willThrowException(new RuntimeException(message: 'listener broke'));
		$broken = new SessionMeeting(eventDispatcher: $failing, logger: $logger);
		self::assertSame(expected: '', actual: $broken->open(session: self::SESSION, location: ['dossiq', 'hearingSession']));

		$noId = new SessionMeeting(eventDispatcher: $this->dispatcher(meetingId: 'meeting-9'), logger: $logger);
		self::assertSame(expected: '', actual: $noId->open(session: ['id' => ''] + self::SESSION, location: ['dossiq', 'hearingSession']));
	}//end testWithoutAMeetingThePlanStands()

}//end class
