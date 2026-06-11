<?php

/**
 * TenantBillingService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-10-billing-shillinq/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantBillingService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantBillingService
 */
class TenantBillingServiceTest extends TestCase
{
    private TenantBillingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenantBillingService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testEmitEventRejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->emitEvent('t-1', 'not-a-real-type');
    }

    public function testAggregateNetsRefundsAgainstCharges(): void
    {
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

    public function testAggregateGroupsByEventType(): void
    {
        $events = [
            ['eventType' => 'case_created', 'quantity' => 5, 'unitPrice' => 2.0],
            ['eventType' => 'user_activated', 'quantity' => 1, 'unitPrice' => 0.0],
        ];
        $r = $this->svc->aggregate($events);
        $this->assertSame(5.0, $r['byType']['case_created']['count']);
        $this->assertSame(10.0, $r['byType']['case_created']['amount']);
    }

    public function testGetMonthBillingRejectsBadMonth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->getMonthBilling('t-1', '2026-13');
    }

    public function testGetMonthBillingReturnsEmptyWhenOrUnavailable(): void
    {
        $r = $this->svc->getMonthBilling('t-1', '2026-06');
        $this->assertSame(0, $r['eventCount']);
        $this->assertSame(0.0, $r['totalAmount']);
    }

    public function testEmitEventReturnsNullWhenOrUnavailable(): void
    {
        $this->assertNull($this->svc->emitEvent('t-1', 'case_created'));
    }

    public function testAllowedEventTypesIncludesRefund(): void
    {
        $this->assertContains('case_refund', TenantBillingService::ALLOWED_EVENT_TYPES);
    }
}
