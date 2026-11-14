<?php

/**
 * TenantQuotaService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\TenantQuotaService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TenantQuotaService
 */
class TenantQuotaServiceTest extends TestCase {
	private TenantQuotaService $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->svc = new TenantQuotaService(
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			organisationLimits: $this->createMock(OrganisationQuotaLimits::class),
		);
	}

	public function testTierDefaultsContainBasicStandardEnterprise(): void {
		$this->assertArrayHasKey('basic', TenantQuotaService::TIER_DEFAULTS);
		$this->assertArrayHasKey('standard', TenantQuotaService::TIER_DEFAULTS);
		$this->assertArrayHasKey('enterprise', TenantQuotaService::TIER_DEFAULTS);
		$this->assertSame(100, TenantQuotaService::TIER_DEFAULTS['basic']['cases_per_month']['limit']);
		$this->assertNull(TenantQuotaService::TIER_DEFAULTS['enterprise']['cases_per_month']['limit']);
	}

	public function testInitializeRejectsUnknownTier(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->initialize('t-1', 'gold');
	}

	public function testDecideAllowsBelowLimit(): void {
		$d = $this->svc->decide(['limit' => 100, 'currentUsage' => 10, 'enforcement' => 'block']);
		$this->assertSame(TenantQuotaService::DECISION_ALLOW, $d['decision']);
		$this->assertFalse($d['soft']);
	}

	public function testDecideFlagsSoftLimitAt80Percent(): void {
		$d = $this->svc->decide(['limit' => 100, 'currentUsage' => 80, 'enforcement' => 'block', 'softLimitWarningPercent' => 80]);
		$this->assertSame(TenantQuotaService::DECISION_ALLOW, $d['decision']);
		$this->assertTrue($d['soft']);
	}

	public function testDecideBlocksAtLimit(): void {
		$d = $this->svc->decide(['limit' => 100, 'currentUsage' => 100, 'enforcement' => 'block']);
		$this->assertSame(TenantQuotaService::DECISION_BLOCK, $d['decision']);
	}

	public function testDecideThrottlesWhenEnforcementThrottle(): void {
		$d = $this->svc->decide(['limit' => 100, 'currentUsage' => 100, 'enforcement' => 'throttle']);
		$this->assertSame(TenantQuotaService::DECISION_THROTTLE, $d['decision']);
	}

	public function testDecideWarnsWhenEnforcementWarn(): void {
		$d = $this->svc->decide(['limit' => 100, 'currentUsage' => 100, 'enforcement' => 'warn']);
		$this->assertSame(TenantQuotaService::DECISION_WARN, $d['decision']);
	}

	public function testDecideAllowsUnlimitedQuota(): void {
		$d = $this->svc->decide(['limit' => null, 'currentUsage' => 9999]);
		$this->assertSame(TenantQuotaService::DECISION_ALLOW, $d['decision']);
		$this->assertSame('unlimited', $d['reason']);
	}

	/**
	 * Every decision, with its soft flag and reason, at and around both limits.
	 *
	 * @return void
	 */
	public function testEveryDecisionIsExact(): void {
		$decide = fn (array $quota, int $increment=1): array => $this->svc->decide($quota, $increment);
		$allow = static fn (bool $soft, string $reason): array => ['decision' => 'allow', 'soft' => $soft, 'reason' => $reason];

		$this->assertSame($allow(false, 'unlimited'), $decide(['currentUsage' => 5]));
		$this->assertSame($allow(false, 'within_limit'), $decide(['limit' => 100, 'currentUsage' => 78]));
		$this->assertSame($allow(true, 'soft_limit'), $decide(['limit' => 100, 'currentUsage' => 79]));
		$this->assertSame($allow(true, 'soft_limit'), $decide(['limit' => '100', 'currentUsage' => 99, 'enforcement' => 'block']));
		$this->assertSame($allow(true, 'soft_limit'), $decide(['limit' => 10, 'currentUsage' => 2, 'softLimitWarningPercent' => 25]));
		$this->assertSame($allow(false, 'within_limit'), $decide(['limit' => 10, 'currentUsage' => 2], 3));
		$this->assertSame(
			['decision' => 'block', 'soft' => true, 'reason' => 'block_limit_exceeded'],
			$decide(['limit' => 100, 'currentUsage' => 99, 'enforcement' => 'block'], 2)
		);
		$this->assertSame(
			['decision' => 'throttle', 'soft' => true, 'reason' => 'throttle_limit_exceeded'],
			$decide(['limit' => 0, 'enforcement' => 'throttle'])
		);
		$this->assertSame(['decision' => 'warn', 'soft' => true, 'reason' => 'warn_limit_exceeded'], $decide(['limit' => 0]));
		$this->assertSame(
			['decision' => 'warn', 'soft' => true, 'reason' => 'warn_limit_exceeded'],
			$decide(['limit' => 1, 'currentUsage' => 1, 'enforcement' => 'something-else'])
		);
	}//end testEveryDecisionIsExact()

	/**
	 * A quota read that throws answers no quota.
	 *
	 * @return void
	 */
	public function testAQuotaReadThatThrowsAnswersNull(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$objectService = new class {
			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> Never.
			 */
			public function findAll(array $config=[]): array {
				throw new \RuntimeException('down');
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$svc = new TenantQuotaService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			organisationLimits: $this->createMock(OrganisationQuotaLimits::class),
		);

		$this->assertNull($svc->getQuota('t-1', 'cases_per_month'));
	}//end testAQuotaReadThatThrowsAnswersNull()

	public function testNextResetAtMonthlyAndHourly(): void {
		$monthly = $this->svc->nextResetAt('cases_per_month');
		$hourly = $this->svc->nextResetAt('api_calls_per_hour');
		$this->assertNotSame('', $monthly);
		$this->assertNotSame('', $hourly);
		// Hourly window is far smaller than monthly window.
		$this->assertLessThan(strtotime($monthly), strtotime($hourly));
	}

	public function testResetIfDueResetsExpiredWindow(): void {
		$row = ['quotaType' => 'cases_per_month', 'currentUsage' => 42, 'resetAt' => '2000-01-01T00:00:00+00:00'];
		$next = $this->svc->resetIfDue($row);
		$this->assertSame(0, $next['currentUsage']);
	}

	public function testResetIfDueLeavesUnexpiredWindowUntouched(): void {
		$row = ['quotaType' => 'cases_per_month', 'currentUsage' => 42, 'resetAt' => (new \DateTimeImmutable('+10 days'))->format(DATE_ATOM)];
		$next = $this->svc->resetIfDue($row);
		$this->assertSame(42, $next['currentUsage']);
	}

	public function testConsumeAllowsWhenNoQuotaRowExists(): void {
		$d = $this->svc->consume('t-1', 'cases_per_month', 1);
		$this->assertSame(TenantQuotaService::DECISION_ALLOW, $d['decision']);
		$this->assertSame('no_quota_row', $d['reason']);
	}
}
