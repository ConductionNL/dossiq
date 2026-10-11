<?php

/**
 * A case holds the sessions its case type declares.
 *
 * Neutral fixture case type `fixture-session` declares two sessions: a
 * `hearing` with an inspection floor of seven days, invitee roles and a
 * waiver, and a `site-visit` with no minutes requirement and its own status
 * names. The store is a real in-memory register, and every written session is
 * validated against the merged register's real `hearingSession` schema, so a
 * field the schema does not declare or a value it refuses fails here.
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\Session\SessionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionService
 * @uses   \OCA\Dossiq\Service\Session\SessionDefinition
 * @uses   \OCA\Dossiq\Service\Session\SessionRules
 */
class SessionServiceTest extends TestCase {

	/**
	 * The chair, as a role uuid.
	 *
	 * @var string
	 */
	private const CHAIR = '6f0c1d52-7b1a-4e7e-9a59-3c2f2f0b9a11';

	/**
	 * The case.
	 *
	 * @var string
	 */
	private const CASE_ID = '0b7c6a0e-3d0b-4c39-8a0b-6d4f1c2e9b01';

	/**
	 * The fixture sessions, as a case type stores them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const SESSIONS = [
		[
			'key'                 => 'hearing',
			'label'               => 'Hearing',
			'inviteeRoles'        => ['applicant', 'third-party'],
			'inspectionFloorDays' => 7,
			'waivable'            => true,
		],
		[
			'key'                      => 'site-visit',
			'label'                    => 'Site visit',
			'minInvitees'              => 0,
			'minutesRequired'          => false,
			'recordingConsentRequired' => false,
			'attendanceGraceHours'     => 0,
			'statuses'                 => ['planned' => 'uitgenodigd'],
		],
	];

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The real schemas.
	 *
	 * @var RealSchemaValidator
	 */
	private static RealSchemaValidator $schemas;

	/**
	 * The current moment.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * The service under test.
	 *
	 * @var SessionService
	 */
	private SessionService $service;

	/**
	 * Load the merged register once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$schemas = new RealSchemaValidator();
	}//end setUpBeforeClass()

	/**
	 * Seed the fixture case type and one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'fixture-session', 'sessions' => self::SESSIONS]);
		$this->store->seed(schema: 'caseType', uuid: 'ct-bare', row: ['title' => 'fixture-bare']);
		$this->store->seed(schema: 'case', uuid: self::CASE_ID, row: ['title' => 'Case', 'caseType' => 'ct-1']);
		$this->store->seed(schema: 'case', uuid: 'case-bare', row: ['title' => 'Bare', 'caseType' => ['id' => 'ct-bare']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register'               => 'dossiq',
				'case_schema'            => 'case',
				'case_type_schema'       => 'caseType',
				'hearing_session_schema' => 'hearingSession',
				default                  => '',
			}
		);

		$this->now = new DateTimeImmutable('2026-10-11T09:00:00+00:00');
		$clock     = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturnCallback(fn (): DateTimeImmutable => $this->now);

		$this->service = new SessionService(settingsService: $settings, timeFactory: $clock);
	}//end setUp()

	/**
	 * A planned hearing carries its key, its title and the inspection dates, and fits the real schema.
	 *
	 * @return void
	 */
	public function testAPlannedSessionCarriesItsKeyAndFitsTheSchema(): void {
		$saved = $this->service->schedule(
			caseId: self::CASE_ID,
			sessionKey: 'hearing',
			scheduledDate: '2026-10-20T10:00:00+00:00',
			chairpersonId: self::CHAIR,
			invitees: [['role' => 'applicant', 'channel' => 'email']],
			payload: ['location' => 'Room 2']
		);

		$stored = $this->store->row(schema: 'hearingSession', uuid: $saved['id']);
		self::assertSame('hearing', $stored['sessionKey']);
		self::assertSame('Hearing', $stored['title']);
		self::assertSame('planned', $stored['status']);
		self::assertSame('2026-10-13', $stored['inspectionDeadline']);
		self::assertSame('2026-10-11', $stored['inspectionAvailableFrom']);
		self::assertSame('Room 2', $stored['location']);
		self::assertSame($this->now->format(DATE_ATOM), $stored['invitees'][0]['invitedAt']);
		self::assertSame([], self::$schemas->errors(slug: 'hearingSession', payload: $stored));
	}//end testAPlannedSessionCarriesItsKeyAndFitsTheSchema()

