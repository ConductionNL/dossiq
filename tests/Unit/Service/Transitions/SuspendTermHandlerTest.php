<?php

/**
 * Unit tests for SuspendTermHandler: a flow step suspends the case term through
 * the same DeadlinePauseService the Termijn card uses, so the case type's rules
 * on suspending apply, and a refusal comes back as the step's error.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Transitions\SuspendTermHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SuspendTermHandlerTest extends TestCase {

	private TermijnService&MockObject $terms;

	private DeadlinePauseService&MockObject $pauses;

	private SuspendTermHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->terms = $this->createMock(TermijnService::class);
		$this->pauses = $this->createMock(DeadlinePauseService::class);
		$this->handler = new SuspendTermHandler($this->terms, $this->pauses, $this->createMock(LoggerInterface::class));
	}//end setUp()

	public function testItSuspendsTheTermForTheDaysAndReasonGiven(): void {
		$this->terms->method('getTermijnInstanceForZaak')->with('case-1')->willReturn(['id' => 'term-1', 'status' => 'running']);
		$this->pauses->expects($this->once())
			->method('registerPauze')
			->with('term-1', 14, 'Aanvulling gevraagd', '', 'aanvulling')
			->willReturn(['endDateCurrent' => '2026-11-15']);

		$result = $this->handler->handle(
			['durationDays' => 14, 'rationale' => 'Aanvulling gevraagd', 'pauseReason' => 'aanvulling'],
			['id' => 'case-1'],
			[]
		);

		$this->assertTrue($result->succeeded);
		$this->assertSame('2026-11-15', $result->data['endDate']);
	}//end testItSuspendsTheTermForTheDaysAndReasonGiven()

	public function testItRefusesWithoutADuration(): void {
		$this->pauses->expects($this->never())->method('registerPauze');
		$result = $this->handler->handle([], ['id' => 'case-1'], []);
		$this->assertFalse($result->succeeded);
		$this->assertSame('duration_required', $result->error);
	}//end testItRefusesWithoutADuration()

	public function testAPausedTermIsNotPausedAgain(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn(['id' => 'term-1', 'status' => 'paused']);
		$this->pauses->expects($this->never())->method('registerPauze');
		$result = $this->handler->handle(['durationDays' => 14], ['id' => 'case-1'], []);
		$this->assertSame('term_already_paused', $result->error);
	}//end testAPausedTermIsNotPausedAgain()

	public function testTheCaseTypesRefusalIsTheStepsError(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn(['id' => 'term-1', 'status' => 'running']);
		$this->pauses->method('registerPauze')->willThrowException(new RefusedException('suspension-not-allowed', 'This case type cannot be suspended.'));
		$result = $this->handler->handle(['durationDays' => 14], ['id' => 'case-1'], []);
		$this->assertFalse($result->succeeded);
		$this->assertSame('suspension_not_allowed', $result->error);
	}//end testTheCaseTypesRefusalIsTheStepsError()
}//end class
