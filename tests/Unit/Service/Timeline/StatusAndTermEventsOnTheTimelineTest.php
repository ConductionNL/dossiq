<?php

/**
 * The status move and the term event on the case timeline.
 *
 * Two writers landed after the seven kinds were declared, and both of them
 * write through the one seam. What these tests hold onto is the pair the other
 * writers' suites hold onto: the entry is written with the kind and the fields
 * its declaration names, and a write the seam refuses does not take the act
 * down with it.
 *
 * WHAT THEY CANNOT SEE, DELIBERATELY. Whether the kinds are declared on the
 * instance, and whether `fields` survives the round trip, are questions about a
 * running OpenRegister; a double answers whatever it is told. Those live in
 * `tests/e2e/one-timeline-on-the-case.spec.ts`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Typed stub for the OpenRegister ObjectService as CaseStatusStore calls it.
 *
 * `saveObject()` and `find()` are both called with NAMED arguments, which a
 * magic mock refuses, so the mock is generated from this signature instead.
 */
interface StatusStoreObjectServiceStub {
	/**
	 * Save or update an object.
	 *
	 * @param array<string, mixed> $object   Object data.
	 * @param string               $register Register slug.
	 * @param string               $schema   Schema slug.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, string $register, string $schema): array;

	/**
	 * Read one object.
	 *
	 * @param string $id       The object id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function find(string $id, mixed $register = null, mixed $schema = null): array;
}//end interface

/**
 * Unit tests for the status-change and term-event timeline writers.
 *
 * @covers \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @covers \OCA\Dossiq\Service\TermijnService
 *
 * @uses \OCA\Dossiq\Service\Timeline\TimelineKinds
 * @uses \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\TermKind
 */
class StatusAndTermEventsOnTheTimelineTest extends TestCase {

	/**
	 * The mocked timeline seam.
	 *
	 * @var CaseTimeline|MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * What the seam was last handed, as named keys.
	 *
	 * @var array<string, mixed>
	 */
	private array $seen = [];

