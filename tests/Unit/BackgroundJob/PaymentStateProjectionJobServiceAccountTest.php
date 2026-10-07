<?php

/**
 * PaymentStateProjectionJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the projected payment state as
 * Anonymous and the case list filtered on a word that never moved. This
 * drives the REAL job into a register that refuses a write from nobody. The
 * shillinq read is the one double: it answers that the case is now paid.
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

use OCA\Dossiq\BackgroundJob\PaymentStateProjectionJob;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The payment projection runs as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\PaymentStateProjectionJob
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class PaymentStateProjectionJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job reads and writes.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How often shillinq was asked.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Seed one open case whose payment is outstanding.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reads = 0;
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: 'case',
			id: 'case-1',
			row: [
				'title' => 'Omgevingsvergunning',
				'isFinalStatus' => false,
				'isDraft' => false,
				'paymentState' => CasePaymentState::OUTSTANDING,
			]
		);
	}//end setUp()

	/**
	 * One run stores the changed state as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		$row = $this->register->row(schema: 'case', id: 'case-1');
		$this->assertSame(CasePaymentState::PAID, $row['paymentState']);
		$this->assertSame('2026-10-07T10:00:00+00:00', $row['paymentStateCheckedAt']);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is read, written or even attempted.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame(0, $this->reads, 'shillinq was asked although nothing could be written.');
		$this->assertSame(
			CasePaymentState::OUTSTANDING,
			$this->register->row(schema: 'case', id: 'case-1')['paymentState']
		);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job, with the shillinq read doubled.
	 *
	 * @return object The job.
	 */
	private function job(): object {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);

		$payments = $this->createMock(CasePaymentReader::class);
		$payments->method('stateOf')->willReturnCallback(
			function (): array {
				$this->reads++;
				return [
					'paymentState' => CasePaymentState::PAID,
					'paymentStateCheckedAt' => '2026-10-07T10:00:00+00:00',
				];
			}
		);

		return $this->buildWith(
			PaymentStateProjectionJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'payments' => $payments,
				'settingsService' => $settings,
				'appManager' => $apps,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
