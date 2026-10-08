<?php

/**
 * Uitdiensttreding in one act, previewed before it runs.
 *
 * The preview is where the real risk sits. A handover of two hundred cases to
 * the wrong person is worse than the database query it replaces, because the
 * query leaves a trace in somebody's terminal and this leaves two hundred cases
 * looking legitimately reassigned. So the first test asserts the preview
 * MUTATES NOTHING, which a preview built on a shared code path can stop doing
 * without anybody noticing.
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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\People\LeaverHandoverService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What one act moves, what it only names, and what it records.
 *
 * @covers \OCA\Dossiq\Service\People\LeaverHandoverService
 * @uses \OCA\Dossiq\Service\People\CaseSeats
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class LeaverHandoverTest extends TestCase {

	/**
	 * The store the seats, the drafts and the records live in.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A leaver holding two handler cases, three coordinator seats and one draft.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'roleType', uuid: 'rt-casemanager', row: ['name' => 'Casemanager', 'genericRole' => 'coordinator']);

		foreach (['case-a', 'case-b'] as $caseId) {
			$this->store->seed(schema: 'case', uuid: $caseId, row: ['title' => $caseId, 'assignee' => 'jan']);
		}

		foreach (['case-c', 'case-d', 'case-e'] as $index => $caseId) {
			$this->store->seed(schema: 'case', uuid: $caseId, row: ['title' => $caseId, 'assignee' => 'sofie']);
			$this->store->seed(
				schema: 'role',
				uuid: 'role-' . $index,
				row: ['case' => $caseId, 'roleType' => 'rt-casemanager', 'participant' => 'user:jan'],
			);
		}

		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-draft',
			row: ['title' => 'Concept zaaktype', 'isDraft' => true, '@self' => ['owner' => 'jan']],
		);
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-other',
			row: ['title' => 'Andermans concept', 'isDraft' => true, '@self' => ['owner' => 'sofie']],
		);
	}//end setUp()

	/**
	 * The preview names what will move, and moves nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function testThePreviewNamesWhatWillMoveAndMovesNothing(): void {
		$before = $this->store->writes;

		$preview = $this->service()->preview(fromUser: 'jan');

		self::assertSame(2, $preview['counts']['cases']);
		self::assertSame(3, $preview['counts']['coordinatorCases']);
		self::assertSame(1, $preview['counts']['tasks']);
		self::assertSame(1, $preview['counts']['drafts'], 'Somebody else\'s draft is not this person\'s to hand over.');
		self::assertSame(6, $preview['counts']['total']);
		self::assertSame($before, $this->store->writes, 'Nothing moves until an administrator confirms.');
	}//end testThePreviewNamesWhatWillMoveAndMovesNothing()

	/**
	 * The act moves the coordinator seats the bulk reassignment never touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function testTheCoordinatorSeatsMoveToo(): void {
		$result = $this->service()->execute(fromUser: 'jan', toUser: 'karim', actor: 'beheerder');

		self::assertSame(3, $result['coordinatorSeats'], 'Three seats the assignee walk cannot see.');

		$seats = new CaseSeats(settingsService: $this->settings(), logger: $this->createMock(originalClassName: LoggerInterface::class));
		self::assertSame(
			['case-c', 'case-d', 'case-e'],
			$seats->casesCoordinatedBy(uid: 'karim'),
		);
		self::assertSame([], $seats->casesCoordinatedBy(uid: 'jan'), 'The leaver holds nothing afterwards.');
	}//end testTheCoordinatorSeatsMoveToo()

	/**
	 * What moved is traceable afterwards, on the case itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function testWhatMovedIsReadableOnTheCase(): void {
		$result = $this->service()->execute(fromUser: 'jan', toUser: 'karim', actor: 'beheerder');

		$record = $this->store->row(schema: 'case', uuid: 'case-a')['handoverRecord'];

		self::assertSame('jan', $record['fromUser']);
		self::assertSame('karim', $record['toUser']);
		self::assertSame('beheerder', $record['actor'], 'A log line is not a record anyone reading the case will find.');
		self::assertSame(CaseSeats::HANDLER, $record['seat']);
		self::assertSame($result['batchId'], $record['batchId'], 'Everything one act touched can be read together.');

		self::assertSame(
			CaseSeats::COORDINATOR,
			$this->store->row(schema: 'case', uuid: 'case-c')['handoverRecord']['seat'],
			'A moved coordinator seat is recorded as one, not as a handler move.',
		);
	}//end testWhatMovedIsReadableOnTheCase()

	/**
	 * A draft is named rather than silently left behind.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function testTheDraftsAreNamed(): void {
		$result = $this->service()->execute(fromUser: 'jan', toUser: 'karim', actor: 'beheerder');

		self::assertSame(
			[['id' => 'ct-draft', 'title' => 'Concept zaaktype', 'schema' => 'caseType']],
			$result['drafts'],
			'A draft nobody can reach is worth naming even while re-owning it waits on OpenRegister.',
		);
	}//end testTheDraftsAreNamed()

	/**
	 * A person cannot be handed their own work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function testAPersonIsNotTheirOwnSuccessor(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->execute(fromUser: 'jan', toUser: 'jan', actor: 'beheerder');
	}//end testAPersonIsNotTheirOwnSuccessor()

	/**
	 * The service under test, over a reassignment double that reports two cases and a task.
	 *
	 * @return LeaverHandoverService The service.
	 */
	private function service(): LeaverHandoverService {
		$reassignment = $this->createMock(originalClassName: CaseReassignmentService::class);
		$reassignment->method('preview')->willReturn(
			[
				'cases' => [['id' => 'case-a', 'title' => 'case-a'], ['id' => 'case-b', 'title' => 'case-b']],
				'tasks' => [['id' => 'task-1', 'title' => 'Beoordelen']],
			]
		);
		// The cases are a bulk job now, committed by the handover because a
		// leaver handover is an act somebody already decided. What comes back
		// names the job and the cases the act was ordered over; what happened
		// to each is the job's own report
		// (bulk-actions-report-progress, D-1, D-6).
		$reassignment->method('releaseCaseload')->willReturn(
			[
				'job' => ['id' => 7, 'uuid' => 'batch-1', 'state' => 'running', 'total' => 2],
				'caseIds' => ['case-a', 'case-b'],
				'tasks' => [['id' => 'task-1', 'title' => 'Beoordelen', 'success' => true]],
			]
		);

		return new LeaverHandoverService(
			reassignment: $reassignment,
			seats: new CaseSeats(settingsService: $this->settings(), logger: $this->createMock(originalClassName: LoggerInterface::class)),
			settings: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()

	/**
	 * A settings service over the in-memory store.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'role_schema' => 'role',
					'role_type_schema' => 'roleType',
					'case_type_schema' => 'caseType',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
