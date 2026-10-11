<?php

/**
 * The billing endpoints answer from TenantBillingService, and refuse a bad month.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-billing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\TenantBillingController;
use OCA\Dossiq\Service\TenantBillingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\TenantBillingController
 */
class TenantBillingControllerTest extends TestCase {
	/**
	 * The controller over a billing double.
	 *
	 * @param TenantBillingService $billing The billing service.
	 *
	 * @return TenantBillingController The controller.
	 */
	private function controller(TenantBillingService $billing): TenantBillingController {
		return new TenantBillingController(request: $this->createMock(IRequest::class), billingService: $billing);
	}//end controller()

	/**
	 * The summary is the service's answer for that tenant and month.
	 *
	 * @return void
	 */
	public function testTheSummaryIsTheServicesAnswer(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->expects($this->once())->method('getMonthBilling')->with('t-1', '2026-09')->willReturn(['amount' => 12.5]);

		$response = $this->controller(billing: $billing)->summary('t-1', '2026-09');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'summary' => ['amount' => 12.5]], $response->getData());
	}//end testTheSummaryIsTheServicesAnswer()

	/**
	 * The run answers with the invoice.
	 *
	 * @return void
	 */
	public function testTheRunAnswersWithTheInvoice(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->expects($this->once())->method('runInvoicing')->with('t-1', '2026-09')->willReturn(['invoiceRef' => 'INV-9']);

		$response = $this->controller(billing: $billing)->run('t-1', '2026-09');

		$this->assertSame(['success' => true, 'invoice' => ['invoiceRef' => 'INV-9']], $response->getData());
	}//end testTheRunAnswersWithTheInvoice()

	/**
	 * A month the service refuses is a 400 on both endpoints.
	 *
	 * @return void
	 */
	public function testARefusedMonthIsABadRequest(): void {
		$billing = $this->createMock(TenantBillingService::class);
		$billing->method('getMonthBilling')->willThrowException(new InvalidArgumentException('Invalid month'));
		$billing->method('runInvoicing')->willThrowException(new InvalidArgumentException('Invalid month'));
		$controller = $this->controller(billing: $billing);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->summary('t-1', 'not-a-month')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->run('t-1', 'not-a-month')->getStatus());
	}//end testARefusedMonthIsABadRequest()
}//end class
