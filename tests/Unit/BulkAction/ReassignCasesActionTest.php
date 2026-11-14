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

use InvalidArgumentException;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\Service\Support\CaseAssigneeWriter;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The redistribution's write: what reaches the assignee writer, the batch id,
 * the rehearsal and the failures.
 *
 * @covers \OCA\Dossiq\BulkAction\ReassignCasesAction
 */
class ReassignCasesActionTest extends TestCase {

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
	 * @param CaseAssigneeWriter   $writer     The writer double.
	 * @param array<string, mixed> $case       The case payload.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     Commit or rehearse.
	 * @param IUser|null           $actor      The acting user.
	 * @param string               $uuid       The case uuid.
	 *
	 * @return BulkActionResult The outcome.
	 */
	private function applyTo(
		CaseAssigneeWriter $writer,
		array $case,
		array $parameters,
		bool $commit = true,
		?IUser $actor = null,
		string $uuid = 'case-1',
	): BulkActionResult {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($case);

		return (new ReassignCasesAction(writer: $writer, l10n: $this->l10n()))->apply(
			step: new BulkStep(object: $object, parameters: $parameters, commit: $commit, actor: $actor)
		);
	}//end applyTo()

	/**
	 * A committed reassignment hands the writer the case, the trimmed receiver,
	 * the actor and the job's batch id.
	 *
	 * @return void
	 */
	public function testACommittedReassignmentReachesTheWriter(): void {
		$actor = $this->createMock(originalClassName: IUser::class);
		$actor->method('getUID')->willReturn('coordinator');
		$case = ['assignee' => 'handler-1', 'title' => 'Permit'];

		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->expects($this->never())->method('newBatchId');
		$writer->expects($this->once())
			->method('reassignOne')
			->with('case-1', ['id' => 'case-1'] + $case, 'handler-2', 'coordinator', 'batch-7')
			->willReturn(true);

		$result = $this->applyTo($writer, $case, ['toUser' => ' handler-2 ', 'reason' => 'r', 'batchId' => 'batch-7'], true, $actor);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testACommittedReassignmentReachesTheWriter()

	/**
	 * Without a batch id in the job the writer mints one; without an actor the
	 * actor id is empty.
	 *
	 * @return void
	 */
	public function testAMissingBatchIdIsMintedAndAMissingActorIsEmpty(): void {
		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->expects($this->once())->method('newBatchId')->willReturn('batch-new');
		$writer->expects($this->once())
			->method('reassignOne')
			->with('case-1', ['id' => 'case-1'], 'handler-2', '', 'batch-new')
			->willReturn(true);

		$result = $this->applyTo($writer, [], ['toUser' => 'handler-2', 'reason' => 'r']);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testAMissingBatchIdIsMintedAndAMissingActorIsEmpty()

	/**
	 * A rehearsal of a case the receiver does not hold is applied without a write.
	 *
	 * @return void
	 */
	public function testARehearsalWritesNothing(): void {
		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->expects($this->never())->method('reassignOne');
		$writer->expects($this->never())->method('newBatchId');

		$result = $this->applyTo($writer, ['assignee' => 'handler-1'], ['toUser' => 'handler-2', 'reason' => 'r'], false);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testARehearsalWritesNothing()

	/**
	 * A writer that throws fails the member with its message; an unwritten
	 * write fails with its own code.
	 *
	 * @return void
	 */
	public function testWriterFailuresAreFailed(): void {
		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->method('reassignOne')->willThrowException(new RuntimeException('user does not exist'));

		$result = $this->applyTo($writer, [], ['toUser' => 'ghost', 'reason' => 'r', 'batchId' => 'b']);
		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
		$this->assertSame(expected: 'user does not exist', actual: $result->getReason());

		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->method('reassignOne')->willReturn(false);

		$result = $this->applyTo($writer, [], ['toUser' => 'handler-2', 'reason' => 'r', 'batchId' => 'b']);
		$this->assertSame(expected: 'reassignment_write_failed', actual: $result->getReason());
	}//end testWriterFailuresAreFailed()

	/**
	 * A case without an id is skipped before anything else.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsSkipped(): void {
		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->expects($this->never())->method('reassignOne');

		$result = $this->applyTo($writer, ['assignee' => 'handler-2'], ['toUser' => 'handler-2', 'reason' => 'r'], true, null, '');

		$this->assertSame(expected: 'no_case_id', actual: $result->getReason());
	}//end testACaseWithoutAnIdIsSkipped()

	/**
	 * A reassignment without a receiver is refused before a job exists, and
	 * the action describes itself.
	 *
	 * @return void
	 */
	public function testAReassignmentNeedsAReceiver(): void {
		$action = new ReassignCasesAction(
			writer: $this->createMock(originalClassName: CaseAssigneeWriter::class),
			l10n: $this->l10n()
		);

		$this->assertSame(expected: 'Give the cases to another handler', actual: $action->getLabel());
		$this->assertStringContainsString(needle: 'who it came from', haystack: $action->getDescription());
		$this->assertSame(expected: [], actual: $action->getGuards());
		$action->validateParameters(['toUser' => 'handler-2', 'reason' => 'r']);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage('toUser is required');
		$action->validateParameters(['toUser' => ' ', 'reason' => 'r']);
	}//end testAReassignmentNeedsAReceiver()
}//end class
