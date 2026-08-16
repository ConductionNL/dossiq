<?php

/**
 * TenantLifecycleControlService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-11-suspension-termination/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\TenantBillingService;
use OCA\Procest\Service\TenantLifecycleControlService;
use OCA\Procest\Service\TenantProvisioningService;
use OCA\Procest\Service\TenantSaasService;
use OCA\Procest\Service\TenantSchemaProvisioner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantLifecycleControlService
 */
class TenantLifecycleControlServiceTest extends TestCase {
	private TenantSaasService $tenantSaas;
	private TenantBillingService $billing;
	private TenantSchemaProvisioner $schemaProv;
	private TenantProvisioningService $provisioning;
	private TenantLifecycleControlService $svc;

	protected function setUp(): void {
		parent::setUp();
		$this->tenantSaas = $this->createMock(TenantSaasService::class);
		$this->billing = $this->createMock(TenantBillingService::class);
		$this->schemaProv = $this->createMock(TenantSchemaProvisioner::class);
		$this->provisioning = $this->createMock(TenantProvisioningService::class);

		$this->svc = new TenantLifecycleControlService(
			tenantSaasService: $this->tenantSaas,
			billingService: $this->billing,
			schemaProvisioner: $this->schemaProv,
			provisioning: $this->provisioning,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	public function testSuspendCallsStatusTransition(): void {
		$this->tenantSaas->expects($this->once())
			->method('updateStatus')
			->with('t-1', 'suspended')
			->willReturn(['uuid' => 't-1', 'status' => 'suspended']);

		$row = $this->svc->suspend('t-1', 'Non-payment');
		$this->assertSame('suspended', $row['status']);
	}

	public function testReactivateCallsStatusTransition(): void {
		$this->tenantSaas->expects($this->once())
			->method('updateStatus')
			->with('t-1', 'active')
			->willReturn(['uuid' => 't-1', 'status' => 'active']);

		$row = $this->svc->reactivate('t-1');
		$this->assertSame('active', $row['status']);
	}

	public function testTerminateCountsUnsettledBeforeTransition(): void {
		$this->billing->method('fetchEventsForMonth')->willReturn([
			['invoiceRef' => null],
			['invoiceRef' => 'INV-1'],
			['invoiceRef' => null],
		]);

		$this->tenantSaas->expects($this->once())
			->method('updateStatus')
			->with('t-1', 'terminated')
			->willReturn(['uuid' => 't-1', 'status' => 'terminated']);

		$r = $this->svc->terminate('t-1', 'Customer left', 2);
		$this->assertSame(2, $r['unsettledEvents']);
		$this->assertSame(2, $r['retentionYears']);
		$this->assertSame('terminated', $r['tenant']['status']);
	}

	/**
	 * The service exposes no schema-dropping entry point. Nothing in the app
	 * references TenantLifecycleControlService, and there is no retention
	 * timer to decide the window has passed, so an irreversible whole-tenant
	 * delete must not sit here waiting for its first caller.
	 *
	 * @return void
	 */
	public function testServiceExposesNoSchemaDrop(): void {
		$this->assertFalse(
			condition: method_exists(TenantLifecycleControlService::class, 'archiveAndDelete'),
			message: 'TenantLifecycleControlService must not expose an irreversible tenant delete.'
		);
	}

	public function testCountUnsettledEventsHonoursInvoiceRef(): void {
		$this->billing->method('fetchEventsForMonth')->willReturn([
			['invoiceRef' => null],
			['invoiceRef' => null],
			['invoiceRef' => 'INV-1'],
		]);

		$this->assertSame(2, $this->svc->countUnsettledEvents('t-1'));
	}
}
