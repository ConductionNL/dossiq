<?php

/**
 * One run of linking in-flight contract decisions: its outcome counts and how a failed listing is reported.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\InFlightDecisionLinkRun;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Dossiq\Repair\InFlightDecisionLinkRun
 */
class InFlightDecisionLinkRunTest extends TestCase {

	/**
	 * The run keeps the object service and output it was given.
	 *
	 * @return void
	 */
	public function testTheRunKeepsItsCollaborators(): void {
		$objects = new stdClass();
		$output  = $this->createMock(originalClassName: IOutput::class);
		$run     = new InFlightDecisionLinkRun(
			objectService: $objects,
			output: $output,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		self::assertSame($objects, $run->objectService);
		self::assertSame($output, $run->output);
		self::assertSame([], $run->counts);
	}//end testTheRunKeepsItsCollaborators()

	/**
	 * Each outcome is counted on its own, starting from one.
	 *
	 * @return void
	 */
	public function testOutcomesAreCountedPerOutcome(): void {
		$run = new InFlightDecisionLinkRun(
			objectService: new stdClass(),
			output: $this->createMock(originalClassName: IOutput::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$run->count(outcome: 'linked');
		$run->count(outcome: 'skipped');
		$run->count(outcome: 'linked');

		self::assertSame(['linked' => 2, 'skipped' => 1], $run->counts);
	}//end testOutcomesAreCountedPerOutcome()

	/**
	 * A case type whose cases cannot be listed is warned about on the output and in the log.
	 *
	 * @return void
	 */
	public function testAFailedListIsWarnedOnTheOutputAndLogged(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with('Could not list cases for type contract: register offline');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'LinkInFlightContractDecisionsRepair: list failed',
				['caseTypeSlug' => 'contract', 'error' => 'register offline']
			);

		$run = new InFlightDecisionLinkRun(objectService: new stdClass(), output: $output, logger: $logger);
		$run->listFailed(caseTypeSlug: 'contract', error: new RuntimeException('register offline'));

		self::assertSame([], $run->counts);
	}//end testAFailedListIsWarnedOnTheOutputAndLogged()
}//end class
