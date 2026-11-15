<?php

/**
 * A concluded decision wakes the run that waits on that decision, and no other.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\WaitingRunResumer;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\WaitingRunResumer
 */
class WaitingRunResumerTest extends TestCase {

	/**
	 * A run suspended on a decision.
	 *
	 * @param string $uuid        The run uuid.
	 * @param mixed  $resumeState The resume slots the run carries.
	 *
	 * @return FlowRun
	 */
	private function suspended(string $uuid, mixed $resumeState): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setContext(['resumeState' => $resumeState]);

		return $run;
	}//end suspended()

	/**
	 * A mapper that reports the given suspended runs.
	 *
	 * @param array<int, FlowRun> $runs The runs.
	 *
	 * @return FlowRunMapper&MockObject
	 */
	private function mapper(array $runs): FlowRunMapper&MockObject {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findSuspendedBySubject')->willReturn($runs);

		return $mapper;
	}//end mapper()

	/**
	 * Only the run whose slot records the decision is signalled, with the outcome.
	 *
	 * @return void
	 */
	public function testOnlyTheRunWaitingOnThisDecisionIsSignalled(): void {
		$runner = $this->createMock(FlowRunService::class);
		$runner->expects(self::once())->method('signal')->with(
			self::callback(static fn (FlowRun $run): bool => $run->getUuid() === 'run-2'),
			['decision' => 'approved', 'decisionRef' => 'dec-2', 'caseId' => 'case-1']
		);
		$runs = [
			$this->suspended(uuid: 'run-0', resumeState: 'not a list'),
			$this->suspended(uuid: 'run-1', resumeState: ['node-a' => ['decisionRef' => 'dec-1'], 'node-b' => 'not a slot']),
			$this->suspended(uuid: 'run-2', resumeState: ['node-a' => ['decisionRef' => ' dec-2 ']]),
		];

		$resumer = new WaitingRunResumer(logger: $this->createMock(LoggerInterface::class), runs: $this->mapper(runs: $runs), runner: $runner);
		$resumer->resumeWaitingRun(caseId: 'case-1', decisionRef: 'dec-2', status: 'approved');
	}//end testOnlyTheRunWaitingOnThisDecisionIsSignalled()

	/**
	 * Without the flow collaborators or a decision reference, nothing is looked up.
	 *
	 * @return void
	 */
	public function testNothingIsResumedWithoutCollaboratorsOrAReference(): void {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->expects(self::never())->method('findSuspendedBySubject');
		$logger = $this->createMock(LoggerInterface::class);

		(new WaitingRunResumer(logger: $logger))->resumeWaitingRun(caseId: 'case-1', decisionRef: 'dec-1', status: 'approved');
		(new WaitingRunResumer(logger: $logger, runs: $mapper, runner: $this->createMock(FlowRunService::class)))
			->resumeWaitingRun(caseId: 'case-1', decisionRef: ' ', status: 'approved');
	}//end testNothingIsResumedWithoutCollaboratorsOrAReference()

	/**
	 * A failed lookup or a failed signal is logged and never thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$broken = $this->createMock(FlowRunMapper::class);
		$broken->method('findSuspendedBySubject')->willThrowException(new RuntimeException('down'));
		$runner = $this->createMock(FlowRunService::class);
		$runner->method('signal')->willThrowException(new RuntimeException('refused'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$logger->expects(self::once())->method('error');

		(new WaitingRunResumer(logger: $logger, runs: $broken, runner: $runner))->resumeWaitingRun(caseId: 'case-1', decisionRef: 'dec-1', status: 'approved');

		$waiting = $this->mapper(runs: [$this->suspended(uuid: 'run-1', resumeState: ['node-a' => ['decisionRef' => 'dec-1']])]);
		(new WaitingRunResumer(logger: $logger, runs: $waiting, runner: $runner))->resumeWaitingRun(caseId: 'case-1', decisionRef: 'dec-1', status: 'approved');
	}//end testAFailureIsLoggedNotThrown()
}//end class
