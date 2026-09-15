<?php

/**
 * An item closes with its subject, and never because somebody pressed a button.
 *
 * The whole requirement is one sentence: "A person SHALL NOT be able to
 * dismiss an item whose work still stands." These tests hold both halves of
 * it, and the second half is the one that needs holding. A dismissal is easy
 * to add later, in a hurry, because a reader asked for it on a bad morning,
 * and nothing else in the app would notice.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueItemLifecycle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\QueueItemLifecycle
 */
class QueueItemLifecycleTest extends TestCase {
	/**
	 * The lifecycle under test.
	 *
	 * @var QueueItemLifecycle
	 */
	private QueueItemLifecycle $lifecycle;

	/**
	 * Build the lifecycle.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->lifecycle = new QueueItemLifecycle();
	}

	/**
	 * An item pointing at one subject.
	 *
	 * @param string      $subjectType What it points at.
	 * @param string|null $coveredFor  The colleague whose work it is.
	 *
	 * @return QueueItem The item.
	 */
	private function item(string $subjectType = 'case', ?string $coveredFor = null): QueueItem {
		return new QueueItem(
			source: 'assigned-cases',
			subjectType: $subjectType,
			subjectId: 'case-1',
			title: 'A case',
			priority: 'normal',
			dueAt: null,
			coveredFor: $coveredFor
		);
	}

	/**
	 * A case still assigned to the reader stays.
	 *
	 * @return void
	 */
	public function testLiveWorkStillStands(): void {
		self::assertTrue(
			$this->lifecycle->stillStands(
				item: $this->item(),
				subject: ['assignee' => 'alice', 'isFinalStatus' => false],
				userId: 'alice'
			)
		);
	}

	/**
	 * A case that reached a final status leaves, and says it is done.
	 *
	 * @return void
	 */
	public function testAFinishedCaseLeaves(): void {
		self::assertSame(
			'done',
			$this->lifecycle->closingReason(
				item: $this->item(),
				subject: ['assignee' => 'alice', 'isFinalStatus' => true],
				userId: 'alice'
			)
		);
	}

	/**
	 * A completed engine task leaves.
	 *
	 * @return void
	 */
	public function testACompletedTaskLeaves(): void {
		self::assertSame(
			'done',
			$this->lifecycle->closingReason(
				item: $this->item(subjectType: 'task'),
				subject: ['state' => 'completed'],
				userId: 'alice'
			)
		);
	}

	/**
	 * A case somebody else picked up leaves, and says it was taken over.
	 *
	 * @return void
	 */
	public function testACaseTakenOverLeaves(): void {
		self::assertSame(
			'taken-over',
			$this->lifecycle->closingReason(
				item: $this->item(),
				subject: ['assignee' => 'bob', 'isFinalStatus' => false],
				userId: 'alice'
			)
		);
	}

	/**
	 * A subject that is gone leaves, and says it was withdrawn.
	 *
	 * @return void
	 */
	public function testAWithdrawnSubjectLeaves(): void {
		self::assertSame(
			'withdrawn',
			$this->lifecycle->closingReason(item: $this->item(), subject: null, userId: 'alice')
		);
	}

	/**
	 * Covered work is not "taken over" by the colleague it belongs to.
	 *
	 * The plain assignee comparison would close every covered item the moment
	 * it appeared, because covered work IS assigned to somebody else. That is
	 * the bug this case exists for.
	 *
	 * @return void
	 */
	public function testCoveredWorkIsNotTakenOverByItsOwner(): void {
		self::assertTrue(
			$this->lifecycle->stillStands(
				item: $this->item(coveredFor: 'bob'),
				subject: ['assignee' => 'bob', 'isFinalStatus' => false],
				userId: 'alice'
			)
		);
	}

	/**
	 * Covered work reassigned to a third person does leave.
	 *
	 * @return void
	 */
	public function testCoveredWorkReassignedElsewhereLeaves(): void {
		self::assertSame(
			'taken-over',
			$this->lifecycle->closingReason(
				item: $this->item(coveredFor: 'bob'),
				subject: ['assignee' => 'carol', 'isFinalStatus' => false],
				userId: 'alice'
			)
		);
	}

	/**
	 * A mention names a reader and not an owner, so it does not close on one.
	 *
	 * @return void
	 */
	public function testASubjectWithNoOwnerStillStands(): void {
		self::assertTrue(
			$this->lifecycle->stillStands(
				item: $this->item(subjectType: 'mention'),
				subject: ['title' => 'A note'],
				userId: 'alice'
			)
		);
	}

	/**
	 * Live work is refused, and the reader is offered the group instead.
	 *
	 * @return void
	 */
	public function testLiveWorkCannotBeDismissed(): void {
		self::assertSame(
			['removed' => false, 'reason' => null, 'offer' => QueueItemLifecycle::OFFER_HIDE_GROUP],
			$this->lifecycle->onRemoveRequested(
				item: $this->item(),
				subject: ['assignee' => 'alice', 'isFinalStatus' => false],
				userId: 'alice'
			)
		);
	}

	/**
	 * Work that already closed needs no gesture, and none is refused.
	 *
	 * The mirror of the test above: without it, a refusal could be coming from
	 * the method refusing everything rather than from the work standing.
	 *
	 * @return void
	 */
	public function testClosedWorkNeedsNoGesture(): void {
		self::assertSame(
			['removed' => true, 'reason' => 'done', 'offer' => null],
			$this->lifecycle->onRemoveRequested(
				item: $this->item(),
				subject: ['assignee' => 'alice', 'isFinalStatus' => true],
				userId: 'alice'
			)
		);
	}

	/**
	 * There are three ways out and they are the ones the requirement names.
	 *
	 * @return void
	 */
	public function testTheOnlyWaysOutAreDoneTakenOverOrWithdrawn(): void {
		self::assertSame(['done', 'taken-over', 'withdrawn'], QueueItemLifecycle::CLOSING_REASONS);
	}
}//end class
