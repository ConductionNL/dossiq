<?php

/**
 * Which events are work on a case, and which saves are not.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseLastActivityListener;
use OCA\Dossiq\Service\Activity\CaseActivityStamp;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use OCP\Comments\IComment;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the case activity listener.
 *
 * @covers \OCA\Dossiq\Listener\CaseLastActivityListener
 */
class CaseLastActivityListenerTest extends TestCase {

	/**
	 * The case ids the stamp was asked to mark.
	 *
	 * @var array<int, string>
	 */
	private array $stamped = [];

	/**
	 * The listener under test.
	 *
	 * @var CaseLastActivityListener
	 */
	private CaseLastActivityListener $listener;

	/**
	 * Build the listener over a recording stamp and a resolver that answers the payload's slug.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stamped = [];

		$stamp = $this->createMock(originalClassName: CaseActivityStamp::class);
		$stamp->method('stamp')->willReturnCallback(
			function (string $caseId): bool {
				$this->stamped[] = $caseId;
				return true;
			}
		);

		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		$this->listener = new CaseLastActivityListener(stamp: $stamp, slugResolver: $resolver);
	}//end setUp()

	/**
	 * A status move is work on the case.
	 *
	 * @return void
	 */
	public function testAStatusMoveStampsTheCase(): void {
		$this->listener->handle($this->updated(before: ['status' => 'st-1'], after: ['status' => 'st-2']));

		self::assertSame(expected: ['case-1'], actual: $this->stamped);
	}//end testAStatusMoveStampsTheCase()

	/**
	 * A save that leaves the status (rematerialisation, refresh, the stamp itself) is not.
	 *
	 * @return void
	 */
	public function testASaveThatKeepsTheStatusIsNotWork(): void {
		$this->listener->handle($this->updated(before: ['status' => 'st-1', 'title' => 'a'], after: ['status' => 'st-1', 'title' => 'b']));
		$this->listener->handle(new ObjectUpdatedEvent($this->entity(record: ['status' => 'st-2'], schema: 'case')));

		self::assertSame(expected: [], actual: $this->stamped);
	}//end testASaveThatKeepsTheStatusIsNotWork()

	/**
	 * A new case is stamped unless it arrives with its own date.
	 *
	 * @return void
	 */
	public function testANewCaseIsStampedUnlessItCarriesADate(): void {
		$this->listener->handle(new ObjectCreatedEvent($this->entity(record: [], schema: 'case')));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(record: ['lastActivityAt' => '2026-10-14T09:00:00+00:00'], schema: 'case')));

		self::assertSame(expected: ['case-1'], actual: $this->stamped);
	}//end testANewCaseIsStampedUnlessItCarriesADate()

	/**
	 * A document or a contact created on a case is work on that case; other records are not.
	 *
	 * @return void
	 */
	public function testAnActivityRecordStampsItsCase(): void {
		foreach (['caseDocument', 'customerContact', 'contactmoment'] as $schema) {
			$this->listener->handle(new ObjectCreatedEvent($this->entity(record: ['case' => 'case-9'], schema: $schema)));
		}

		$this->listener->handle(new ObjectCreatedEvent($this->entity(record: ['case' => 'case-9'], schema: 'caseProperty')));
		$document = $this->entity(record: ['case' => 'case-9'], schema: 'caseDocument');
		$this->listener->handle(new ObjectUpdatedEvent($document, $document));

		self::assertSame(expected: ['case-9', 'case-9', 'case-9'], actual: $this->stamped);
	}//end testAnActivityRecordStampsItsCase()

	/**
	 * A note added on an OpenRegister object stamps that object; an edit or another app's comment does not.
	 *
	 * @return void
	 */
	public function testANewNoteStampsTheObjectItIsOn(): void {
		$this->listener->handle($this->comment(kind: 'add', objectType: 'openregister', objectId: 'case-3'));
		$this->listener->handle($this->comment(kind: 'update', objectType: 'openregister', objectId: 'case-3'));
		$this->listener->handle($this->comment(kind: 'add', objectType: 'files', objectId: '42'));

		self::assertSame(expected: ['case-3'], actual: $this->stamped);
	}//end testANewNoteStampsTheObjectItIsOn()

	/**
	 * A completed task stamps its case; a withdrawn or uncommitted one does not.
	 *
	 * @return void
	 */
	public function testACompletedTaskStampsItsCase(): void {
		$this->listener->handle($this->task(state: 'completed', caseId: 'case-5', committed: true));
		$this->listener->handle($this->task(state: 'terminated', caseId: 'case-5', committed: true));
		$this->listener->handle($this->task(state: 'completed', caseId: 'case-5', committed: false));

		self::assertSame(expected: ['case-5'], actual: $this->stamped);
	}//end testACompletedTaskStampsItsCase()

	/**
	 * An entity whose payload is this record in this schema.
	 *
	 * @param array<string, mixed> $record The fields.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $record, string $schema): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			array_merge(['@self' => ['id' => 'case-1', 'schema' => $schema]], $record)
		);

		return $entity;
	}//end entity()

	/**
	 * A case update from before to after.
	 *
	 * @param array<string, mixed> $before The case before.
	 * @param array<string, mixed> $after  The case after.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function updated(array $before, array $after): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent($this->entity(record: $after, schema: 'case'), $this->entity(record: $before, schema: 'case'));
	}//end updated()

	/**
	 * A comment event.
	 *
	 * @param string $kind       'add', 'update', ...
	 * @param string $objectType The comment's object type.
	 * @param string $objectId   The object it is on.
	 *
	 * @return CommentsEvent The event.
	 */
	private function comment(string $kind, string $objectType, string $objectId): CommentsEvent {
		$comment = $this->createMock(originalClassName: IComment::class);
		$comment->method('getObjectType')->willReturn($objectType);
		$comment->method('getObjectId')->willReturn($objectId);

		return new CommentsEvent($kind, $comment);
	}//end comment()

	/**
	 * An engine terminal event for a task on a case.
	 *
	 * @param string $state     The terminal state.
	 * @param string $caseId    The case the task is on.
	 * @param bool   $committed Whether the terminal write committed.
	 *
	 * @return TaskTerminalEvent The event.
	 */
	private function task(string $state, string $caseId, bool $committed): TaskTerminalEvent {
		$entity = $this->getMockBuilder(className: Task::class)
			->disableOriginalConstructor()
			->onlyMethods(['getState'])
			->addMethods(['getObjectUuid'])
			->getMock();
		$entity->method('getState')->willReturn($state);
		$entity->method('getObjectUuid')->willReturn($caseId);

		$event = $this->createMock(originalClassName: TaskTerminalEvent::class);
		$event->method('getTask')->willReturn($entity);
		$event->method('isCommitted')->willReturn($committed);

		return $event;
	}//end task()
}//end class
