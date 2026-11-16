<?php

/**
 * A concluded decision no suspended run waits on wakes nothing.
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
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\WaitingRunResumer;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Flow\WaitingRunResumer
 */
class WaitingRunResumerUnmatchedTest extends TestCase {

	/**
	 * Runs on the case that wait on other decisions, or carry no readable context, stay suspended.
	 *
	 * @return void
	 */
	public function testNoRunIsSignalledWhenNoneWaitsOnTheDecision(): void {
		$other = new FlowRun();
		$other->setUuid('run-1');
		$other->setContext(['resumeState' => ['node-a' => ['decisionRef' => 'dec-1']]]);

		$noContext = new FlowRun();
		$noContext->setUuid('run-2');

		$unreadable = new class {
			/**
			 * A context that is not an array.
			 *
			 * @return string
			 */
			public function getContext(): string {
				return 'not a context';
			}//end getContext()
		};

		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->expects(self::once())->method('findSuspendedBySubject')
			->with('case-1')
			->willReturn([$other, $noContext, $unreadable]);
		$runner = $this->createMock(FlowRunService::class);
		$runner->expects(self::never())->method('signal');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::never())->method('error');

		(new WaitingRunResumer(logger: $logger, runs: $mapper, runner: $runner))
			->resumeWaitingRun(caseId: 'case-1', decisionRef: 'dec-2', status: 'rejected');
	}//end testNoRunIsSignalledWhenNoneWaitsOnTheDecision()

	/**
	 * A case with no suspended runs signals nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testACaseWithoutSuspendedRunsSignalsNothing(): void {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->expects(self::once())->method('findSuspendedBySubject')->willReturn([]);
		$runner = $this->createMock(FlowRunService::class);
		$runner->expects(self::never())->method('signal');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		(new WaitingRunResumer(logger: $logger, runs: $mapper, runner: $runner))
			->resumeWaitingRun(caseId: 'case-1', decisionRef: 'dec-1', status: 'approved');
	}//end testACaseWithoutSuspendedRunsSignalsNothing()
}//end class
