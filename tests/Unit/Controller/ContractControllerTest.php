<?php

/**
 * ContractController Unit Tests
 *
 * Covers the supplier-scoped contract list/detail surface and the
 * renewal-request flow (role gate, manual-renewable + window checks, the
 * per-object IDOR fail-closed behaviour, and the cross-supplier 403).
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
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/specs/supplier-portal/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ContractController;
use OCA\Procest\Service\ContractRenewalService;
use OCA\Procest\Service\SupplierScopeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ContractController class.
 *
 * @covers \OCA\Procest\Controller\ContractController
 */
class ContractControllerTest extends TestCase
{
    /**
     * Build a controller with the given request param map and optional service
     * overrides. The renewal service defaults to a REAL instance with mocked
     * collaborators so the date/role helpers run for real.
     *
     * @param array<string,string|null>   $params  Request param map.
     * @param SupplierScopeService|null   $scope   Optional scope override.
     * @param ContractRenewalService|null $renewal Optional renewal override.
     *
     * @return ContractController
     */
    private function makeController(
        array $params,
        ?SupplierScopeService $scope=null,
        ?ContractRenewalService $renewal=null,
    ): ContractController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return $params[$key] ?? ($default ?? '');
            }
        );

        return new ContractController(
            $request,
            $scope ?? $this->createMock(SupplierScopeService::class),
            $renewal ?? $this->makeRealRenewalService(),
        );
    }//end makeController()

    /**
     * A real ContractRenewalService whose dependencies are mocked — exercises
     * the genuine days/window/role logic.
     *
     * @return ContractRenewalService
     */
    private function makeRealRenewalService(): ContractRenewalService
    {
        return new ContractRenewalService(
            scopeService: $this->createMock(SupplierScopeService::class),
            auditTrail: $this->createMock(\OCA\Procest\Service\TenantAuditTrailService::class),
            appManager: $this->createMock(\OCP\App\IAppManager::class),
            container: $this->createMock(\Psr\Container\ContainerInterface::class),
            logger: $this->createMock(\Psr\Log\LoggerInterface::class),
            decisionDelegation: $this->createMock(\OCA\Procest\Service\ContractDecisionDelegationService::class),
        );
    }//end makeRealRenewalService()

    public function testIndexRequiresSupplierRef(): void
    {
        $c = $this->makeController([]);
        $r = $c->index();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testIndexRequiresSupplierRef()

    public function testIndexReturnsScopedRowsWithComputedFields(): void
    {
        $future = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
        $scope  = $this->createMock(SupplierScopeService::class);
        $scope->expects($this->once())->method('listSupplierObjects')
            ->with('sup-1', 'supplierContract')
            ->willReturn([['id' => 'c1', 'endDate' => $future]]);

        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope);
        $r = $c->index();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        $this->assertSame(1, $data['total']);
        $this->assertTrue($data['items'][0]['renewalWindowOpen']);
        $this->assertGreaterThan(0, $data['items'][0]['daysUntilExpiry']);
    }//end testIndexReturnsScopedRowsWithComputedFields()

    public function testShowReturns403WhenNotOwned(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        // Caller's scope only contains other ids — requested id is not owned →
        // fail closed with 403 (no IDOR).
        $scope->method('listSupplierObjects')->willReturn([['id' => 'other', 'supplierRef' => 'sup-1']]);
        $scope->method('validateSupplierAccess')->willReturn(true);

        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope);
        $r = $c->show('does-not-exist');
        $this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
    }//end testShowReturns403WhenNotOwned()

    public function testShowReturnsContractWhenOwned(): void
    {
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn([['id' => 'c1', 'supplierRef' => 'sup-1', 'endDate' => '2030-01-01']]);
        $scope->method('validateSupplierAccess')->willReturn(true);

        $c = $this->makeController(['supplierRef' => 'sup-1'], scope: $scope);
        $r = $c->show('c1');
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertSame('c1', $r->getData()['id']);
    }//end testShowReturnsContractWhenOwned()

    public function testRequestRenewalRejectsInsufficientRole(): void
    {
        $c = $this->makeController(['supplierRef' => 'sup-1', 'role' => 'finance']);
        $r = $c->requestRenewal('c1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
        $this->assertSame(['error' => 'insufficient role'], $r->getData());
    }//end testRequestRenewalRejectsInsufficientRole()

    public function testRequestRenewalForCrossSupplierReturns403(): void
    {
        // Role is fine, but the contract id is not in the caller's scope —
        // findOwnedContract returns null → 403 (IDOR fail-closed).
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn([]);
        $scope->method('validateSupplierAccess')->willReturn(false);

        $c = $this->makeController(['supplierRef' => 'sup-1', 'role' => 'contracts'], scope: $scope);
        $r = $c->requestRenewal('foreign-contract');
        $this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
        $this->assertSame(['error' => 'forbidden'], $r->getData());
    }//end testRequestRenewalForCrossSupplierReturns403()

    public function testRequestRenewalRejectsNonManualContract(): void
    {
        $future = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
        $scope  = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn(
                [
                    ['id' => 'c1', 'supplierRef' => 'sup-1', 'endDate' => $future, 'renewalOption' => 'auto'],
                ]
                );
        $scope->method('validateSupplierAccess')->willReturn(true);

        $c = $this->makeController(['supplierRef' => 'sup-1', 'role' => 'contracts'], scope: $scope);
        $r = $c->requestRenewal('c1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testRequestRenewalRejectsNonManualContract()

    public function testRequestRenewalRejectsOutOfWindow(): void
    {
        $far   = (new \DateTimeImmutable('today'))->modify('+200 days')->format('Y-m-d');
        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn(
                [
                    ['id' => 'c1', 'supplierRef' => 'sup-1', 'endDate' => $far, 'renewalOption' => 'manual_request'],
                ]
                );
        $scope->method('validateSupplierAccess')->willReturn(true);

        $c = $this->makeController(['supplierRef' => 'sup-1', 'role' => 'contracts'], scope: $scope);
        $r = $c->requestRenewal('c1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testRequestRenewalRejectsOutOfWindow()

    public function testRequestRenewalHappyPathDelegatesToService(): void
    {
        $future   = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
        $contract = ['id' => 'c1', 'supplierRef' => 'sup-1', 'endDate' => $future, 'renewalOption' => 'manual_request'];

        $scope = $this->createMock(SupplierScopeService::class);
        $scope->method('listSupplierObjects')->willReturn([$contract]);
        $scope->method('validateSupplierAccess')->willReturn(true);

        $renewal = $this->createMock(ContractRenewalService::class);
        $renewal->method('canRequestRenewal')->willReturn(true);
        $renewal->method('isWithinRenewalWindow')->willReturn(true);
        $renewal->expects($this->once())->method('requestRenewal')
            ->with($this->callback(fn ($c) => ($c['id'] ?? '') === 'c1'), 'actor-bob')
            ->willReturn(['ok' => true, 'caseRef' => 'case-99']);

        $c = $this->makeController(
            ['supplierRef' => 'sup-1', 'role' => 'contracts', 'actor' => 'actor-bob'],
            scope: $scope,
            renewal: $renewal,
        );
        $r = $c->requestRenewal('c1');
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        // The controller returns the decidesk decisionRef in the envelope
        // (REQ-PDCD-001); the renewal mock omits it so it defaults to ''.
        $this->assertSame(['ok' => true, 'caseRef' => 'case-99', 'decisionRef' => ''], $r->getData());
    }//end testRequestRenewalHappyPathDelegatesToService()
}//end class
