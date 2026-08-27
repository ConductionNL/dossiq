<?php

/**
 * ShillinqIntegrationService Unit Tests
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

use OCA\Dossiq\Service\ShillinqIntegrationService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\ShillinqIntegrationService
 */
class ShillinqIntegrationServiceTest extends TestCase {
	private ShillinqIntegrationService $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->svc = new ShillinqIntegrationService(
			httpClientService: $this->createMock(IClientService::class),
			logger: $this->createMock(LoggerInterface::class),
			shillinqBaseUrl: '',
			shillinqApiKey: '',
		);
	}

	public function testGroupForInvoicingKeysByTenantAndMonth(): void {
		$events = [
			['tenantRef' => 't-1', 'occurredAt' => '2026-05-10T00:00:00+00:00', 'invoiceRef' => null],
			['tenantRef' => 't-1', 'occurredAt' => '2026-05-20T00:00:00+00:00', 'invoiceRef' => null],
			['tenantRef' => 't-2', 'occurredAt' => '2026-05-15T00:00:00+00:00', 'invoiceRef' => null],
		];
		$g = $this->svc->groupForInvoicing($events);
		$this->assertSame(2, count($g['t-1:2026-05']));
		$this->assertSame(1, count($g['t-2:2026-05']));
	}

	public function testGroupForInvoicingSkipsExportedEvents(): void {
		$events = [
			['tenantRef' => 't-1', 'occurredAt' => '2026-05-10T00:00:00+00:00', 'invoiceRef' => 'INV-1'],
		];
		$this->assertSame([], $this->svc->groupForInvoicing($events));
	}

	public function testBuildInvoicePayloadHasLineItems(): void {
		$events = [
			['eventType' => 'case_created', 'quantity' => 2, 'unitPrice' => 5.0, 'currency' => 'EUR', 'occurredAt' => '2026-05-10T00:00:00+00:00'],
		];
		$payload = $this->svc->buildInvoicePayload('t-1', '2026-05', $events);
		$this->assertSame('t-1', $payload['tenant_id']);
		$this->assertSame('2026-05', $payload['period']);
		$this->assertSame(1, count($payload['line_items']));
		$this->assertSame('case_created', $payload['line_items'][0]['description']);
	}

	public function testExportInvoiceReturnsFailureWhenNotConfigured(): void {
		$r = $this->svc->exportInvoice(['tenant_id' => 't-1']);
		$this->assertFalse($r['success']);
		$this->assertSame('Shillinq not configured', $r['lastError']);
	}
}
