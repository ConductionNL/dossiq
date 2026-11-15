<?php

/**
 * Unit tests for PortalWithdrawalTarget: whether a resident's withdrawal can land.
 *
 * 🔴 WHAT THESE GUARD IS A BUTTON THAT FAILS IN FRONT OF A RESIDENT. A
 * withdrawal is one status write, so a move must lead straight to the target
 * from every status the withdrawal is open in. The edges matter as much as the
 * rule: a type with no moves is not judged on reachability at all, a malformed
 * open-status list opens nothing, and a stray non-row among the moves must not
 * stop a real move from being found.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PortalWithdrawalTarget.
 *
 * @covers \OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget
 */
class PortalWithdrawalTargetTest extends TestCase {

	/**
	 * The type's statuses, id to title.
	 *
	 * @var array<string, string>
	 */
	private const STATUSES = [
		's-open' => 'Ontvangen',
		's-busy' => 'In behandeling',
		's-gone' => 'Ingetrokken',
	];

	/**
	 * A type with no moves is judged only on owning the target.
	 *
	 * @return void
	 */
	public function testATypeWithNoMovesIsNotJudgedOnReachability(): void {
		$target = new PortalWithdrawalTarget();
		$withdrawal = ['targetStatus' => 's-gone', 'openStatuses' => ['s-open', 's-busy']];

		$this->assertNull($target->refusal(withdrawal: $withdrawal, statuses: self::STATUSES, moves: []));
		$this->assertSame(
			['reason' => PortalWithdrawalTarget::NOT_OWN, 'parameters' => ['s-elsewhere']],
			$target->refusal(withdrawal: ['targetStatus' => 's-elsewhere'], statuses: self::STATUSES, moves: [])
		);
	}//end testATypeWithNoMovesIsNotJudgedOnReachability()

	/**
	 * Open statuses that are not a list open nothing, so nothing is unreachable.
	 *
	 * @return void
	 */
	public function testOpenStatusesThatAreNotAListOpenNothing(): void {
		$withdrawal = ['targetStatus' => 's-gone', 'openStatuses' => 's-open'];
		$moves = [['fromStatus' => 's-busy', 'toStatus' => 's-open']];

		$this->assertNull((new PortalWithdrawalTarget())->refusal(withdrawal: $withdrawal, statuses: self::STATUSES, moves: $moves));
	}//end testOpenStatusesThatAreNotAListOpenNothing()

	/**
	 * A stray non-row among the moves is skipped, and the real move still counts.
	 *
	 * The control beside it is the same withdrawal with the real move taken
	 * away: then it is refused, naming both titles.
	 *
	 * @return void
	 */
	public function testANonRowAmongTheMovesIsSkipped(): void {
		$target = new PortalWithdrawalTarget();
		$withdrawal = ['targetStatus' => 's-gone', 'openStatuses' => ['s-open']];
		// Stored transitions are decoded JSON, so a non-row can sit among them whatever the signature promises.
		/** @var array<int, array<string, mixed>> $reachable */
		$reachable = ['not a move', ['fromStatus' => 's-open', 'toStatus' => 's-gone']];
		/** @var array<int, array<string, mixed>> $unreachable */
		$unreachable = ['not a move', ['fromStatus' => 's-busy', 'toStatus' => 's-gone']];

		$this->assertNull($target->refusal(withdrawal: $withdrawal, statuses: self::STATUSES, moves: $reachable));
		$this->assertSame(
			['reason' => PortalWithdrawalTarget::UNREACHABLE, 'parameters' => ['Ontvangen', 'Ingetrokken']],
			$target->refusal(withdrawal: $withdrawal, statuses: self::STATUSES, moves: $unreachable)
		);
	}//end testANonRowAmongTheMovesIsSkipped()
	/**
	 * The target itself, and a status the type does not have, are not judged as open statuses.
	 *
	 * A case already in the target has nothing to withdraw, and a stale id in
	 * the open list names no status a case can be in; refusing on either would
	 * block a withdrawal that works from every real open status.
	 *
	 * @return void
	 */
	public function testTheTargetAndAForeignStatusAreNotJudged(): void {
		$withdrawal = ['targetStatus' => 's-gone', 'openStatuses' => ['s-gone', 's-stale', ['id' => 's-open']]];
		$moves = [['fromStatus' => 's-open', 'toStatus' => 's-gone']];

		$this->assertNull((new PortalWithdrawalTarget())->refusal(withdrawal: $withdrawal, statuses: self::STATUSES, moves: $moves));
	}//end testTheTargetAndAForeignStatusAreNotJudged()
}//end class
