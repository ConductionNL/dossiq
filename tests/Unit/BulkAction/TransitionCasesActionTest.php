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

use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The transition action's outcomes beyond the guard paths: missing case ids,
 * the uuid fallback, automatic actions that did not run, and engine failures.
 *
 * @covers \OCA\Dossiq\BulkAction\TransitionCasesAction
 */
class TransitionCasesActionTest extends TestCase {

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
	 * @param StatusTransitionService $engine     The engine double.
	 * @param ObjectEntity            $object     The case.
	 * @param array<string, mixed>    $parameters The job parameters.
	 * @param bool                    $commit     Commit or rehearse.
	 *
	 * @return \OCA\OpenRegister\BulkAction\BulkActionResult The outcome.
	 */
	private function applyTo(StatusTransitionService $engine, ObjectEntity $object, array $parameters, bool $commit) {
		return (new TransitionCasesAction(engine: $engine, l10n: $this->l10n()))->apply(
			step: new BulkStep(object: $object, parameters: $parameters, commit: $commit, actor: null)
		);
	}//end applyTo()

	/**
	 * A case without a uuid or id is skipped before the engine is asked.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsSkipped(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->expects($this->never())->method('execute');
		$engine->expects($this->never())->method('getAvailableTransitions');

		$result = $this->applyTo($engine, $this->caseObject(uuid: '  ', data: ['id' => ' ']), ['transitionId' => 't'], true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_SKIPPED, actual: $result->getOutcome());
		$this->assertSame(expected: 'no_case_id', actual: $result->getReason());
	}//end testACaseWithoutAnIdIsSkipped()

	/**
	 * Without a uuid the case payload's trimmed id is used, and the trimmed
	 * transition id and a null comment reach the engine.
	 *
	 * @return void
	 */
	public function testThePayloadIdIsUsedWhenTheUuidIsEmpty(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->expects($this->once())
			->method('execute')
			->with('case-9', 'to-decided', null)
			->willReturn(['failedActions' => []]);

		$result = $this->applyTo($engine, $this->caseObject(uuid: '', data: ['id' => ' case-9 ']), ['transitionId' => ' to-decided '], true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertNull($result->getReason());
	}//end testThePayloadIdIsUsedWhenTheUuidIsEmpty()

	/**
	 * A move whose automatic actions failed is still applied, carrying the
	 * named action types in its reason.
	 *
	 * @return void
	 */
	public function testMissedAutomaticActionsAreNamedInTheReason(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willReturn(
			['failedActions' => [['type' => 'sendEmail'], ['type' => ' '], ['type' => 'createTask']]]
		);

		$result = $this->applyTo($engine, $this->caseObject(uuid: 'case-1'), ['transitionId' => 't'], true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertSame(
			expected: 'moved, but these automatic actions did not run: sendEmail, createTask',
			actual: $result->getReason()
		);
	}//end testMissedAutomaticActionsAreNamedInTheReason()

	/**
	 * Failed actions without a type still say that something did not run.
	 *
	 * @return void
	 */
	public function testMissedActionsWithoutATypeGetTheGenericReason(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willReturn(['failedActions' => [['message' => 'boom']]]);

		$result = $this->applyTo($engine, $this->caseObject(uuid: 'case-1'), ['transitionId' => 't'], true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertSame(expected: 'moved, but an automatic action did not run', actual: $result->getReason());
	}//end testMissedActionsWithoutATypeGetTheGenericReason()

	/**
	 * An engine that throws on the commit fails that member with its message.
	 *
	 * @return void
	 */
	public function testACommitThatThrowsIsFailed(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(new RuntimeException('database gone'));

		$result = $this->applyTo($engine, $this->caseObject(uuid: 'case-1'), ['transitionId' => 't'], true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
		$this->assertSame(expected: 'database gone', actual: $result->getReason());
	}//end testACommitThatThrowsIsFailed()

	/**
	 * A guard failure without any message is refused as guard_failed.
	 *
	 * @return void
	 */
	public function testAGuardFailureWithoutAMessageIsRefusedWithTheGenericRule(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('execute')->willThrowException(
			new GuardFailedException(failedGuards: [['message' => ' '], ['type' => 'document']])
		);

		$result = $this->applyTo($engine, $this->caseObject(uuid: 'case-1'), ['transitionId' => 't'], true);

		$this->assertSame(expected: BulkJobMember::OUTCOME_REFUSED, actual: $result->getOutcome());
		$this->assertSame(expected: 'guard_failed', actual: $result->getReason());
	}//end testAGuardFailureWithoutAMessageIsRefusedWithTheGenericRule()

	/**
	 * A rehearsal of an offered transition whose guards pass is applied
	 * without executing anything.
	 *
	 * @return void
	 */
	public function testARehearsedTransitionWhoseGuardsPassIsApplied(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->expects($this->once())
			->method('getAvailableTransitions')
			->with('case-1')
			->willReturn(['transitions' => [['id' => 'other', 'guardsPassed' => false], ['id' => 'to-decided', 'guardsPassed' => true]]]);
		$engine->expects($this->never())->method('execute');

		$result = $this->applyTo($engine, $this->caseObject(uuid: 'case-1'), ['transitionId' => 'to-decided'], false);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
	}//end testARehearsedTransitionWhoseGuardsPassIsApplied()

	/**
	 * A rehearsal that cannot read the available transitions is failed.
	 *
	 * @return void
	 */
	public function testARehearsalThatCannotReadTheTransitionsIsFailed(): void {
		$engine = $this->createMock(originalClassName: StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willThrowException(new RuntimeException('case not found'));

		$result = $this->applyTo($engine, $this->caseObject(uuid: 'case-1'), ['transitionId' => 't'], false);

		$this->assertSame(expected: BulkJobMember::OUTCOME_FAILED, actual: $result->getOutcome());
		$this->assertSame(expected: 'case not found', actual: $result->getReason());
	}//end testARehearsalThatCannotReadTheTransitionsIsFailed()

	/**
	 * The action describes itself in the user's language and declares no guards.
	 *
	 * @return void
	 */
	public function testTheActionDescribesItself(): void {
		$action = new TransitionCasesAction(
			engine: $this->createMock(originalClassName: StatusTransitionService::class),
			l10n: $this->l10n()
		);

		$this->assertSame(expected: 'dossiq:transition-cases', actual: $action->getId());
		$this->assertSame(expected: 'Move to another status', actual: $action->getLabel());
		$this->assertStringContainsString(needle: 'transition engine', haystack: $action->getDescription());
		$this->assertFalse($action->requiresJustification());
		$this->assertSame(expected: [], actual: $action->getGuards());
		$action->validateParameters(['transitionId' => 'to-decided']);
	}//end testTheActionDescribesItself()
}//end class
