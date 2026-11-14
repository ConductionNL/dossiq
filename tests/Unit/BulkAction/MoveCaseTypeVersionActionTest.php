<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\BulkAction
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BulkAction;

use OCA\Dossiq\BulkAction\MoveCaseTypeVersionAction;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The version move's refusals and failures, the actor it records and the
 * fallback sentence of a refusal without reasons.
 *
 * @covers \OCA\Dossiq\BulkAction\MoveCaseTypeVersionAction
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class MoveCaseTypeVersionActionTest extends TestCase {

	/**
	 * A localisation double that answers its own input.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

	/**
	 * Run the action over one case.
	 *
	 * @param CaseVersionMove $move   The move double.
	 * @param bool            $commit Commit or rehearse.
	 * @param IUser|null      $actor  The acting user.
	 * @param string          $uuid   The case uuid.
	 *
	 * @return BulkActionResult The outcome.
	 */
	private function applyTo(CaseVersionMove $move, bool $commit, ?IUser $actor = null, string $uuid = 'case-1'): BulkActionResult {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject([]);

		return (new MoveCaseTypeVersionAction(move: $move, l10n: $this->l10n()))->apply(
			step: new BulkStep(
				object: $object,
				parameters: ['target' => ' ct-2 ', 'reason' => ' Nieuwe regels '],
				commit: $commit,
				actor: $actor
			)
		);
	}//end applyTo()

	/**
	 * The committed move records the acting user and the trimmed target and reason.
	 *
	 * @return void
	 */
	public function testTheCommittedMoveRecordsTheActor(): void {
		$actor = $this->createMock(originalClassName: IUser::class);
		$actor->method('getUID')->willReturn('coordinator');
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->expects($this->once())
			->method('move')
			->with(caseId: 'case-1', targetCaseTypeId: 'ct-2', reason: 'Nieuwe regels', actorUid: 'coordinator')
			->willReturn([]);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $this->applyTo($move, true, $actor)->getOutcome());
	}//end testTheCommittedMoveRecordsTheActor()

	/**
	 * A refusal on the commit is refused with its sentence, not failed.
	 *
	 * @return void
	 */
	public function testARefusedCommitIsRefusedWithTheSentence(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->method('move')->willThrowException(
			new RefusedException(rule: 'version_move.no_landing', sentence: 'This case has nowhere to land.')
		);

		$result = $this->applyTo($move, true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_REFUSED, actual: $result->getOutcome());
		$this->assertSame(expected: 'This case has nowhere to land.', actual: $result->getReason());
	}//end testARefusedCommitIsRefusedWithTheSentence()

	/**
	 * Any other exception, on commit or preview, fails the member.
	 *
	 * @return void
	 */
	public function testOtherExceptionsAreFailed(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->method('move')->willThrowException(new RuntimeException('write failed'));
		$move->method('preview')->willThrowException(new RuntimeException('case type not found'));

		$committed = $this->applyTo($move, true);
		$rehearsed = $this->applyTo($move, false);

		$this->assertSame(expected: [BulkJobMember::OUTCOME_FAILED, 'write failed'], actual: [$committed->getOutcome(), $committed->getReason()]);
		$this->assertSame(expected: [BulkJobMember::OUTCOME_FAILED, 'case type not found'], actual: [$rehearsed->getOutcome(), $rehearsed->getReason()]);
	}//end testOtherExceptionsAreFailed()

	/**
	 * A preview that refuses without reasons is refused with the fallback sentence.
	 *
	 * @return void
	 */
	public function testARefusalWithoutReasonsGetsTheFallbackSentence(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->method('preview')->with(caseId: 'case-1', targetCaseTypeId: 'ct-2')->willReturn(['canMove' => false, 'refusals' => []]);
		$move->expects($this->never())->method('move');

		$result = $this->applyTo($move, false);

		$this->assertSame(expected: BulkJobMember::OUTCOME_REFUSED, actual: $result->getOutcome());
		$this->assertSame(expected: 'This case cannot move to that version.', actual: $result->getReason());
	}//end testARefusalWithoutReasonsGetsTheFallbackSentence()

	/**
	 * A case without an id is skipped before the move is asked anything.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsSkipped(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->expects($this->never())->method('preview');
		$move->expects($this->never())->method('move');

		$result = $this->applyTo($move, false, null, '');

		$this->assertSame(expected: [BulkJobMember::OUTCOME_SKIPPED, 'no_case_id'], actual: [$result->getOutcome(), $result->getReason()]);
	}//end testACaseWithoutAnIdIsSkipped()
}//end class
