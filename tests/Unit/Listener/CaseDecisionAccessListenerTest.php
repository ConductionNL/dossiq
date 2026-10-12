<?php

/**
 * Tests for CaseDecisionAccessListener.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseDecisionAccessListener;
use OCA\Dossiq\Service\Access\CaseDecisionAccess;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * REQ-CRA-006 and REQ-CRA-007.
 *
 * @covers \OCA\Dossiq\Listener\CaseDecisionAccessListener
 */
class CaseDecisionAccessListenerTest extends TestCase {

	private const DOCUMENT = 'd0c00000-0000-4000-8000-000000000001';

	public function testARefusingCaseRefusesWhatTheSchemaGroupAllowed(): void {
		$event = $this->event(allowed: true);

		$this->listener(verdict: false)->handle($event);

		self::assertFalse($event->isAllowed());
		self::assertSame(CaseDecisionAccess::REFUSAL, $event->getReason());
	}//end testARefusingCaseRefusesWhatTheSchemaGroupAllowed()

	public function testASupervisorOfTheCaseIsAllowed(): void {
		$event = $this->event(allowed: false);

		$this->listener(verdict: true)->handle($event);

		self::assertTrue($event->isAllowed());
		self::assertNull($event->getReason());
	}//end testASupervisorOfTheCaseIsAllowed()

	public function testNoGuardedCaseLeavesTheAnswerAlone(): void {
		foreach ([true, false] as $before) {
			$event = $this->event(allowed: $before);
			$this->listener(verdict: null)->handle($event);
			self::assertSame($before, $event->isAllowed());
			self::assertNull($event->getReason());
		}
	}//end testNoGuardedCaseLeavesTheAnswerAlone()

	public function testASubjectWithoutAnObjectIsNotAsked(): void {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->expects(self::never())->method('mayChange');
		$event = new DecisionChangeRequestedEvent(new stdClass(), 'p.bakker', new stdClass(), ['objectUuid' => ''], true);

		(new CaseDecisionAccessListener(access: $access, logger: new NullLogger()))->handle($event);

		self::assertTrue($event->isAllowed());
	}//end testASubjectWithoutAnObjectIsNotAsked()

	public function testAFailedReadRefuses(): void {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->method('mayChange')->willThrowException(new RuntimeException('register down'));
		$event = $this->event(allowed: true);

		(new CaseDecisionAccessListener(access: $access, logger: new NullLogger()))->handle($event);

		self::assertFalse($event->isAllowed());
	}//end testAFailedReadRefuses()

	public function testTheActorAndTheObjectAreWhatIsAsked(): void {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->expects(self::once())->method('mayChange')->with(self::DOCUMENT, 'p.bakker')->willReturn(null);

		(new CaseDecisionAccessListener(access: $access, logger: new NullLogger()))->handle($this->event(allowed: false));
	}//end testTheActorAndTheObjectAreWhatIsAsked()

	public function testAnotherEventIsIgnored(): void {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->expects(self::never())->method('mayChange');

		(new CaseDecisionAccessListener(access: $access, logger: new NullLogger()))->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A supervisor of the case is shown the hidden decision (REQ-CRA-007).
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-supervisor-of-the-case-sees-a-hidden-decision-on-its-file-req-cra-007
	 */
	public function testASupervisorOfTheCaseSeesTheHiddenDecision(): void {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->expects(self::once())->method('maySee')->with(self::DOCUMENT, 'coordinator')->willReturn(true);
		$event = $this->visibilityEvent(actorId: 'coordinator');

		(new CaseDecisionAccessListener(access: $access, logger: new NullLogger()))->handle($event);

		self::assertTrue($event->isAllowed());
	}//end testASupervisorOfTheCaseSeesTheHiddenDecision()

	/**
	 * Anyone else, a subject without an object, and a failed read leave it hidden.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-supervisor-of-the-case-sees-a-hidden-decision-on-its-file-req-cra-007
	 */
	public function testAnyoneElseOrAFailedReadLeavesItHidden(): void {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->method('maySee')->willReturn(false);
		$event = $this->visibilityEvent(actorId: 'p.bakker');
		(new CaseDecisionAccessListener(access: $access, logger: new NullLogger()))->handle($event);
		self::assertFalse($event->isAllowed(), 'not a supervisor of the case');

		$failing = $this->createMock(CaseDecisionAccess::class);
		$failing->method('maySee')->willThrowException(new RuntimeException('register down'));
		$event = $this->visibilityEvent(actorId: 'coordinator');
		(new CaseDecisionAccessListener(access: $failing, logger: new NullLogger()))->handle($event);
		self::assertFalse($event->isAllowed(), 'a failed read');

		$unasked = $this->createMock(CaseDecisionAccess::class);
		$unasked->expects(self::never())->method('maySee');
		$event = new DecisionVisibilityRequestedEvent(null, 'coordinator', new stdClass(), ['objectUuid' => '']);
		(new CaseDecisionAccessListener(access: $unasked, logger: new NullLogger()))->handle($event);
		self::assertFalse($event->isAllowed(), 'no object');
	}//end testAnyoneElseOrAFailedReadLeavesItHidden()

	private function visibilityEvent(string $actorId): DecisionVisibilityRequestedEvent {
		return new DecisionVisibilityRequestedEvent(
			new stdClass(),
			$actorId,
			new stdClass(),
			['subjectType' => 'entity-relation', 'subjectId' => '12', 'objectUuid' => self::DOCUMENT]
		);
	}//end visibilityEvent()

	private function listener(?bool $verdict): CaseDecisionAccessListener {
		$access = $this->createMock(CaseDecisionAccess::class);
		$access->method('mayChange')->willReturn($verdict);

		return new CaseDecisionAccessListener(access: $access, logger: new NullLogger());
	}//end listener()

	private function event(bool $allowed): DecisionChangeRequestedEvent {
		return new DecisionChangeRequestedEvent(
			new stdClass(),
			'p.bakker',
			new stdClass(),
			['subjectType' => 'entity-relation', 'subjectId' => '12', 'objectUuid' => self::DOCUMENT],
			$allowed
		);
	}//end event()
}//end class
