<?php

/**
 * PlannedFollowUpSweepJob: when it sweeps and what it reports.
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

use OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The hourly sweep retires spent follow-ups only with OpenRegister installed, and logs only when it retired some.
 *
 * @covers \OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob
 * @uses   \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
final class PlannedFollowUpSweepJobTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The flow actions double.
	 *
	 * @var CaseFlowActions&MockObject
	 */
	private CaseFlowActions&MockObject $actions;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->actions = $this->createMock(CaseFlowActions::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The job over the given installed apps.
	 *
	 * @param array<int, string> $installed The installed app ids.
	 *
	 * @return PlannedFollowUpSweepJob
	 */
	private function job(array $installed = ['dossiq', 'openregister']): PlannedFollowUpSweepJob {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		return new PlannedFollowUpSweepJob(
			time: $this->createMock(ITimeFactory::class),
			flowActions: $this->actions,
			appManager: $apps,
			logger: $this->logger,
			serviceAccount: $this->backgroundAccount(),
		);
	}//end job()

	/**
	 * The job is scheduled hourly.
	 *
	 * @return void
	 */
	public function testTheJobRunsHourly(): void {
		$this->assertSame(3600, $this->job()->getInterval());
	}//end testTheJobRunsHourly()

	/**
	 * Without OpenRegister there are no flows to sweep, so nothing is asked of them.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsSwept(): void {
		$this->actions->expects($this->never())->method('retireSpent');
		$this->logger->expects($this->never())->method('info');

		$this->runJobOnce(job: $this->job(installed: ['dossiq', 'openregisterx']));
	}//end testWithoutOpenRegisterNothingIsSwept()

	/**
	 * A sweep that retired nothing stays silent.
	 *
	 * @return void
	 */
	public function testASweepThatRetiredNothingIsNotLogged(): void {
		$this->actions->expects($this->once())->method('retireSpent')->willReturn(0);
		$this->logger->expects($this->never())->method('info');

		$this->runJobOnce(job: $this->job());
	}//end testASweepThatRetiredNothingIsNotLogged()

	/**
	 * A sweep that retired follow-ups says how many, under the app, as the service account.
	 *
	 * @return void
	 */
	public function testASweepThatRetiredSomeLogsTheCount(): void {
		$this->actions->expects($this->once())->method('retireSpent')->willReturnCallback(
			function (): int {
				$this->assertSame('dossiq-achtergrond', $this->actingUid(), 'the sweep runs as the service account');
				return 3;
			}
		);
		$this->logger->expects($this->once())->method('info')->with(
			'Dossiq: retired 3 planned follow-up(s) that were spent',
			['app' => 'dossiq']
		);

		$this->runJobOnce(job: $this->job());

		$this->assertNull($this->acting, 'the service account is signed out again');
	}//end testASweepThatRetiredSomeLogsTheCount()
}//end class
