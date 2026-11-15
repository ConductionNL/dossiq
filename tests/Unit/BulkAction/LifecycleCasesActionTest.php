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
use OCA\Dossiq\BulkAction\LifecycleCasesAction;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The lifecycle action's refusal codes, the resume and extend writes, and
 * the failures of the lifecycle service.
 *
 * @covers \OCA\Dossiq\BulkAction\LifecycleCasesAction
 */
class LifecycleCasesActionTest extends TestCase {

	/**
	 * An object entity carrying one case.
	 *
	 * @param string               $uuid The case uuid.
	 * @param array<string, mixed> $data The case payload.
	 *
	 * @return ObjectEntity The object the job would hand an action.
	 */
	private function caseObject(string $uuid, array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);

		return $object;
	}//end caseObject()

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
	 * @param CaseLifecycleService $lifecycle  The service double.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     Commit or rehearse.
	 * @param string               $uuid       The case uuid.
	 *
	 * @return BulkActionResult The outcome.
	 */
	private function applyTo(CaseLifecycleService $lifecycle, array $parameters, bool $commit = true, string $uuid = 'case-1'): BulkActionResult {
		return (new LifecycleCasesAction(lifecycle: $lifecycle, l10n: $this->l10n()))->apply(
			step: new BulkStep(object: $this->caseObject(uuid: $uuid), parameters: $parameters, commit: $commit, actor: null)
		);
	}//end applyTo()

	/**
	 * Each refused gesture gets the code the case page shows for it.
	 *
	 * @param string               $gesture  The gesture.
	 * @param array<string, mixed> $state    The case's lifecycle state.
	 * @param string               $expected The refusal code.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testARefusedGestureIsSkippedWithItsCode(string $gesture, array $state, string $expected): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->with('case-1')->willReturn($state);
		$lifecycle->expects($this->never())->method($gesture);

		$result = $this->applyTo($lifecycle, ['gesture' => $gesture, 'reason' => 'r']);

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: $expected, actual: $result->getReason());
	}//end testARefusedGestureIsSkippedWithItsCode()

	/**
	 * Gestures, states and the codes they are refused with.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'suspend a closed case'    => ['suspend', ['canSuspend' => false, 'isFinalStatus' => true, 'suspended' => true], 'case_closed'],
			'extend a closed case'     => ['extend', ['canExtend' => false, 'isFinalStatus' => true], 'case_closed'],
			'resume a closed case'     => ['resume', ['canResume' => false, 'isFinalStatus' => true], 'not_suspended'],
			'suspend twice'            => ['suspend', ['canSuspend' => false, 'suspended' => true], 'already_suspended'],
			'extend without a limit'   => ['extend', ['canExtend' => false, 'extensionCount' => 5], 'extension_not_allowed'],
			'extend below the limit'   => ['extend', ['canExtend' => false, 'extensionCount' => 0, 'maxExtensions' => 2], 'extension_not_allowed'],
			'canX not strictly true'   => ['resume', ['canResume' => 1], 'not_suspended'],
		];
	}//end refusals()

	/**
	 * A permitted resume writes through the service with the trimmed reason.
	 *
	 * @return void
	 */
	public function testAPermittedResumeWrites(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canResume' => true]);
		$lifecycle->expects($this->once())->method('resume')->with('case-1', 'Documents arrived')->willReturn([]);

		$result = $this->applyTo($lifecycle, ['gesture' => ' resume ', 'reason' => ' Documents arrived ']);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testAPermittedResumeWrites()

	/**
	 * A permitted extension writes the new end date.
	 *
	 * @return void
	 */
	public function testAPermittedExtensionWritesTheNewEndDate(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canExtend' => true]);
		$lifecycle->expects($this->once())->method('extend')->with('case-1', 'More time', '2027-01-31')->willReturn([]);

		$result = $this->applyTo($lifecycle, ['gesture' => 'extend', 'reason' => 'More time', 'newEndDate' => '2027-01-31']);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testAPermittedExtensionWritesTheNewEndDate()

	/**
	 * A suspension without a day count writes zero days.
	 *
	 * @return void
	 */
	public function testASuspensionWithoutDaysWritesZero(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canSuspend' => true]);
		$lifecycle->expects($this->once())->method('suspend')->with('case-1', 'Waiting', 0)->willReturn([]);

		$this->assertSame(
			expected: BulkJobMember::OUTCOME_APPLIED,
			actual: $this->applyTo($lifecycle, ['gesture' => 'suspend', 'reason' => 'Waiting'])->getOutcome()
		);
	}//end testASuspensionWithoutDaysWritesZero()

	/**
	 * A write that throws fails that member with the message.
	 *
	 * @return void
	 */
	public function testAWriteThatThrowsIsFailed(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canExtend' => true]);
		$lifecycle->method('extend')->willThrowException(new RuntimeException('end date is in the past'));

		$result = $this->applyTo($lifecycle, ['gesture' => 'extend', 'reason' => 'r', 'newEndDate' => '2020-01-01']);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
		$this->assertSame(expected: 'end date is in the past', actual: $result->getReason());
	}//end testAWriteThatThrowsIsFailed()

	/**
	 * A state that cannot be read fails the member, also on a rehearsal.
	 *
	 * @return void
	 */
	public function testAnUnreadableStateIsFailed(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willThrowException(new RuntimeException('case not found'));

		$result = $this->applyTo($lifecycle, ['gesture' => 'suspend', 'reason' => 'r'], false);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
		$this->assertSame(expected: 'case not found', actual: $result->getReason());
	}//end testAnUnreadableStateIsFailed()

	/**
	 * A case without an id is skipped before the state is read.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsSkipped(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->expects($this->never())->method('state');

		$result = $this->applyTo($lifecycle, ['gesture' => 'suspend', 'reason' => 'r'], true, '');

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: 'no_case_id', actual: $result->getReason());
	}//end testACaseWithoutAnIdIsSkipped()

	/**
	 * Only the three gestures are accepted, and each needs a reason.
	 *
	 * @return void
	 */
	public function testOnlyTheThreeGesturesAreAccepted(): void {
		$action = new LifecycleCasesAction(
			lifecycle: $this->createMock(originalClassName: CaseLifecycleService::class),
			l10n: $this->l10n()
		);

		$action->validateParameters(['gesture' => ' extend ', 'reason' => 'r']);
		$this->assertSame(expected: [], actual: $action->getGuards());

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage('gesture must be one of suspend, resume, extend');
		$action->validateParameters(['gesture' => 'reopen', 'reason' => 'r']);
	}//end testOnlyTheThreeGesturesAreAccepted()
}//end class
