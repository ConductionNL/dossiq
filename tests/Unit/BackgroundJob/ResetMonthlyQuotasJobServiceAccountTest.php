<?php

/**
 * ResetMonthlyQuotasJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the reset of every due quota as
 * Anonymous and a `block` quota kept refusing after its window had passed.
 * This drives the REAL job and the REAL TenantQuotaService into a register
 * that refuses a write from nobody and records who wrote.
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

use OCA\Dossiq\BackgroundJob\ResetMonthlyQuotasJob;
use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The quota reset runs as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\ResetMonthlyQuotasJob
 * @uses \OCA\Dossiq\Service\TenantQuotaService
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class ResetMonthlyQuotasJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job reads and writes.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Seed one due quota.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: 'tenantQuota',
			id: 'quota-due',
			row: [
				'tenantRef' => 'tenant-a',
				'quotaType' => 'cases_per_month',
				'limit' => 100,
				'currentUsage' => 100,
				'enforcement' => 'block',
				'resetAt' => '2020-01-01T00:00:00+00:00',
			]
		);
	}//end setUp()

	/**
	 * One run resets the due quota as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['tenantQuota']);
		$this->assertSame(0, $this->register->row(schema: 'tenantQuota', id: 'quota-due')['currentUsage']);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is written or even attempted.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame(100, $this->register->row(schema: 'tenantQuota', id: 'quota-due')['currentUsage']);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real quota service.
	 *
	 * @return object The job.
	 */
	private function job(): object {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->register);

		$limits = $this->createMock(OrganisationQuotaLimits::class);
		$limits->method('owns')->willReturn(false);

		$quotas = new TenantQuotaService(
			appManager: $apps,
			container: $container,
			logger: new NullLogger(),
			organisationLimits: $limits,
		);

		return $this->buildWith(
			ResetMonthlyQuotasJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'quotaService' => $quotas,
				'appManager' => $apps,
				'container' => $container,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
