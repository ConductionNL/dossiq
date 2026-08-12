<?php

/**
 * TenantQuotaService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantQuotaService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantQuotaService
 */
class TenantQuotaServiceTest extends TestCase {
	private TenantQuotaService $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->svc = new TenantQuotaService(
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
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
