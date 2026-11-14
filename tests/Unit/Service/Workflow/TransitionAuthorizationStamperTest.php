<?php

/**
 * Unit tests for TransitionAuthorizationStamper: each transition is stamped
 * with the group ids its roles resolve to, a stale stamp is dropped when no
 * group resolves, and non-object entries pass through untouched.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\WorkflowStepAuthorizationResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper
 */
class TransitionAuthorizationStamperTest extends TestCase {

	public function testTransitionsAreStampedWithTheirResolvedGroups(): void {
		$resolver = $this->createMock(WorkflowStepAuthorizationResolver::class);
		$resolver->expects($this->exactly(2))->method('resolveGroupIds')->willReturnCallback(
			static fn (array $entry): array => ['approve' => ['x' => 'g-teamleads', 'y' => 'g-admin'], 'reopen' => []][$entry['name']]
		);

		$stamped = (new TransitionAuthorizationStamper($resolver))->stamp(
			[
				['name' => 'approve', 'roles' => ['teamleider'], 'authorization' => ['g-old']],
				'not a transition',
				['name' => 'reopen', 'roles' => ['retired-role'], 'authorization' => ['g-old']],
			]
		);

		$this->assertSame(
			[
				['name' => 'approve', 'roles' => ['teamleider'], 'authorization' => ['g-teamleads', 'g-admin']],
				'not a transition',
				['name' => 'reopen', 'roles' => ['retired-role']],
			],
			$stamped
		);
	}//end testTransitionsAreStampedWithTheirResolvedGroups()

	public function testTheResolverSeesTheTransitionWithoutItsOldStamp(): void {
		$resolver = $this->createMock(WorkflowStepAuthorizationResolver::class);
		$resolver->expects($this->once())->method('resolveGroupIds')->with(['name' => 'approve'])->willReturn([]);

		(new TransitionAuthorizationStamper($resolver))->stamp([['name' => 'approve', 'authorization' => ['g-old']]]);
	}//end testTheResolverSeesTheTransitionWithoutItsOldStamp()

	public function testNoTransitionsIsNothingToStamp(): void {
		$resolver = $this->createMock(WorkflowStepAuthorizationResolver::class);
		$resolver->expects($this->never())->method('resolveGroupIds');

		$this->assertNull((new TransitionAuthorizationStamper($resolver))->stamp([]));
	}//end testNoTransitionsIsNothingToStamp()
}//end class