	/**
	 * How many entries the seam was asked to write.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Arm the seam so every call is captured.
	 *
	 * @param string $answer What `record()` answers, '' for a refused write.
	 *
	 * @return void
	 */
	private function armTimeline(string $answer = 'entry-1'): void {
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			) use ($answer): string {
				$this->seen = compact('caseId', 'kind', 'message', 'fields', 'visibility', 'relatedCaseIds');
				$this->writes++;

				return $answer;
			}
		);
	}//end armTimeline()

	/**
	 * Reset the capture before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seen = [];
		$this->writes = 0;
		$this->armTimeline();
	}//end setUp()

	/**
	 * A status move puts a line on the case's timeline, carrying both
	 * statuses, who moved it, what they said and the record it wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAStatusMoveReachesTheTimeline(): void {
		$store = $this->statusStore(actor: 'handler');

		$store->writeStatusRecord(
			caseId: 'case-1',
			toStatus: 'st-behandeling',
			fromStatus: 'st-intake',
			label: 'Start behandeling',
			comment: 'Stukken compleet',
			evaluatedGuards: [],
			noWorkflowTemplate: false,
		);

		self::assertSame(1, $this->writes);
		self::assertSame('case-1', $this->seen['caseId']);
		self::assertSame('statuswijziging', $this->seen['kind']);
		self::assertSame('st-intake', $this->seen['fields']['from']);
		self::assertSame('st-behandeling', $this->seen['fields']['to']);
		self::assertSame('handler', $this->seen['fields']['actor']);
		self::assertSame('Stukken compleet', $this->seen['fields']['explanation']);
		self::assertSame('Start behandeling', $this->seen['fields']['label']);
		self::assertSame('record-1', $this->seen['fields']['statusRecordId']);
		self::assertSame('internal', $this->seen['visibility']);
	}//end testAStatusMoveReachesTheTimeline()

	/**
	 * The actor the caller names wins over the signed-in session.
	 *
	 * `writeStatusRecord()` takes an actor because the four-eyes rule needs to
	 * know who TOOK a step, which is not the same claim as who wrote the row.
	 * A bulk action running as one user on behalf of another would otherwise
	 * put the wrong name on every line it writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testTheActorTheCallerNamesWinsOverTheSession(): void {
		$store = $this->statusStore(actor: 'session-user');

		$store->writeStatusRecord(
			caseId: 'case-1',
			toStatus: 'st-behandeling',
			fromStatus: 'st-intake',
			label: 'Start behandeling',
			comment: null,
			evaluatedGuards: [],
			noWorkflowTemplate: false,
			actor: 'named-mover',
		);

		self::assertSame('named-mover', $this->seen['fields']['actor']);
	}//end testTheActorTheCallerNamesWinsOverTheSession()

	/**
	 * The sentence a handler reads names the two statuses, not their uuids.
	 *
	 * A timeline line reading "Status gewijzigd van st-intake naar
	 * st-behandeling" is a line nobody can use, and the ids are already in
	 * `fields` for anything that filters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testTheStatusSentenceCarriesNamesRatherThanIds(): void {
		$store = $this->statusStore(actor: 'handler');

		$store->writeStatusRecord(
			caseId: 'case-1',
			toStatus: 'st-behandeling',
			fromStatus: 'st-intake',
			label: 'Start behandeling',
			comment: null,
			evaluatedGuards: [],
			noWorkflowTemplate: false,
		);

		self::assertSame('Status gewijzigd van Intake naar Behandeling', $this->seen['message']);
		self::assertStringNotContainsString('st-intake', $this->seen['message']);
	}//end testTheStatusSentenceCarriesNamesRatherThanIds()

	/**
	 * A timeline the seam refuses does not take the status move with it.
	 *
	 * The case has already moved by the time the entry is attempted. Refusing
	 * the move because the log could not be written would trade a missing line
	 * for a lost transition, so the record still comes back to its caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testARefusedTimelineWriteStillReturnsTheStatusRecord(): void {
		$this->armTimeline(answer: '');
		$store = $this->statusStore(actor: 'handler');

		$record = $store->writeStatusRecord(
			caseId: 'case-1',
			toStatus: 'st-behandeling',
			fromStatus: 'st-intake',
			label: 'Start behandeling',
			comment: null,
			evaluatedGuards: [],
			noWorkflowTemplate: false,
		);

		self::assertSame(1, $this->writes);
		self::assertSame('record-1', $record['id']);
		self::assertSame('st-behandeling', $record['statusType']);
	}//end testARefusedTimelineWriteStillReturnsTheStatusRecord()

	/**
	 * A term event puts a line on the case of the instance it hangs on,
	 * carrying the event, the moment and the dates the term now holds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testATermEventReachesTheTimeline(): void {
		$service = $this->termService();

		$service->recordEvent(
			termInstanceId: 'ti-1',
			type: 'pause',
			basis: 'AWB 4:5',
			rationale: 'Aanvulling gevraagd; termijn opgeschort',
			daysImpact: 14,
		);

		self::assertSame(1, $this->writes);
		self::assertSame('case-1', $this->seen['caseId']);
		self::assertSame('termijngebeurtenis', $this->seen['kind']);
		self::assertSame('Aanvulling gevraagd; termijn opgeschort', $this->seen['message']);
		self::assertSame('pause', $this->seen['fields']['event']);
		self::assertSame(TermKind::STATUTORY, $this->seen['fields']['term']);
		self::assertSame('2026-10-01', $this->seen['fields']['dueAt']);
		self::assertSame('2026-09-01T00:00:00+00:00', $this->seen['fields']['startedAt']);
		self::assertSame('AWB 4:5', $this->seen['fields']['basis']);
		self::assertSame('ti-1', $this->seen['fields']['termijnId']);
		self::assertNotSame('', (string)$this->seen['fields']['occurredAt']);
		self::assertSame('internal', $this->seen['visibility']);
	}//end testATermEventReachesTheTimeline()

	/**
	 * A timeline the seam refuses does not take the term event with it.
	 *
	 * The TermijnGebeurtenis is the legal record and it is already written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testARefusedTimelineWriteStillReturnsTheTermEvent(): void {
		$this->armTimeline(answer: '');
		$service = $this->termService();

		$event = $service->recordEvent(
			termInstanceId: 'ti-1',
			type: 'hervat',
			basis: 'AWB 4:15',
			rationale: 'Aanvulling ontvangen; termijn hervat',
			daysImpact: 0,
		);

		self::assertSame(1, $this->writes);
		self::assertNotNull($event);
		self::assertSame('hervat', $event['type']);
		self::assertSame('ti-1', $event['deadlineInstance']);
	}//end testARefusedTimelineWriteStillReturnsTheTermEvent()

	/**
	 * A phase term writes no TermijnGebeurtenis, so its start is recorded
	 * where the instance itself is written.
	 *
	 * A case carries up to four clocks and only the statutory one funnels
	 * through the event register. A handler whose week is governed by the
	 * phase clock would otherwise read a timeline that never mentions it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAPhaseTermStartReachesTheTimeline(): void {
		$service = $this->termService();

		$service->saveTermInstance(
			instance: [
				'case' => 'case-1',
				'kind' => TermKind::PHASE,
				'startDate' => '2026-09-10T00:00:00+00:00',
				'endDateCalculated' => '2026-09-24',
				'endDateCurrent' => '2026-09-24',
				'status' => 'lopend',
			]
		);

		self::assertSame(1, $this->writes);
		self::assertSame('case-1', $this->seen['caseId']);
		self::assertSame('termijngebeurtenis', $this->seen['kind']);
		self::assertSame('Fasetermijn gestart, uiterlijk 2026-09-24', $this->seen['message']);
		self::assertSame('start', $this->seen['fields']['event']);
		self::assertSame(TermKind::PHASE, $this->seen['fields']['term']);
		self::assertSame('2026-09-24', $this->seen['fields']['dueAt']);
	}//end testAPhaseTermStartReachesTheTimeline()

	/**
	 * Re-binding an existing term instance is not a second start.
	 *
	 * `bindStatutory()` rewrites a term that is already running when the case
	 * type's fixed end date moves. A timeline that announced a start each time
	 * would report clocks that never started.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testRewritingAnExistingTermAnnouncesNoStart(): void {
		$service = $this->termService();

		$service->saveTermInstance(
			instance: [
				'id' => 'ti-1',
				'case' => 'case-1',
				'kind' => TermKind::STATUTORY,
				'endDateCurrent' => '2026-11-01',
			]
		);

		self::assertSame(0, $this->writes);
	}//end testRewritingAnExistingTermAnnouncesNoStart()

	/**
	 * A term event on an instance whose case cannot be read writes no entry
	 * rather than an entry with no case on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testATermEventWithoutAReadableCaseWritesNoEntry(): void {
		$service = $this->termService();

		$service->recordEvent(
			termInstanceId: 'ti-unknown',
			type: 'exceeded',
			basis: 'AWB 4:13',
			rationale: 'Termijn overschreden zonder beschikking',
			daysImpact: 0,
		);

		self::assertSame(0, $this->writes);
	}//end testATermEventWithoutAReadableCaseWritesNoEntry()

	/**
	 * A CaseStatusStore over two named statuses and a capturing writer.
	 *
	 * @param string $actor The signed-in user id, '' for nobody.
	 *
	 * @return CaseStatusStore The store under test.
	 */
	private function statusStore(string $actor): CaseStatusStore {
		$objectService = $this->createMock(StatusStoreObjectServiceStub::class);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object, string $register, string $schema): array {
				return array_merge($object, ['id' => 'record-1']);
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function (string $id, mixed $register = null, mixed $schema = null): array {
				return ([
					'st-intake' => ['id' => 'st-intake', 'name' => 'Intake'],
					'st-behandeling' => ['id' => 'st-behandeling', 'name' => 'Behandeling'],
				][$id] ?? []);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'status_record_schema' => 'statusRecord',
				'status_type_schema' => 'statusType',
				'case_type_schema' => 'caseType',
			][$key] ?? '')
		);

		$userSession = $this->createMock(IUserSession::class);
		if ($actor !== '') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($actor);
			$userSession->method('getUser')->willReturn($user);
		}

		return new CaseStatusStore(
			$settings,
			new StatusTypeLookup($settings, new CaseTypeResolver(new CaseTypeStore($settings))),
			new NullLogger(),
			$this->timeline,
			$userSession,
		);
	}//end statusStore()

	/**
	 * A TermijnService over one seeded, running statutory term.
	 *
	 * @return TermijnService The service under test.
	 */
	private function termService(): TermijnService {
		$objects = new FakeTermijnStore();
		$objects->seed(
			'deadlineInstance',
			[
				'id' => 'ti-1',
				'case' => 'case-1',
				'kind' => TermKind::STATUTORY,
				'startDate' => '2026-09-01T00:00:00+00:00',
				'endDateCalculated' => '2026-10-01',
				'endDateCurrent' => '2026-10-01',
				'status' => 'lopend',
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
			][$key] ?? '')
		);

		return new TermijnService($settings, new NullLogger(), null, $this->timeline);
	}//end termService()
}//end class
