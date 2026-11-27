<?php

/**
 * CaseVersionMoves Unit Tests
 *
 * The writer is the signed-in caller only when the guard lets them change the
 * case; the options carry a preview only for a named target; the move goes
 * out as the actor's uid.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\CaseVersionMoves;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseVersionMoveInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseVersionMoves.
 *
 * @covers \OCA\Dossiq\Controller\Support\CaseVersionMoves
 */
final class CaseVersionMovesTest extends TestCase {

	/**
	 * The writer is the caller when the guard allows, and null when signed out or refused.
	 *
	 * @return void
	 */
	public function testTheWriterIsTheCallerOnlyWhenTheGuardAllows(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls(null, $user, $user);
		$guard = $this->createMock(originalClassName: CaseAccessGuardInterface::class);
		$guard->method('hasCaseMutationAccess')->willReturnOnConsecutiveCalls(false, true);
		$moves = new CaseVersionMoves(
			move: $this->createMock(originalClassName: CaseVersionMoveInterface::class),
			accessGuard: $guard,
			userSession: $session,
		);

		$this->assertNull(actual: $moves->writer(caseId: 'case-1'));
		$this->assertNull(actual: $moves->writer(caseId: 'case-1'));
		$this->assertSame(expected: $user, actual: $moves->writer(caseId: 'case-1'));
	}//end testTheWriterIsTheCallerOnlyWhenTheGuardAllows()

	/**
	 * The options carry a preview only when a target is named.
	 *
	 * @return void
	 */
	public function testTheOptionsCarryAPreviewOnlyForANamedTarget(): void {
		$move = $this->createMock(originalClassName: CaseVersionMoveInterface::class);
		$move->method('options')->willReturn(['versions' => ['v2']]);
		$move->expects($this->once())->method('preview')->with('case-1', 'v2')->willReturn(['changes' => []]);
		$moves = $this->moves(move: $move);

		$this->assertSame(expected: ['versions' => ['v2']], actual: $moves->options(caseId: 'case-1', target: ''));
		$this->assertSame(
			expected: ['versions' => ['v2'], 'preview' => ['changes' => []]],
			actual: $moves->options(caseId: 'case-1', target: 'v2')
		);
	}//end testTheOptionsCarryAPreviewOnlyForANamedTarget()

	/**
	 * The move goes out with the actor's uid.
	 *
	 * @return void
	 */
	public function testTheMoveGoesOutAsTheActorsUid(): void {
		$actor = $this->createMock(originalClassName: IUser::class);
		$actor->method('getUID')->willReturn('anna');
		$move = $this->createMock(originalClassName: CaseVersionMoveInterface::class);
		$move->expects($this->once())->method('move')->with('case-1', 'v2', 'new rules', 'anna')->willReturn(['moved' => true]);

		$this->assertSame(
			expected: ['moved' => true],
			actual: $this->moves(move: $move)->move(caseId: 'case-1', target: 'v2', reason: 'new rules', actor: $actor)
		);
	}//end testTheMoveGoesOutAsTheActorsUid()

	/**
	 * The moves over a move service, with a session and guard that are not asked.
	 *
	 * @param CaseVersionMoveInterface $move The move service.
	 *
	 * @return CaseVersionMoves
	 */
	private function moves(CaseVersionMoveInterface $move): CaseVersionMoves {
		return new CaseVersionMoves(
			move: $move,
			accessGuard: $this->createMock(originalClassName: CaseAccessGuardInterface::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
		);
	}//end moves()
}//end class
