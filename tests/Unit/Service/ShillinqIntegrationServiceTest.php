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
 * @spec openspec/changes/dossiq-delivers-nothing/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ShillinqIntegrationService;
use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Shillinq\Event\InvoiceIngestRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\ShillinqIntegrationService
 */
class ShillinqIntegrationServiceTest extends TestCase {
	/** @var IEventDispatcher&MockObject */
	private IEventDispatcher&MockObject $dispatcher;

	/** @var TenantAuditTrailService&MockObject */
	private TenantAuditTrailService&MockObject $audit;

	private ShillinqIntegrationService $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->audit = $this->createMock(TenantAuditTrailService::class);
		$this->svc = new ShillinqIntegrationService(
			eventDispatcher: $this->dispatcher,
			auditTrail: $this->audit,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	private function payload(): array {
		return $this->svc->buildInvoicePayload('t-1', '2026-05', [
			['uuid' => 'e-1', 'eventType' => 'case_created', 'quantity' => 2, 'unitPrice' => 5.0, 'currency' => 'EUR', 'occurredAt' => '2026-05-10T00:00:00+00:00'],
		]);
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

	public function testBuildInvoicePayloadCarriesPricedLines(): void {
		$payload = $this->payload();
		$this->assertSame('t-1', $payload['tenantId']);
		$this->assertSame('2026-05', $payload['period']);
		$this->assertSame(
			[['description' => 'case_created', 'quantity' => 2.0, 'unitPrice' => 5.0, 'currency' => 'EUR', 'occurredAt' => '2026-05-10T00:00:00+00:00', 'sourceId' => 'e-1']],
			$payload['lines']
		);
	}

	public function testBuildInvoicePayloadRefusesAnUnpricedEvent(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->buildInvoicePayload('t-1', '2026-05', [['eventType' => 'case_created', 'quantity' => 1]]);
	}

	/**
	 * Shillinq accepts: the drafted invoice comes back, nothing is recorded as refused.
	 */
	public function testExportInvoiceRaisesShillinqsCommandAndReadsTheInvoice(): void {
		$seen = null;
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (InvoiceIngestRequestedEvent $event) use (&$seen): void {
				$seen = $event;
				$event->accept(invoiceId: 'inv-9', invoiceNumber: 'BIL-2026-0009');
			}
		);
		$this->audit->expects($this->never())->method('emit');

		$r = $this->svc->exportInvoice($this->payload());

		$this->assertTrue($r['success']);
		$this->assertSame('inv-9', $r['invoiceRef']);
		$this->assertSame('BIL-2026-0009', $r['invoiceNumber']);
		$this->assertFalse($r['duplicated']);
		$this->assertSame('dossiq', $seen->getSourceApp());
		$this->assertSame('t-1', $seen->getExternalReference());
		$this->assertSame('2026-05', $seen->getPeriod());
		$this->assertCount(1, $seen->getLines());
	}

	/**
	 * Shillinq refuses (no customer carries the tenant): dossiq records the reason.
	 */
	public function testARefusalIsRecordedOnTheTenantsTrail(): void {
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (InvoiceIngestRequestedEvent $event): void {
				$event->refuse(error: 'No shillinq customer carries the external reference "t-1".');
			}
		);
		$this->audit->expects($this->once())->method('emit')->with($this->callback(
			static fn (array $p): bool => $p['action'] === 'invoice.refused'
				&& $p['tenantId'] === 't-1'
				&& str_contains($p['resource'], '2026-05')
				&& str_contains($p['resource'], 'No shillinq customer carries')
		));

		$r = $this->svc->exportInvoice($this->payload());

		$this->assertFalse($r['success']);
		$this->assertSame('No shillinq customer carries the external reference "t-1".', $r['lastError']);
	}

	public function testNoListenerAnsweringIsARefusalToo(): void {
		$this->audit->expects($this->once())->method('emit');

		$r = $this->svc->exportInvoice($this->payload());

		$this->assertFalse($r['success']);
		$this->assertSame('No shillinq listener answered the invoice request.', $r['lastError']);
	}

	public function testADispatchThatThrowsIsRecorded(): void {
		$this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener exploded'));
		$this->audit->expects($this->once())->method('emit');

		$r = $this->svc->exportInvoice($this->payload());

		$this->assertFalse($r['success']);
		$this->assertStringContainsString('listener exploded', $r['lastError']);
	}
}
