<?php

/**
 * The refusals and failures of the session service: what it refuses before
 * it reads anything, what it says when the register refuses a write, and how
 * a session stored without a key finds its definition.
 *
 * Same fixture as SessionServiceTest: case type `fixture-session` with a
 * `hearing` and a `site-visit`, plus `fixture-one` declaring one session.
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
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionService
 * @covers \OCA\Dossiq\Service\Support\OpensInTheInitialState
 * @uses   \OCA\Dossiq\Service\Session\SessionDefinition
 * @uses   \OCA\Dossiq\Service\Session\SessionDefinitionReader
 * @uses   \OCA\Dossiq\Service\Session\SessionRules
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Support\CaseRegisterReads
 */
class SessionServiceRefusalsTest extends TestCase {

	use MakesCaseDateNormaliser;

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
	 * The register, judging the session's lifecycle as OpenRegister does.
	 *
	 * @var LifecycleRegister
	 */
	private LifecycleRegister $store;

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
		$this->store = new LifecycleRegister();
		// Recording minutes moves a planned session to executed, which the
		// schema's transitions do not declare; the tests that write a status
		// on create judge the move themselves.
		$this->store->judgesMoves = false;
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'fixture-session', 'sessions' => self::SESSIONS]);
		$this->store->seed(schema: 'caseType', uuid: 'ct-bare', row: ['title' => 'fixture-bare']);
		$this->store->seed(schema: 'case', uuid: self::CASE_ID, row: ['title' => 'Case', 'caseType' => 'ct-1']);
		$this->store->seed(schema: 'case', uuid: 'case-bare', row: ['title' => 'Bare', 'caseType' => ['id' => 'ct-bare']]);
		$this->store->seed(schema: 'caseType', uuid: 'ct-one', row: ['title' => 'fixture-one', 'sessions' => [['key' => 'talk', 'label' => 'Talk', 'minInvitees' => 0]]]);
		$this->store->seed(schema: 'case', uuid: 'case-one', row: ['title' => 'One', 'caseType' => 'ct-one']);

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

		$this->service = new SessionService(settingsService: $settings, timeFactory: $clock, caseDates: $this->caseDates(zone: 'UTC', clock: $clock));
	}//end setUp()

	/**
	 * Planning without a case or a chair is refused before anything is read or stored.
	 *
	 * @return void
	 */
	public function testPlanningNeedsACaseAndAChair(): void {
		foreach ([['', self::CHAIR], [self::CASE_ID, '  ']] as [$caseId, $chair]) {
			try {
				$this->service->schedule(caseId: $caseId, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: $chair, invitees: [['role' => 'applicant']]);
				self::fail('Accepted a plan without a case or a chair');
			} catch (InvalidArgumentException $e) {
				self::assertSame('Name the case and who chairs the session.', $e->getMessage());
			}
		}

		self::assertSame([], $this->store->all(schema: 'hearingSession'));
		self::assertSame(0, $this->store->writes);
	}//end testPlanningNeedsACaseAndAChair()

	/**
	 * A waiver needs a reason first, then a case and who records it.
	 *
	 * @return void
	 */
	public function testAWaiverNeedsAReasonACaseAndARecorder(): void {
		foreach ([
			[self::CASE_ID, self::CHAIR, '   ', 'Say why the session is waived.'],
			['', self::CHAIR, 'No hearing wanted', 'Name the case and who records the waiver.'],
			[self::CASE_ID, '', 'No hearing wanted', 'Name the case and who records the waiver.'],
		] as [$caseId, $chair, $reason, $sentence]) {
			try {
				$this->service->waive(caseId: $caseId, sessionKey: 'hearing', chairpersonId: $chair, reason: $reason);
				self::fail("Accepted: $sentence");
			} catch (InvalidArgumentException $e) {
				self::assertSame($sentence, $e->getMessage());
			}
		}

		self::assertSame([], $this->store->all(schema: 'hearingSession'));
		self::assertSame(0, $this->store->writes);
	}//end testAWaiverNeedsAReasonACaseAndARecorder()

	/**
	 * Attendance with no entries is refused before the session is read.
	 *
	 * @return void
	 */
	public function testAttendanceNeedsAnEntry(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Give at least one attendance entry.');

		$this->service->recordAttendance(sessionId: 'does-not-matter', entries: []);
	}//end testAttendanceNeedsAnEntry()

	/**
	 * A session that is not in the register is reported as not found.
	 *
	 * @return void
	 */
	public function testAMissingSessionIsNotFound(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Session not found');

		$this->service->addMinutes(sessionId: 'no-such-session', payload: ['minutesSummary' => 'Heard']);
	}//end testAMissingSessionIsNotFound()

	/**
	 * A session stored without a key takes the case type's only declared session.
	 *
	 * @return void
	 */
	public function testASessionWithoutAKeyTakesTheOnlyDeclaredSession(): void {
		$this->store->seed(schema: 'hearingSession', uuid: 'legacy-one', row: ['case' => 'case-one', 'scheduledDate' => '2026-10-20T10:00:00+00:00', 'status' => 'planned']);

		$closed = $this->service->addMinutes(sessionId: 'legacy-one', payload: ['minutesSummary' => 'Talked it through']);

		self::assertSame('executed', $closed['status']);
		self::assertSame('Talked it through', $this->store->row(schema: 'hearingSession', uuid: 'legacy-one')['minutesSummary']);
	}//end testASessionWithoutAKeyTakesTheOnlyDeclaredSession()

	/**
	 * Minutes whose summary is not text count as no minutes.
	 *
	 * @return void
	 */
	public function testASummaryThatIsNotTextCountsAsNoMinutes(): void {
		$id = $this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']])['id'];

		try {
			$this->service->addMinutes(sessionId: $id, payload: ['minutesSummary' => ['Heard']]);
			self::fail('Accepted a summary that is not text');
		} catch (InvalidArgumentException $e) {
			self::assertSame('Hearing needs minutes: a summary or a document.', $e->getMessage());
		}

		self::assertSame('planned', $this->store->row(schema: 'hearingSession', uuid: $id)['status']);
	}//end testASummaryThatIsNotTextCountsAsNoMinutes()

	/**
	 * A register that refuses the first write is reported with its own words.
	 *
	 * @return void
	 */
	public function testARefusedSaveSaysTheSessionWasNotSaved(): void {
		$this->store->refuseSaves = true;

		try {
			$this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']]);
			self::fail('A refused save was reported as saved');
		} catch (RuntimeException $e) {
			self::assertSame('The session could not be saved: object store refused the save', $e->getMessage());
			self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
		}
	}//end testARefusedSaveSaysTheSessionWasNotSaved()

	/**
	 * A register that refuses an update is reported with its own words, and the session keeps its status.
	 *
	 * @return void
	 */
	public function testARefusedUpdateSaysTheSessionWasNotUpdated(): void {
		$id = $this->service->schedule(caseId: self::CASE_ID, sessionKey: 'hearing', scheduledDate: '2026-10-20T10:00:00+00:00', chairpersonId: self::CHAIR, invitees: [['role' => 'applicant']])['id'];
		$this->store->refuseSaves = true;

		try {
			$this->service->addMinutes(sessionId: $id, payload: ['minutesSummary' => 'Heard']);
			self::fail('A refused update was reported as saved');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('The session could not be updated: ', $e->getMessage());
		}

		self::assertSame('planned', $this->store->row(schema: 'hearingSession', uuid: $id)['status']);
	}//end testARefusedUpdateSaysTheSessionWasNotUpdated()
}//end class
