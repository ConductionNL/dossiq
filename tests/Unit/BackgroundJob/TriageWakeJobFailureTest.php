<?php

/**
 * TriageWakeJob: a failed wake sweep.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\TriageWakeJob;
use OCA\Dossiq\Service\Intake\TriageSleep;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A wake sweep that throws is logged as an error with its reason and does not escape the job.
 *
 * @covers \OCA\Dossiq\BackgroundJob\TriageWakeJob
 * @uses   \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
final class TriageWakeJobFailureTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The failure is logged once as an error, nothing is reported as woken, and the account is signed out.
	 *
	 * @return void
	 */
	public function testAFailedSweepIsLoggedAndContained(): void {
		$sleep = $this->createMock(TriageSleep::class);
		$sleep->expects($this->once())->method('wakeDue')->willThrowException(new RuntimeException('register unreachable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq triage: the wake sweep failed',
			['reason' => 'register unreachable']
		);
		$logger->expects($this->never())->method('info');

		$job = new TriageWakeJob(
			time: $this->createMock(ITimeFactory::class),
			sleep: $sleep,
			logger: $logger,
			serviceAccount: $this->backgroundAccount(),
		);

		$this->runJobOnce(job: $job);

		$this->assertSame(3600, $job->getInterval());
		$this->assertNull($this->acting, 'the service account is signed out again');
	}//end testAFailedSweepIsLoggedAndContained()
}//end class
