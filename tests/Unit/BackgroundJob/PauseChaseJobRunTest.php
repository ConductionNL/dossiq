<?php

/**
 * PauseChaseJob: when the daily reminder sweep runs, and what it reports.
 *
 * The service-account test drives the real sweep. This pins the job around
 * it: no sweep without OpenRegister, a failing sweep logged instead of
 * stopping cron, and a report only when reminders actually went out.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\PauseChaseJob;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The reminder sweep runs only with OpenRegister, never takes cron down, and
 * is reported only when it did something.
 *
 * @covers \OCA\Dossiq\BackgroundJob\PauseChaseJob
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class PauseChaseJobRunTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The job over a sweep double.
	 *
	 * @param PauseChaseServiceInterface $chases    The sweep.
	 * @param LoggerInterface            $logger    The logger.
	 * @param list<string>               $installed The installed apps.
	 *
	 * @return PauseChaseJob
	 */
	private function job(PauseChaseServiceInterface $chases, LoggerInterface $logger, array $installed = ['openregister']): PauseChaseJob {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		return new PauseChaseJob(
			time: $this->createMock(ITimeFactory::class),
			chases: $chases,
			appManager: $apps,
			logger: $logger,
			serviceAccount: $this->backgroundAccount(),
		);
	}//end job()

	/**
	 * Without OpenRegister the sweep does not run.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSweepDoesNotRun(): void {
		$chases = $this->createMock(PauseChaseServiceInterface::class);
		$chases->expects($this->never())->method('sweep');

		$this->runJobOnce(job: $this->job(chases: $chases, logger: $this->createMock(LoggerInterface::class), installed: ['files']));
	}//end testWithoutOpenRegisterTheSweepDoesNotRun()

	/**
	 * Reminders that went out are reported with their counts, as the service account.
	 *
	 * @return void
	 */
	public function testRemindersThatWentOutAreReported(): void {
		$chases = $this->createMock(PauseChaseServiceInterface::class);
		$chases->expects($this->once())->method('sweep')->willReturnCallback(
			function (): array {
				$this->assertSame('dossiq-achtergrond', $this->actingUid(), 'the sweep runs as the service account');
				return ['chased' => 2, 'escalated' => 1];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Dossiq pause: reminders went out on suspended terms',
			['app' => 'dossiq', 'chased' => 2, 'escalated' => 1]
		);
		$logger->expects($this->never())->method('error');

		$this->runJobOnce(job: $this->job(chases: $chases, logger: $logger));
	}//end testRemindersThatWentOutAreReported()

	/**
	 * A sweep that did nothing is not reported.
	 *
	 * @return void
	 */
	public function testAnEmptySweepIsNotReported(): void {
		$chases = $this->createMock(PauseChaseServiceInterface::class);
		$chases->method('sweep')->willReturn(['chased' => 0, 'escalated' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		$this->runJobOnce(job: $this->job(chases: $chases, logger: $logger));
	}//end testAnEmptySweepIsNotReported()

	/**
	 * A failing sweep is logged as an error and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$chases = $this->createMock(PauseChaseServiceInterface::class);
		$chases->method('sweep')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq pause: the reminder sweep failed',
			['app' => 'dossiq', 'error' => 'register down']
		);
		$logger->expects($this->never())->method('info');

		$this->runJobOnce(job: $this->job(chases: $chases, logger: $logger));
	}//end testAFailingSweepIsLoggedNotThrown()

	/**
	 * Without a service account nothing is swept and nothing is logged as a sweep failure.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountNothingIsSwept(): void {
		$this->configuredAccount = '';
		$chases = $this->createMock(PauseChaseServiceInterface::class);
		$chases->expects($this->never())->method('sweep');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->runJobOnce(job: $this->job(chases: $chases, logger: $logger));

		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
	}//end testWithoutAnAccountNothingIsSwept()
}//end class
