<?php

/**
 * Unit tests for the transferred case act listener.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TransferredCaseActListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Transfer\TransferredCaseFollow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Which events are an act on a case, and what each act is called.
 *
 * @covers \OCA\Dossiq\Listener\TransferredCaseActListener
 */
class TransferredCaseActListenerTest extends TestCase {

	/**
	 * The acts the follow was handed, as [caseId, act, actor].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $recorded = [];

	/**
	 * The listener over a recording follow, as the given user (or nobody).
	 *
	 * @param string|null $uid The session user, or null for the system.
	 *
	 * @return TransferredCaseActListener
	 */
	private function listener(?string $uid = 'bob'): TransferredCaseActListener {
		$this->recorded = [];

		$follow = $this->createMock(originalClassName: TransferredCaseFollow::class);
		$follow->method('record')->willReturnCallback(
			function (string $caseId, string $act, string $actor = ''): bool {
				$this->recorded[] = [$caseId, $act, $actor];
				return true;
			}
		);

		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new TransferredCaseActListener(follow: $follow, slugResolver: $resolver, userSession: $session);
	}//end listener()

	/**
	 * Scenario: the receiver adds a document, and the sender sees "document added".
	 *
	 * @return void
	 */
	public function testANewDocumentOnACaseIsADocumentAdded(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity(record: ['case' => 'case-9'], schema: 'caseDocument')));

		self::assertSame(expected: [['case-9', 'document added', 'bob']], actual: $this->recorded);
	}//end testANewDocumentOnACaseIsADocumentAdded()

	/**
	 * Both contact records are a contact moment; other records and an updated document are no act.
	 *
	 * @return void
	 */
	public function testContactRecordsAreContactMomentsAndOtherRecordsAreNoAct(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity(record: ['case' => 'case-9'], schema: 'customerContact')));
		$listener->handle(new ObjectCreatedEvent($this->entity(record: ['case' => 'case-9'], schema: 'contactmoment')));
		$listener->handle(new ObjectCreatedEvent($this->entity(record: ['case' => 'case-9'], schema: 'caseProperty')));
		$listener->handle(new ObjectCreatedEvent($this->entity(record: [], schema: 'caseDocument')));
		$document = $this->entity(record: ['case' => 'case-9'], schema: 'caseDocument');
		$listener->handle(new ObjectUpdatedEvent($document, $document));

		self::assertSame(
			expected: [['case-9', 'contact moment added', 'bob'], ['case-9', 'contact moment added', 'bob']],
			actual: $this->recorded
		);
	}//end testContactRecordsAreContactMomentsAndOtherRecordsAreNoAct()

	/**
	 * A case's status move is an act; a save that keeps the status, or has no before, is not.
	 *
	 * @return void
	 */
	public function testOnlyAStatusMoveOfACaseIsStatusChanged(): void {
		$listener = $this->listener(uid: null);
		$listener->handle($this->updated(before: ['status' => 'st-1'], after: ['status' => 'st-2']));
		$listener->handle($this->updated(before: ['status' => 'st-1', 'title' => 'a'], after: ['status' => 'st-1', 'title' => 'b']));
		$listener->handle(new ObjectUpdatedEvent($this->entity(record: ['status' => 'st-3'], schema: 'case')));
		$listener->handle(new ObjectCreatedEvent($this->entity(record: [], schema: 'case')));

		self::assertSame(expected: [['case-1', 'status changed', '']], actual: $this->recorded);
	}//end testOnlyAStatusMoveOfACaseIsStatusChanged()

	/**
	 * A new note on an OpenRegister object is a note added; an edit or another app's comment is not.
	 *
	 * @return void
	 */
	public function testANewNoteIsANoteAdded(): void {
		$listener = $this->listener();
		$listener->handle($this->comment(kind: 'add', objectType: 'openregister', objectId: 'case-3'));
		$listener->handle($this->comment(kind: 'update', objectType: 'openregister', objectId: 'case-3'));
		$listener->handle($this->comment(kind: 'add', objectType: 'files', objectId: '42'));

		self::assertSame(expected: [['case-3', 'note added', 'bob']], actual: $this->recorded);
	}//end testANewNoteIsANoteAdded()

	/**
	 * A completed task is a task completed; a withdrawn or uncommitted one is not.
	 *
	 * @return void
	 */
	public function testACompletedTaskIsATaskCompleted(): void {
		$listener = $this->listener();
		$listener->handle($this->task(state: 'completed', caseId: 'case-5', committed: true));
		$listener->handle($this->task(state: 'terminated', caseId: 'case-5', committed: true));
		$listener->handle($this->task(state: 'completed', caseId: 'case-5', committed: false));

		self::assertSame(expected: [['case-5', 'task completed', 'bob']], actual: $this->recorded);
	}//end testACompletedTaskIsATaskCompleted()

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
			array_merge(['id' => 'case-1', '@self' => ['id' => 'case-1', 'schema' => $schema]], $record)
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
