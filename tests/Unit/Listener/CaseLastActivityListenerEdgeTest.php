<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseLastActivityListener;
use OCA\Dossiq\Service\Activity\CaseActivityStamp;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use OCP\Comments\IComment;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Events that are not work on a case, and a comment or task that cannot be
 * read, stamp nothing and never throw.
 *
 * @covers \OCA\Dossiq\Listener\CaseLastActivityListener
 */
class CaseLastActivityListenerEdgeTest extends TestCase {

	/**
	 * @var array<int, string> The case ids the stamp was asked to mark.
	 */
	private array $stamped = [];

	/**
	 * @return void
	 */
	public function testAnEventThatIsNoWorkStampsNothing(): void {
		$this->listener()->handle(new Event());

		$this->assertSame([], $this->stamped);
	}//end testAnEventThatIsNoWorkStampsNothing()

	/**
	 * @return void
	 */
	public function testACommentThatCannotBeReadStampsNothing(): void {
		$event = $this->createMock(CommentsEvent::class);
		$event->method('getComment')->willThrowException(new RuntimeException('comment gone'));

		$this->listener()->handle($event);

		$this->assertSame([], $this->stamped);
	}//end testACommentThatCannotBeReadStampsNothing()

	/**
	 * @return void
	 */
	public function testANoteOnAnotherKindOfObjectStampsNothingButOneOnAnObjectDoes(): void {
		$listener = $this->listener();
		$listener->handle($this->comment(objectType: 'files', objectId: '42'));
		$listener->handle($this->comment(objectType: 'openregister', objectId: ' case-7 '));

		$this->assertSame(['case-7'], $this->stamped);
	}//end testANoteOnAnotherKindOfObjectStampsNothingButOneOnAnObjectDoes()

	/**
	 * @return void
	 */
	public function testATaskThatCannotBeReadStampsNothing(): void {
		$event = $this->createMock(TaskTerminalEvent::class);
		$event->method('getTask')->willThrowException(new RuntimeException('task gone'));

		$this->listener()->handle($event);

		$this->assertSame([], $this->stamped);
	}//end testATaskThatCannotBeReadStampsNothing()

	/**
	 * @param string $objectType The comment's object type.
	 * @param string $objectId   The object it is on.
	 *
	 * @return CommentsEvent An added comment.
	 */
	private function comment(string $objectType, string $objectId): CommentsEvent {
		$comment = $this->createMock(IComment::class);
		$comment->method('getObjectType')->willReturn($objectType);
		$comment->method('getObjectId')->willReturn($objectId);

		return new CommentsEvent('add', $comment);
	}//end comment()

	/**
	 * @return CaseLastActivityListener The listener over a recording stamp.
	 */
	private function listener(): CaseLastActivityListener {
		$stamp = $this->createMock(CaseActivityStamp::class);
		$stamp->method('stamp')->willReturnCallback(
			function (string $caseId): bool {
				$this->stamped[] = $caseId;
				return true;
			}
		);

		return new CaseLastActivityListener(stamp: $stamp, slugResolver: $this->createMock(ObjectSchemaSlugResolver::class));
	}//end listener()
}//end class
