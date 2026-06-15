<?php

/**
 * Remaining Decision Delegation Unit Tests
 *
 * Covers the procest-delegate-remaining-decisions-to-decidesk delegation
 * plumbing: the shared raiseDecision core fails CLOSED when the decidesk
 * integration leaf is unavailable (REQ-PDRD-002), and the thin per-flow
 * siblings (BezwaarDecisionDelegationService, AdviceDelegationService) raise a
 * Decision of the right decisionType and return the ref when the leaf is
 * available (REQ-PDRD-001).
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\AdviceDelegationService;
use OCA\Procest\Service\BezwaarDecisionDelegationService;
use OCA\Procest\Service\ContractDecisionDelegationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Integration-leaf stub matching the decidesk hub named-arg surface.
 */
interface DecisionLeafStub
{
    /**
     * Create a decidesk Decision.
     *
     * @param array<string,mixed> $payload The decision payload.
     *
     * @return array<string,mixed>
     */
    public function createDecision(array $payload): array;
}//end interface

/**
 * @covers \OCA\Procest\Service\ContractDecisionDelegationService
 * @covers \OCA\Procest\Service\BezwaarDecisionDelegationService
 * @covers \OCA\Procest\Service\AdviceDelegationService
 */
class RemainingDecisionDelegationTest extends TestCase
{

    /**
     * raiseDecision fails CLOSED when OpenRegister (the leaf source) is absent.
     *
     * @return void
     */
    public function testRaiseDecisionFailsClosedWhenLeafUnavailable(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        // No openregister installed → resolveIntegrationService() returns null.
        $appManager->method('getInstalledApps')->willReturn(['procest']);

        $core = new ContractDecisionDelegationService(
            $appManager,
            $this->createMock(ContainerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Decision service unavailable');

        $core->raiseDecision(
            decisionType: ContractDecisionDelegationService::DECISION_TYPE_ADVICE,
            externalReference: 'case-1',
            subject: ['subjectSchema' => 'adviesAanvraag', 'subjectId' => 'a-1'],
        );
    }//end testRaiseDecisionFailsClosedWhenLeafUnavailable()

    /**
     * raiseDecision returns the decidesk decisionRef when the leaf is available.
     *
     * @return void
     */
    public function testRaiseDecisionReturnsRefWhenLeafAvailable(): void
    {
        $leaf = $this->createMock(DecisionLeafStub::class);
        $leaf->expects($this->once())
            ->method('createDecision')
            ->willReturnCallback(function (array $payload): array {
                // The decisionType + sourceApp provenance must be carried.
                $this->assertSame('advice', $payload['decisionType']);
                $this->assertSame('procest', $payload['sourceApp']);
                return ['id' => 'decision-uuid-123'];
            });

        $core = $this->coreWithLeaf($leaf);

        $ref = $core->raiseDecision(
            decisionType: ContractDecisionDelegationService::DECISION_TYPE_ADVICE,
            externalReference: 'case-1',
            subject: ['subjectSchema' => 'adviesAanvraag', 'subjectId' => 'a-1'],
        );

        $this->assertSame('decision-uuid-123', $ref);
    }//end testRaiseDecisionReturnsRefWhenLeafAvailable()

    /**
     * The bezwaar sibling raises a bezwaar-decision Decision and returns the ref.
     *
     * @return void
     */
    public function testBezwaarSiblingRaisesBezwaarDecision(): void
    {
        $leaf = $this->createMock(DecisionLeafStub::class);
        $leaf->method('createDecision')->willReturnCallback(function (array $payload): array {
            $this->assertSame('bezwaar-decision', $payload['decisionType']);
            $this->assertSame('bezwaarDecision', $payload['subjectSchema']);
            return ['id' => 'bd-1'];
        });

        $sibling = new BezwaarDecisionDelegationService($this->coreWithLeaf($leaf));

        $ref = $sibling->raiseBezwaarDecision('bezwaar-1', [
            'subjectId'       => 'dec-1',
            'dispositionType' => 'ongegrond',
            'reasoning'       => 'r',
            'legalBasis'      => 'Awb 7:11',
        ]);

        $this->assertSame('bd-1', $ref);
    }//end testBezwaarSiblingRaisesBezwaarDecision()

    /**
     * The advice sibling fails CLOSED when the leaf is unavailable.
     *
     * @return void
     */
    public function testAdviceSiblingFailsClosed(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['procest']);

        $core    = new ContractDecisionDelegationService(
            $appManager,
            $this->createMock(ContainerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
        $sibling = new AdviceDelegationService($core);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Decision service unavailable');

        $sibling->raiseAdviceDecision('adviesAanvraag', 'a-1', ['externalReference' => 'case-1']);
    }//end testAdviceSiblingFailsClosed()

    /**
     * The voorstel besluit path raises a report-adoption Decision.
     *
     * @return void
     */
    public function testVoorstelBesluitRaisesReportAdoption(): void
    {
        $leaf = $this->createMock(DecisionLeafStub::class);
        $leaf->method('createDecision')->willReturnCallback(function (array $payload): array {
            $this->assertSame('report-adoption', $payload['decisionType']);
            $this->assertSame('voorstel', $payload['subjectSchema']);
            return ['id' => 'ra-1'];
        });

        $sibling = new AdviceDelegationService($this->coreWithLeaf($leaf));

        $ref = $sibling->raiseVoorstelBesluit('voorstel-1', ['title' => 'Besluit X']);
        $this->assertSame('ra-1', $ref);
    }//end testVoorstelBesluitRaisesReportAdoption()

    /**
     * Build a delegation core whose ADR-019 registry resolves to the given leaf.
     *
     * @param object $leaf The integration leaf stub.
     *
     * @return ContractDecisionDelegationService
     */
    private function coreWithLeaf(object $leaf): ContractDecisionDelegationService
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['procest', 'openregister']);

        $registry = new class($leaf) {
            /**
             * @param object $leaf Leaf to return from getLeaf().
             */
            public function __construct(private object $leaf)
            {
            }

            /**
             * Resolve a named integration leaf.
             *
             * @param string $name Leaf name.
             *
             * @return object
             */
            public function getLeaf(string $name): object
            {
                return $this->leaf;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('OCA\\OpenRegister\\Service\\IntegrationService')
            ->willReturn($registry);

        return new ContractDecisionDelegationService(
            $appManager,
            $container,
            $this->createMock(LoggerInterface::class),
        );
    }//end coreWithLeaf()
}//end class
