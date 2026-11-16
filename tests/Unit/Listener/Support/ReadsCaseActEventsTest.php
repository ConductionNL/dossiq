<?php

/**
 * Tests for reading the case act a comment or task event stands for.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\ReadsCaseActEvents;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use OCP\Comments\IComment;
use PHPUnit\Framework\TestCase;

/**
 * A new note answers its object, a completed committed task its case, and
 * anything else an empty string.
 *
 * @covers \OCA\Dossiq\Listener\Support\ReadsCaseActEvents
 *
 * @spec exclude shared event reading; each using listener carries its own spec
 */
class ReadsCaseActEventsTest extends TestCase {

	/**
	 * A class using the trait, with its private steps opened up.
	 *
	 * @var object
	 */
	private object $reader;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reader = new class {
			use ReadsCaseActEvents;

			public const NOTE_OBJECT_TYPE = 'openregister';

			/**
			 * Open notedObject().
			 *
			 * @param CommentsEvent $event The event.
			 *
			 * @return string
			 */
			public function noted(CommentsEvent $event): string {
				return $this->notedObject(event: $event);
			}//end noted()

			/**
			 * Open completedTaskCase().
			 *
			 * @param TaskTerminalEvent $event The event.
			 *
			 * @return string
			 */
			public function completed(TaskTerminalEvent $event): string {
				return $this->completedTaskCase(event: $event);
			}//end completed()
		};
	}//end setUp()

	/**
	 * An added note on an object answers the trimmed object id.
	 *
	 * @return void
	 */
	public function testAddedNoteAnswersItsObject(): void {
		$this->assertSame(expected: 'case-1', actual: $this->reader->noted($this->comment(kind: 'add', objectType: 'openregister')));
	}//end testAddedNoteAnswersItsObject()

	/**
	 * An edit, or a note under another object type, is no act.
	 *
	 * @return void
	 */
	public function testOtherNotesAnswerNothing(): void {
		$this->assertSame(expected: '', actual: $this->reader->noted($this->comment(kind: 'update', objectType: 'openregister')));
		$this->assertSame(expected: '', actual: $this->reader->noted($this->comment(kind: 'add', objectType: 'files')));
	}//end testOtherNotesAnswerNothing()

	/**
	 * A committed completed task answers its case; a withdrawn or uncommitted one nothing.
	 *
	 * @return void
	 */
	public function testCompletedTaskAnswersItsCase(): void {
		$this->assertSame(expected: 'case-2', actual: $this->reader->completed($this->task(state: 'completed', committed: true)));
		$this->assertSame(expected: '', actual: $this->reader->completed($this->task(state: 'terminated', committed: true)));
		$this->assertSame(expected: '', actual: $this->reader->completed($this->task(state: 'completed', committed: false)));
	}//end testCompletedTaskAnswersItsCase()

	/**
	 * A comment event on object id ' case-1 '.
	 *
	 * @param string $kind       The comment event kind.
	 * @param string $objectType The comment's object type.
	 *
	 * @return CommentsEvent
	 */
	private function comment(string $kind, string $objectType): CommentsEvent {
		$comment = $this->createMock(originalClassName: IComment::class);
		$comment->method('getObjectType')->willReturn($objectType);
		$comment->method('getObjectId')->willReturn(' case-1 ');

		return new CommentsEvent($kind, $comment);
	}//end comment()

	/**
	 * A terminal event for a task on case-2.
	 *
	 * @param string $state     The terminal state.
	 * @param bool   $committed Whether the write committed.
	 *
	 * @return TaskTerminalEvent
	 */
	private function task(string $state, bool $committed): TaskTerminalEvent {
		$entity = $this->getMockBuilder(className: Task::class)
			->disableOriginalConstructor()
			->onlyMethods(['getState'])
			->addMethods(['getObjectUuid'])
			->getMock();
		$entity->method('getState')->willReturn($state);
		$entity->method('getObjectUuid')->willReturn('case-2');

		$event = $this->createMock(originalClassName: TaskTerminalEvent::class);
		$event->method('getTask')->willReturn($entity);
		$event->method('isCommitted')->willReturn($committed);

		return $event;
	}//end task()
}//end class
