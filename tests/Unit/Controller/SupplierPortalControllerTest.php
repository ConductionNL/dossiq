<?php

/**
 * SupplierPortalController Unit Tests
 *
 * Round-3 controller tests for the operator-side leverancier-zaakportaal
 * surface. Each test wires the controller against mocked services so the
 * unit test stays free of OR / OCP bootstrap noise.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-14-kpi-frontend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\SupplierPortalController;
use OCA\Procest\Service\LeverancierViewModelService;
use OCA\Procest\Service\SupplierDashboardService;
use OCA\Procest\Service\SupplierKpiAggregationService;
use OCA\Procest\Service\SupplierMessageService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenderViewModelService;
use OCA\Procest\Service\TenderVisibilityService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SupplierPortalController class.
 *
 * @covers \OCA\Procest\Controller\SupplierPortalController
 */
class SupplierPortalControllerTest extends TestCase
{
    /**
     * Build a controller with given request param map.
     *
     * @param array<string,string|null> $params       Request param map.
     * @param SupplierDashboardService|null $dashboard Optional override.
     * @param SupplierScopeService|null $scope         Optional override.
     * @param TenderViewModelService|null $tenderVm    Optional override.
     * @param LeverancierViewModelService|null $vm     Optional override.
     * @param SupplierKpiAggregationService|null $kpi  Optional override.
     * @param SupplierMessageService|null $messages    Optional override.
     *
     * @return SupplierPortalController
     */
    private function makeController(
        array $params,
        ?SupplierDashboardService $dashboard=null,
        ?SupplierScopeService $scope=null,
        ?TenderViewModelService $tenderVm=null,
        ?LeverancierViewModelService $vm=null,
        ?SupplierKpiAggregationService $kpi=null,
        ?SupplierMessageService $messages=null,
    ): SupplierPortalController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return $params[$key] ?? ($default ?? '');
            }
        );

        return new SupplierPortalController(
            $request,
            $scope ?? $this->createMock(SupplierScopeService::class),
            $dashboard ?? $this->createMock(SupplierDashboardService::class),
            $tenderVm ?? $this->createMock(TenderViewModelService::class),
            $this->createMock(TenderVisibilityService::class),
            $vm ?? $this->createMock(LeverancierViewModelService::class),
            $messages ?? $this->createMock(SupplierMessageService::class),
            $kpi ?? $this->createMock(SupplierKpiAggregationService::class),
        );
    }//end makeController()

    public function testDashboardRequiresSupplierRef(): void
    {
        $c = $this->makeController([]);
        $r = $c->dashboard();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        $this->assertSame(['error' => 'supplierRef required'], $r->getData());
    }//end testDashboardRequiresSupplierRef()

    public function testDashboardReturnsBuiltSummary(): void
    {
        $payload = ['tenders' => ['count' => 0], 'invoices' => [], 'contracts' => [], 'kpi' => ['ready' => false, 'period' => '2026-06']];
        $svc     = $this->createMock(SupplierDashboardService::class);
        $svc->expects($this->once())->method('buildSummary')->with('sup-123', $this->isType('int'))->willReturn($payload);
        $c = $this->makeController(['supplierRef' => 'sup-123'], dashboard: $svc);
        $r = $c->dashboard();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame($payload, $r->getData());
    }//end testDashboardReturnsBuiltSummary()

    public function testTendersRequiresSupplierRef(): void
    {
        $c = $this->makeController([]);
        $r = $c->tenders();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testTendersRequiresSupplierRef()

    public function testTendersDecoratesEachRowWithBadgeAndVisibility(): void
    {
        $scope   = $this->createMock(SupplierScopeService::class);
        $tenderV = $this->createMock(TenderViewModelService::class);
        $scope->expects($this->once())->method('listSupplierObjects')
            ->with('sup-1', 'supplierTender', [])
            ->willReturn([
                ['id' => 't1', 'status' => 'awarded'],
                ['id' => 't2', 'status' => 'rejected'],
            ]);
        $tenderV->expects($this->exactly(2))->method('badgeColor')
            ->willReturnOnConsecutiveCalls('green', 'red');
        $tenderV->expects($this->exactly(2))->method('visibilityFlags')
            ->willReturn(['showAward' => true]);
        $tenderV->expects($this->once())->method('cacheControlHeader')
            ->willReturn('private, max-age=300');

        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope, tenderVm: $tenderV);
        $r = $c->tenders();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        $this->assertSame(2, $data['total']);
        $this->assertSame('green', $data['items'][0]['badgeColor']);
        $this->assertSame('red', $data['items'][1]['badgeColor']);
        // Cache header set.
        $this->assertSame('private, max-age=300', $r->getHeaders()['Cache-Control']);
    }//end testTendersDecoratesEachRowWithBadgeAndVisibility()

    public function testTendersAppliesStatusFilter(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->expects($this->once())->method('listSupplierObjects')
            ->with('sup-1', 'supplierTender', ['status' => 'awarded'])
            ->willReturn([]);
        $c = $this->makeController(['supplierRef' => 'sup-1', 'status' => 'awarded'], scope: $scope);
        $c->tenders();
    }//end testTendersAppliesStatusFilter()

    public function testTenderDetailReturns404WhenIdMissing(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn([['id' => 'other-id', 'status' => 'submitted']]);
        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope);
        $r = $c->tenderDetail('not-found');
        $this->assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
    }//end testTenderDetailReturns404WhenIdMissing()

    public function testInvoicesDecoratesRowsWithBadgeAndOverdueFlag(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        $vm    = $this->createMock(LeverancierViewModelService::class);
        $scope->method('listSupplierObjects')->willReturn([
            ['id' => 'i1', 'status' => 'received', 'dueDate' => '2025-01-01'],
            ['id' => 'i2', 'status' => 'paid',     'dueDate' => '2025-01-01'],
        ]);
        $vm->expects($this->exactly(2))->method('invoiceBadgeColor')
            ->willReturnOnConsecutiveCalls('gray', 'green');
        $vm->expects($this->exactly(2))->method('isOverdue90Plus')
            ->willReturnOnConsecutiveCalls(true, false);
        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope, vm: $vm);
        $r = $c->invoices();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        $this->assertSame(2, $data['total']);
        $this->assertTrue($data['items'][0]['overdue90Plus']);
        $this->assertFalse($data['items'][1]['overdue90Plus']);
    }//end testInvoicesDecoratesRowsWithBadgeAndOverdueFlag()

    public function testContractsReturnsTotalAndItems(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn([['id' => 'c1'], ['id' => 'c2']]);
        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope);
        $r = $c->contracts();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame(2, $r->getData()['total']);
    }//end testContractsReturnsTotalAndItems()

    public function testKpiCallsAggregateKpisWithInvoices(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        $kpi   = $this->createMock(SupplierKpiAggregationService::class);
        $invs  = [['id' => 'i1']];
        $scope->method('listSupplierObjects')->willReturn($invs);
        $kpi->expects($this->once())->method('aggregateKpis')->with($invs)->willReturn(['paymentDays' => 12.5]);
        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope, kpi: $kpi);
        $r = $c->kpi();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame(['paymentDays' => 12.5], $r->getData());
    }//end testKpiCallsAggregateKpisWithInvoices()

    public function testMessagesReturnsEmptyWhenCaseRefMissing(): void
    {
        $c = $this->makeController(['supplierRef' => 'sup-1']);
        $r = $c->messages();
        $this->assertSame(['items' => [], 'total' => 0], $r->getData());
    }//end testMessagesReturnsEmptyWhenCaseRefMissing()

    public function testMessagesDelegatesToConversationHistory(): void
    {
        $messages = $this->createMock(SupplierMessageService::class);
        $messages->expects($this->once())
            ->method('getConversationHistory')
            ->with('case-1', 'sup-1')
            ->willReturn([['body' => 'hi']]);
        $c = $this->makeController(
            ['supplierRef' => 'sup-1', 'caseRef' => 'case-1'],
            messages: $messages,
        );
        $r = $c->messages();
        $data = $r->getData();
        $this->assertSame(1, $data['total']);
    }//end testMessagesDelegatesToConversationHistory()
}//end class
