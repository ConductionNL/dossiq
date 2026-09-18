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
use OCA\Dossiq\BulkAction\MoveCaseTypeVersionAction;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\CaseAssigneeWriter;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What each of dossiq's four bulk actions does to ONE case.
 *
 * The loop is not here and is not tested here: it is OpenRegister's. What is
 * dossiq's is the per-case outcome, and the distinction between the three
 * outcomes is the whole feature. Applied is a write. Skipped is "the act does
 * not apply here, and here is why" — the row a handler reads off the skip list.
 * Refused is "the rule said no". Collapsing refused into skipped would hide a
 * guard failure inside a business outcome (D-2).
 *
 * The rehearsal is asserted beside every commit, because the two run the same
 * method with `$commit` flipped and a rehearsal that wrote would be invisible
 * in a green suite.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class CaseBulkActionsTest extends TestCase {

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
	 * @return IL10N|\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function l10n() {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

	/**
	 * A transition the case cannot make is SKIPPED with the reason, and the
	 * engine is never asked to execute it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testARehearsedTransitionThatIsNotOfferedIsSkipped(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willReturn(['transitions' => [['id' => 'to-closed', 'guardsPassed' => true]]]);
		$engine->expects($this->never())->method('execute');

		$result = (new TransitionCasesAction(engine: $engine, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['transitionId' => 'to-decided'],
			commit: false,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: 'transition_not_available', actual: $result->getReason());
	}//end testARehearsedTransitionThatIsNotOfferedIsSkipped()

	/**
	 * A guard that fails is REFUSED with the guard's own message, not skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testARehearsedTransitionWhoseGuardFailsIsRefusedWithTheGuardMessage(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willReturn([
			'transitions' => [
				[
					'id' => 'to-decided',
					'guardsPassed' => false,
					'failedGuards' => [['message' => 'A decision document is required']],
				],
			],
		]);

		$result = (new TransitionCasesAction(engine: $engine, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['transitionId' => 'to-decided'],
			commit: false,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_REFUSED, actual: $result->getOutcome());
		$this->assertSame(expected: 'A decision document is required', actual: $result->getReason());
	}//end testARehearsedTransitionWhoseGuardFailsIsRefusedWithTheGuardMessage()

	/**
	 * A committed transition goes through the engine, which stays the only
	 * write path for `case.status`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testACommittedTransitionGoesThroughTheEngine(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->expects($this->once())
			->method('execute')
			->with('case-1', 'to-decided', 'Handled in bulk')
			->willReturn(['statusRecord' => ['id' => 'sr-1']]);

		$result = (new TransitionCasesAction(engine: $engine, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['transitionId' => 'to-decided', 'comment' => 'Handled in bulk'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testACommittedTransitionGoesThroughTheEngine()

	/**
	 * A guard failure on the commit is refused, and does not fail the member:
	 * a refusal is a rule saying no, a failure is the write throwing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testACommittedTransitionRefusedByAGuardIsRefusedNotFailed(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(
			new GuardFailedException(failedGuards: [['message' => 'The term has not expired']])
		);

		$result = (new TransitionCasesAction(engine: $engine, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['transitionId' => 'to-decided'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_REFUSED, actual: $result->getOutcome());
		$this->assertSame(expected: 'The term has not expired', actual: $result->getReason());
	}//end testACommittedTransitionRefusedByAGuardIsRefusedNotFailed()

	/**
	 * A transition needs a transition id and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testATransitionWithoutATransitionIdIsRefusedBeforeAJobExists(): void {
		$this->expectException(exception: InvalidArgumentException::class);

		(new TransitionCasesAction(
			engine: $this->createMock(originalClassName: StatusTransitionService::class),
			l10n: $this->l10n(),
		))->validateParameters(['comment' => 'no id here']);
	}//end testATransitionWithoutATransitionIdIsRefusedBeforeAJobExists()

	/**
	 * A case whose type forbids suspension is skipped with the code the case
	 * page uses, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testALifecycleGestureTheCaseTypeForbidsIsSkipped(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canSuspend' => false, 'suspended' => false, 'isFinalStatus' => false]);
		$lifecycle->expects($this->never())->method('suspend');

		$result = (new LifecycleCasesAction(lifecycle: $lifecycle, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['gesture' => 'suspend', 'reason' => 'Awaiting documents', 'days' => 14],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: 'suspension_not_allowed', actual: $result->getReason());
	}//end testALifecycleGestureTheCaseTypeForbidsIsSkipped()

	/**
	 * A permitted gesture writes, carrying the reason onto the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAPermittedLifecycleGestureWritesWithItsReason(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canSuspend' => true, 'suspended' => false, 'isFinalStatus' => false]);
		$lifecycle->expects($this->once())
			->method('suspend')
			->with('case-1', 'Awaiting documents', 14)
			->willReturn([]);

		$result = (new LifecycleCasesAction(lifecycle: $lifecycle, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['gesture' => 'suspend', 'reason' => 'Awaiting documents', 'days' => 14],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testAPermittedLifecycleGestureWritesWithItsReason()

	/**
	 * A rehearsed gesture writes nothing, which is what makes the dry run a
	 * rehearsal rather than a promise.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testARehearsedLifecycleGestureWritesNothing(): void {
		$lifecycle = $this->createMock(originalClassName: CaseLifecycleService::class);
		$lifecycle->method('state')->willReturn(['canSuspend' => true, 'suspended' => false, 'isFinalStatus' => false]);
		$lifecycle->expects($this->never())->method('suspend');

		$result = (new LifecycleCasesAction(lifecycle: $lifecycle, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1'),
			parameters: ['gesture' => 'suspend', 'reason' => 'Awaiting documents', 'days' => 14],
			commit: false,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testARehearsedLifecycleGestureWritesNothing()

	/**
	 * A statutory gesture in bulk needs a written reason, and the action says
	 * so rather than leaving it to a caller to remember.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testALifecycleGestureDeclaresThatItNeedsAJustification(): void {
		$action = new LifecycleCasesAction(
			lifecycle: $this->createMock(originalClassName: CaseLifecycleService::class),
			l10n: $this->l10n(),
		);

		$this->assertTrue(condition: $action->requiresJustification());

		$this->expectException(exception: InvalidArgumentException::class);
		$action->validateParameters(['gesture' => 'suspend', 'reason' => '   ']);
	}//end testALifecycleGestureDeclaresThatItNeedsAJustification()

	/**
	 * A redistribution needs a written reason. This is the clause the change
	 * exists for: reassigning four hundred cases with nothing recorded about
	 * why is an audit finding waiting to happen (D-3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testARedistributionWithoutAWrittenReasonIsRefused(): void {
		$action = new ReassignCasesAction(
			writer: $this->createMock(originalClassName: CaseAssigneeWriter::class),
			l10n: $this->l10n(),
		);

		$this->assertTrue(condition: $action->requiresJustification());

		$this->expectException(exception: InvalidArgumentException::class);
		$action->validateParameters(['toUser' => 'handler-2', 'reason' => '']);
	}//end testARedistributionWithoutAWrittenReasonIsRefused()

	/**
	 * A case already held by the receiving handler is skipped, not rewritten.
	 *
	 * Rewriting it would stamp an audit entry saying a case moved from
	 * somebody to themselves, which is a false record rather than a no-op.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testACaseAlreadyHeldByTheReceiverIsSkipped(): void {
		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->expects($this->never())->method('reassignOne');

		$result = (new ReassignCasesAction(writer: $writer, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1', data: ['assignee' => 'handler-2']),
			parameters: ['toUser' => 'handler-2', 'reason' => 'Team reorganised'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: 'already_assigned', actual: $result->getReason());
	}//end testACaseAlreadyHeldByTheReceiverIsSkipped()

	/**
	 * A write that answers false is FAILED, so the member says so rather than
	 * reporting a move that did not happen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testARedistributionWriteThatDoesNotLandIsFailed(): void {
		$writer = $this->createMock(originalClassName: CaseAssigneeWriter::class);
		$writer->method('newBatchId')->willReturn('batch-1');
		$writer->method('reassignOne')->willReturn(false);

		$result = (new ReassignCasesAction(writer: $writer, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1', data: ['assignee' => 'handler-1']),
			parameters: ['toUser' => 'handler-2', 'reason' => 'Team reorganised'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
	}//end testARedistributionWriteThatDoesNotLandIsFailed()

	/**
	 * The attribute write declares the homogeneity guard. Without it the
	 * engine would accept a selection spanning two schema versions and write a
	 * value into a field that means two things (D-4).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheAttributeWriteDeclaresTheHomogeneityGuard(): void {
		$action = new SetCaseAttributeAction(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			l10n: $this->l10n(),
		);

		$this->assertSame(expected: ['homogeneity'], actual: $action->getGuards());
	}//end testTheAttributeWriteDeclaresTheHomogeneityGuard()

	/**
	 * A field with its own write path is refused, so a bulk act cannot route
	 * around the transition engine or the reassignment audit entry.
	 *
	 * @param string $property The reserved property.
	 *
	 * @return void
	 *
	 * @dataProvider reservedProperties
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAFieldWithItsOwnWritePathIsNotSetInBulk(string $property): void {
		$action = new SetCaseAttributeAction(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			l10n: $this->l10n(),
		);

		$this->expectException(exception: InvalidArgumentException::class);
		$action->validateParameters(['property' => $property, 'value' => 'anything']);
	}//end testAFieldWithItsOwnWritePathIsNotSetInBulk()

	/**
	 * The fields a bulk attribute write must not touch.
	 *
	 * @return array<int, array<int, string>> The cases.
	 */
	public static function reservedProperties(): array {
		return [['status'], ['assignee'], ['activity'], ['statusHistory'], ['caseType'], ['id']];
	}//end reservedProperties()

	/**
	 * A case already carrying the value is skipped rather than rewritten.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testACaseAlreadyCarryingTheValueIsSkipped(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$result = (new SetCaseAttributeAction(settingsService: $settings, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1', data: ['confidentiality' => 'openbaar']),
			parameters: ['property' => 'confidentiality', 'value' => 'openbaar'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: 'already_set', actual: $result->getReason());
	}//end testACaseAlreadyCarryingTheValueIsSkipped()

	/**
	 * Without OpenRegister the write fails as that member rather than throwing
	 * out of the job, so the other members still get their outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAnAttributeWriteWithoutOpenRegisterFailsThatMemberOnly(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('3');

		$result = (new SetCaseAttributeAction(settingsService: $settings, l10n: $this->l10n()))->apply(
			object: $this->caseObject(uuid: 'case-1', data: ['confidentiality' => 'vertrouwelijk']),
			parameters: ['property' => 'confidentiality', 'value' => 'openbaar'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
	}//end testAnAttributeWriteWithoutOpenRegisterFailsThatMemberOnly()

	/**
	 * The four actions carry four distinct ids, all in `app:action` form under
	 * dossiq's own prefix.
	 *
	 * An id collision would be refused by the registry at registration, which
	 * on a fail-soft listener means one action silently missing from the
	 * catalogue rather than an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheFiveActionsCarryFiveDistinctDossiqIds(): void {
		$ids = [
			TransitionCasesAction::ID,
			LifecycleCasesAction::ID,
			ReassignCasesAction::ID,
			SetCaseAttributeAction::ID,
			MoveCaseTypeVersionAction::ID,
		];

		$this->assertSame(expected: $ids, actual: array_values(array_unique($ids)));
		foreach ($ids as $id) {
			$this->assertStringStartsWith(prefix: 'dossiq:', string: $id);
		}
	}//end testTheFiveActionsCarryFiveDistinctDossiqIds()

	/**
	 * 🔴 The version move REHEARSES through the same service the dialog asks.
	 *
	 * A bulk gesture that computed its own status mapping would be a second
	 * answer to the question the per-case dialog already asks, and the two would
	 * drift the first time either side changed: the dialog would refuse a case
	 * the job had already moved, and nothing on either side would say which one
	 * was right.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function testARehearsedVersionMoveAsksThePreviewAndWritesNothing(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->expects($this->once())
			->method('preview')
			->with(caseId: 'case-1', targetCaseTypeId: 'ct-2')
			->willReturn(['canMove' => true, 'refusals' => []]);
		$move->expects($this->never())->method('move');

		$result = (new MoveCaseTypeVersionAction(move: $move, l10n: $this->l10n()))->apply(
			$this->caseObject('case-1'),
			['target' => 'ct-2', 'reason' => 'Nieuwe regels'],
			false,
		);

		$this->assertSame(expected: 'applied', actual: $result->getOutcome());
	}//end testARehearsedVersionMoveAsksThePreviewAndWritesNothing()

	/**
	 * 🔴 A case the target version cannot hold is REFUSED with the sentence.
	 *
	 * Not failed, and not skipped. The sentence names the status that does not
	 * exist in the target version, and the name is the only thing an operator
	 * looking at a hundred-row report can act on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function testACaseWithNowhereToLandIsRefusedWithTheReason(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->method('preview')->willReturn(
			[
				'canMove' => false,
				'refusals' => ['The other version has no status called "Ingetrokken", so this case has nowhere to land.'],
			]
		);

		$result = (new MoveCaseTypeVersionAction(move: $move, l10n: $this->l10n()))->apply(
			$this->caseObject('case-1'),
			['target' => 'ct-2', 'reason' => 'Nieuwe regels'],
			false,
		);

		$this->assertSame(expected: 'refused', actual: $result->getOutcome());
		$this->assertStringContainsString(needle: 'Ingetrokken', haystack: (string)$result->getReason());
	}//end testACaseWithNowhereToLandIsRefusedWithTheReason()

	/**
	 * A committed move goes through the service, with the reason and the actor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function testACommittedVersionMoveGoesThroughTheService(): void {
		$move = $this->createMock(originalClassName: CaseVersionMove::class);
		$move->expects($this->once())
			->method('move')
			->with(
				caseId: 'case-1',
				targetCaseTypeId: 'ct-2',
				reason: 'Nieuwe regels',
				actorUid: '',
			)
			->willReturn(['moved' => true]);

		$result = (new MoveCaseTypeVersionAction(move: $move, l10n: $this->l10n()))->apply(
			$this->caseObject('case-1'),
			['target' => 'ct-2', 'reason' => 'Nieuwe regels'],
			true,
		);

		$this->assertSame(expected: 'applied', actual: $result->getOutcome());
	}//end testACommittedVersionMoveGoesThroughTheService()

	/**
	 * The move refuses a selection that names no target and no reason.
	 *
	 * The reason is a PARAMETER and not only the job's justification, so an act
	 * handed over by a caller that is not dossiq's own endpoint is refused just
	 * the same.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function testTheVersionMoveRequiresATargetAndAReason(): void {
		$action = new MoveCaseTypeVersionAction(
			move: $this->createMock(originalClassName: CaseVersionMove::class),
			l10n: $this->l10n(),
		);

		$this->assertTrue($action->requiresJustification());

		try {
			$action->validateParameters(['reason' => 'Nieuwe regels']);
			$this->fail('a move with no target was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString(needle: 'target', haystack: $e->getMessage());
		}

		try {
			$action->validateParameters(['target' => 'ct-2']);
			$this->fail('a move with no reason was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString(needle: 'reason', haystack: $e->getMessage());
		}
	}//end testTheVersionMoveRequiresATargetAndAReason()
}//end class
