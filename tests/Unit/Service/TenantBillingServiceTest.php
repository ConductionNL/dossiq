<?php

/**
 * TenantBillingService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\TenantBillingService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\TenantBillingService
 */
class TenantBillingServiceTest extends TestCase {
	private TenantBillingService $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->svc = new TenantBillingService(
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			shillinq: $this->createMock(\OCA\Dossiq\Service\ShillinqIntegrationService::class),
		);
	}

	public function testTierMonthlyPriceResolvesKnownTiersAndDefaultsToZero(): void {
		$this->assertSame(49.0, $this->svc->tierMonthlyPrice('basic'));
		$this->assertSame(149.0, $this->svc->tierMonthlyPrice('standard'));
		$this->assertSame(499.0, $this->svc->tierMonthlyPrice('enterprise'));
		$this->assertSame(0.0, $this->svc->tierMonthlyPrice('unknown'));
	}

	public function testRunInvoicingComputesNonZeroAmountAndExports(): void {
		// A tenant with one activation event at the standard tier price.
		$events = [
			['uuid' => 'e-1', 'tenantRef' => 't-1', 'eventType' => 'user_activated', 'quantity' => 1.0, 'unitPrice' => 149.0, 'currency' => 'EUR', 'occurredAt' => '2026-07-05T10:00:00+00:00', 'invoiceRef' => null],
		];

		$shillinq = $this->createMock(\OCA\Dossiq\Service\ShillinqIntegrationService::class);
		$shillinq->method('buildInvoicePayload')->willReturn(['tenant_id' => 't-1', 'period' => '2026-07', 'currency' => 'EUR', 'line_items' => []]);
		$shillinq->expects($this->once())
			->method('exportInvoice')
			->willReturn(['success' => true, 'invoiceRef' => 'INV-2026-07-t1', 'attempts' => 1]);

		$svc = $this->getMockBuilder(TenantBillingService::class)
			->setConstructorArgs([
				$this->createMock(IAppManager::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
				$shillinq,
			])
			->onlyMethods(['fetchEventsForMonth', 'markExported'])
			->getMock();
		$svc->method('fetchEventsForMonth')->willReturn($events);
		$svc->expects($this->once())->method('markExported')->willReturn(1);

		$result = $svc->runInvoicing('t-1', '2026-07');

		$this->assertGreaterThan(0.0, $result['amount'], 'invoice amount must be non-zero for real usage');
		$this->assertSame(149.0, $result['amount']);
		$this->assertTrue($result['exported']);
		$this->assertSame('INV-2026-07-t1', $result['invoiceRef']);
		$this->assertSame(1, $result['eventCount']);
	}

	public function testEmitEventRejectsUnknownType(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->emitEvent('t-1', 'not-a-real-type');
	}

	public function testAggregateNetsRefundsAgainstCharges(): void {
		$events = [
			['eventType' => 'case_created', 'quantity' => 1, 'unitPrice' => 10.0],
			['eventType' => 'case_created', 'quantity' => 1, 'unitPrice' => 10.0],
			['eventType' => 'case_refund',  'quantity' => -1, 'unitPrice' => 10.0],
		];
		$r = $this->svc->aggregate($events);
		$this->assertSame(3, $r['eventCount']);
		$this->assertSame(10.0, $r['totalAmount']);
		$this->assertSame(2.0, $r['byType']['case_created']['count']);
		$this->assertSame(-1.0, $r['byType']['case_refund']['count']);
	}

	public function testAggregateGroupsByEventType(): void {
		$events = [
			['eventType' => 'case_created', 'quantity' => 5, 'unitPrice' => 2.0],
			['eventType' => 'user_activated', 'quantity' => 1, 'unitPrice' => 0.0],
		];
		$r = $this->svc->aggregate($events);
		$this->assertSame(5.0, $r['byType']['case_created']['count']);
		$this->assertSame(10.0, $r['byType']['case_created']['amount']);
	}

	public function testGetMonthBillingRejectsBadMonth(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->getMonthBilling('t-1', '2026-13');
	}

	public function testGetMonthBillingReturnsEmptyWhenOrUnavailable(): void {
		$r = $this->svc->getMonthBilling('t-1', '2026-06');
		$this->assertSame(0, $r['eventCount']);
		$this->assertSame(0.0, $r['totalAmount']);
	}

	public function testEmitEventReturnsNullWhenOrUnavailable(): void {
		$this->assertNull($this->svc->emitEvent('t-1', 'case_created'));
	}

	public function testAllowedEventTypesIncludesRefund(): void {
		$this->assertContains('case_refund', TenantBillingService::ALLOWED_EVENT_TYPES);
	}
}