	/**
	 * A session planned inside the floor, or with a role not declared, is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testABrokenPlanIsRefusedAndNothingIsStored(): void {
		foreach ([
			['2026-10-15T10:00:00+00:00', [['role' => 'applicant']], 'at least 7 days'],
			['2026-10-20T10:00:00+00:00', [['role' => 'neighbour']], 'invites only these roles'],
			['2026-10-20T10:00:00+00:00', [], 'at least 1 invitee'],
		] as [$date, $invitees, $message]) {
			try {
				$this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: $date, chairpersonId: self::CHAIR, invitees: $invitees);
				self::fail("Accepted: $message");
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString($message, $e->getMessage());
			}
		}

		self::assertSame([], $this->store->all(schema: 'hearingSession'));
	}//end testABrokenPlanIsRefusedAndNothingIsStored()

	/**
	 * A session the case type does not declare is refused.
	 *
	 * @return void
	 */
	public function testAnUndeclaredSessionIsRefused(): void {
		$this->expectExceptionMessage("declares no session 'zitting'");
		$this->service->schedule(caseId: self::CASE_ID, sessionKey: 'zitting', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']]);
	}//end testAnUndeclaredSessionIsRefused()

	/**
	 * The case type's own status names are written.
	 *
	 * @return void
	 */
	public function testTheCaseTypesStatusNamesAreWritten(): void {
		$saved = $this->service->schedule(caseId: self::CASE_ID, sessionKey: 'site-visit', scheduledDate: '2026-10-11T09:30:00+00:00', chairpersonId: self::CHAIR, invitees: []);

		self::assertSame('uitgenodigd', $saved['status']);
		self::assertSame([], self::$schemas->errors(slug: 'hearingSession', payload: $saved));
	}//end testTheCaseTypesStatusNamesAreWritten()

	/**
	 * A waivable session is waived with a reason; a session not declared waivable is refused.
	 *
	 * @return void
	 */
	public function testOnlyAWaivableSessionIsWaived(): void {
		$saved = $this->service->waive(caseId: self::CASE_ID, sessionKey: 'hearing', chairpersonId: self::CHAIR, reason: 'Applicant does not want to be heard');

		self::assertTrue($saved['hearingWaived']);
		self::assertSame('afgezien', $saved['status']);
		self::assertSame('hearing', $saved['sessionKey']);
		self::assertSame([], self::$schemas->errors(slug: 'hearingSession', payload: $saved));

		$this->expectExceptionMessage('Site visit cannot be waived.');
		$this->service->waive(caseId: self::CASE_ID, sessionKey: 'site-visit', chairpersonId: self::CHAIR, reason: 'No');
	}//end testOnlyAWaivableSessionIsWaived()

	/**
	 * Inside the grace window attendance is added; after it, an entry without a reason changes nothing.
	 *
	 * @return void
	 */
	public function testAttendanceClosesAfterTheGraceWindow(): void {
		$saved = $this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']]);
		$id    = $saved['id'];

		$this->now = new DateTimeImmutable('2026-10-20T10:59:00+00:00');
		$updated   = $this->service->recordAttendance(sessionId: $id, entries: [['invitee' => 'applicant', 'present' => true]]);
		self::assertCount(1, $updated['attendance']);
		self::assertSame('2026-10-20T11:00:00+00:00', $updated['attendanceFrozenAt']);

		$this->now = new DateTimeImmutable('2026-10-20T11:01:00+00:00');
		try {
			$this->service->recordAttendance(sessionId: $id, entries: [['invitee' => 'third-party', 'present' => false]]);
			self::fail('A late entry without a reason was written.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('correction reason', $e->getMessage());
		}

		self::assertCount(1, $this->store->row(schema: 'hearingSession', uuid: $id)['attendance']);

		$this->service->recordAttendance(sessionId: $id, entries: [['invitee' => 'third-party', 'present' => false, 'correctionReason' => 'Left early']]);
		$stored = $this->store->row(schema: 'hearingSession', uuid: $id);
		self::assertCount(2, $stored['attendance']);
		self::assertSame([], self::$schemas->errors(slug: 'hearingSession', payload: $stored));
	}//end testAttendanceClosesAfterTheGraceWindow()

	/**
	 * A session that requires minutes is not closed without them; a recording needs consent.
	 *
	 * @return void
	 */
	public function testMinutesAndRecordingConsentFollowTheDefinition(): void {
		$id = $this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']])['id'];

		foreach ([
			[[], 'needs minutes'],
			[['minutesSummary' => 'Heard', 'audioRecording' => 'a1f0c1d5-7b1a-4e7e-9a59-3c2f2f0b9a11'], 'recording consent'],
		] as [$payload, $message]) {
			try {
				$this->service->addMinutes(sessionId: $id, payload: $payload);
				self::fail("Accepted: $message");
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString($message, $e->getMessage());
			}
		}

		self::assertSame('planned', $this->store->row(schema: 'hearingSession', uuid: $id)['status']);

		$closed = $this->service->addMinutes(sessionId: $id, payload: ['minutesSummary' => 'Applicant was heard']);
		self::assertSame('executed', $closed['status']);
		self::assertSame('Applicant was heard', $closed['minutesSummary']);
		self::assertSame([], self::$schemas->errors(slug: 'hearingSession', payload: $this->store->row(schema: 'hearingSession', uuid: $id)));
	}//end testMinutesAndRecordingConsentFollowTheDefinition()

	/**
	 * A session that does not require minutes or consent closes without them.
	 *
	 * @return void
	 */
	public function testASessionWithoutAMinutesRequirementClosesBare(): void {
		$id = $this->service->schedule(caseId: self::CASE_ID, sessionKey: 'site-visit', scheduledDate: '2026-10-12T09:00:00+00:00', chairpersonId: self::CHAIR, invitees: [])['id'];

		$closed = $this->service->addMinutes(sessionId: $id, payload: ['audioRecording' => 'a1f0c1d5-7b1a-4e7e-9a59-3c2f2f0b9a11']);

		self::assertSame('executed', $closed['status']);
		self::assertNull($closed['minutesSummary']);
	}//end testASessionWithoutAMinutesRequirementClosesBare()

	/**
	 * A session stored without a key takes the case type's only session, and is refused when there are several.
	 *
	 * @return void
	 */
	public function testASessionWithoutAKeyNeedsExactlyOneDeclaredSession(): void {
		$this->store->seed(schema: 'hearingSession', uuid: 'legacy', row: ['case' => self::CASE_ID, 'scheduledDate' => '2026-10-20T10:00:00+00:00']);

		$this->expectExceptionMessage('does not declare exactly one');
		$this->service->addMinutes(sessionId: 'legacy', payload: ['minutesSummary' => 'Heard']);
	}//end testASessionWithoutAKeyNeedsExactlyOneDeclaredSession()

	/**
	 * The listing names the declared sessions and the sessions the case holds.
	 *
	 * @return void
	 */
	public function testTheListingNamesDefinitionsAndSessions(): void {
		$this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']]);

		$listing = $this->service->sessionsFor(caseId: self::CASE_ID);
		self::assertSame(['hearing', 'site-visit'], array_column($listing['definitions'], 'key'));
		self::assertCount(1, $listing['sessions']);

		self::assertSame([], $this->service->sessionsFor(caseId: 'case-bare')['definitions']);
	}//end testTheListingNamesDefinitionsAndSessions()

	/**
	 * The session schema shows sessions in Calendar on the planned start.
	 *
	 * @return void
	 */
	public function testTheSessionSchemaIsACalendar(): void {
		$calendar = (self::$schemas->schemas['hearingSession']['configuration']['calendarProvider'] ?? []);

		self::assertTrue($calendar['enabled']);
		self::assertSame('scheduledDate', $calendar['dtstart']);
		self::assertArrayHasKey('title', self::$schemas->schemas['hearingSession']['properties']);
		self::assertArrayHasKey('sessionKey', self::$schemas->schemas['hearingSession']['properties']);
	}//end testTheSessionSchemaIsACalendar()
}//end class
