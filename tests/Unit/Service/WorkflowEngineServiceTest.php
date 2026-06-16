<?php

/**
 * WorkflowEngineService Unit Tests
 *
 * Tests for the public WorkflowEngineService facade. The facade itself does
 * not contain transition or guard logic — it delegates to existing services
 * (WorkflowDefinitionService, StatusTransitionService, GuardRegistry). These
 * tests therefore use stubs to assert the facade routes calls correctly and
 * shapes the guard-evaluation envelope the spec mandates.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-3
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\StatusTransitionService;
use OCA\Procest\Service\Transitions\GuardRegistry;
use OCA\Procest\Service\WorkflowDefinitionService;
use OCA\Procest\Service\WorkflowEngineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Procest\Service\WorkflowEngineService
 */
class WorkflowEngineServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function testGetActiveWorkflowDelegatesToDefinitionService(): void
    {
        $definition = ['id' => 'def-1', 'caseType' => 'ct-1', 'version' => 3, 'isActive' => true];

        $defService = $this->createMock(WorkflowDefinitionService::class);
        $defService->expects(self::once())
            ->method('getActiveDefinitionFor')
            ->with(caseTypeId: 'ct-1')
            ->willReturn($definition);

        $service = new WorkflowEngineService(
            definitionService: $defService,
            transitionService: $this->createMock(StatusTransitionService::class),
            guardRegistry: $this->createMock(GuardRegistry::class),
            logger: new NullLogger(),
        );

        self::assertSame($definition, $service->getActiveWorkflow(caseTypeId: 'ct-1'));
    }//end testGetActiveWorkflowDelegatesToDefinitionService()

    /**
     * @return void
     */
    public function testGetWorkflowForCaseDelegatesToDefinitionService(): void
    {
        $definition = ['id' => 'def-1', 'caseType' => 'ct-1', 'version' => 1];

        $defService = $this->createMock(WorkflowDefinitionService::class);
        $defService->expects(self::once())
            ->method('getDefinitionForCase')
            ->with(caseId: 'case-42')
            ->willReturn($definition);

        $service = new WorkflowEngineService(
            definitionService: $defService,
            transitionService: $this->createMock(StatusTransitionService::class),
            guardRegistry: $this->createMock(GuardRegistry::class),
            logger: new NullLogger(),
        );

        self::assertSame($definition, $service->getWorkflowForCase(caseId: 'case-42'));
    }//end testGetWorkflowForCaseDelegatesToDefinitionService()

    /**
     * @return void
     */
    public function testGetAvailableTransitionsDelegatesToTransitionService(): void
    {
        $list = [
            ['id' => 't1', 'available' => true, 'label' => 'Aanvaarden'],
            ['id' => 't2', 'available' => false, 'label' => 'Afwijzen', 'unmetGuards' => []],
        ];

        $transitionService = $this->createMock(StatusTransitionService::class);
        $transitionService->expects(self::once())
            ->method('getAvailableTransitions')
            ->with(caseId: 'case-1', userId: 'alice')
            ->willReturn($list);

        $service = new WorkflowEngineService(
            definitionService: $this->createMock(WorkflowDefinitionService::class),
            transitionService: $transitionService,
            guardRegistry: $this->createMock(GuardRegistry::class),
            logger: new NullLogger(),
        );

        self::assertSame($list, $service->getAvailableTransitions(caseId: 'case-1', userId: 'alice'));
    }//end testGetAvailableTransitionsDelegatesToTransitionService()

    /**
     * @return void
     */
    public function testEvaluateGuardsReturnsSpecEnvelopeWhenAllPassed(): void
    {
        $transition = [
            'id'     => 't-approve',
            'guards' => [
                ['type' => 'checklist', 'config' => ['items' => ['a', 'b']]],
            ],
        ];

        $results = [
            ['type' => 'checklist', 'passed' => true, 'message' => null],
        ];

        $guardRegistry = $this->createMock(GuardRegistry::class);
        $guardRegistry->expects(self::once())
            ->method('evaluateAll')
            ->with(
                guards: [['type' => 'checklist', 'config' => ['items' => ['a', 'b']]]],
                case: ['id' => 'case-1'],
                userId: 'alice'
            )
            ->willReturn($results);
        $guardRegistry->expects(self::once())
            ->method('allPassed')
            ->with(results: $results)
            ->willReturn(true);

        $service = new WorkflowEngineService(
            definitionService: $this->createMock(WorkflowDefinitionService::class),
            transitionService: $this->createMock(StatusTransitionService::class),
            guardRegistry: $guardRegistry,
            logger: new NullLogger(),
        );

        $envelope = $service->evaluateGuards(
            transition: $transition,
            case: ['id' => 'case-1'],
            userId: 'alice'
        );

        self::assertTrue($envelope['isSatisfied']);
        self::assertSame([], $envelope['unmetGuards']);
    }//end testEvaluateGuardsReturnsSpecEnvelopeWhenAllPassed()

    /**
     * @return void
     */
    public function testEvaluateGuardsReturnsUnmetGuardsWhenAnyFails(): void
    {
        $transition = [
            'id'     => 't-approve',
            'guards' => [
                ['type' => 'checklist', 'config' => ['items' => ['a']]],
                ['type' => 'requiredField', 'config' => ['field' => 'decision']],
            ],
        ];

        $results = [
            ['type' => 'checklist', 'passed' => true],
            ['type' => 'requiredField', 'passed' => false, 'message' => 'decision is missing'],
        ];

        $guardRegistry = $this->createMock(GuardRegistry::class);
        $guardRegistry->method('evaluateAll')->willReturn($results);
        $guardRegistry->method('allPassed')->willReturn(false);

        $service = new WorkflowEngineService(
            definitionService: $this->createMock(WorkflowDefinitionService::class),
            transitionService: $this->createMock(StatusTransitionService::class),
            guardRegistry: $guardRegistry,
            logger: new NullLogger(),
        );

        $envelope = $service->evaluateGuards(
            transition: $transition,
            case: ['id' => 'case-1'],
            userId: 'alice'
        );

        self::assertFalse($envelope['isSatisfied']);
        self::assertCount(1, $envelope['unmetGuards']);
        self::assertSame('requiredField', $envelope['unmetGuards'][0]['type']);
    }//end testEvaluateGuardsReturnsUnmetGuardsWhenAnyFails()

    /**
     * @return void
     */
    public function testEvaluateGuardsPromotesAllowedRolesIntoRoleGuard(): void
    {
        $transition = [
            'id'           => 't-approve',
            'guards'       => [],
            'allowedRoles' => ['Voorzitter', 'Secretaris'],
        ];

        $guardRegistry = $this->createMock(GuardRegistry::class);
        $guardRegistry->expects(self::once())
            ->method('evaluateAll')
            ->with(
                guards: [
                    ['type' => 'roleGuard', 'allowedRoles' => ['Voorzitter', 'Secretaris']],
                ],
                case: ['id' => 'case-1'],
                userId: ''
            )
            ->willReturn([['type' => 'roleGuard', 'passed' => true]]);
        $guardRegistry->method('allPassed')->willReturn(true);

        $service = new WorkflowEngineService(
            definitionService: $this->createMock(WorkflowDefinitionService::class),
            transitionService: $this->createMock(StatusTransitionService::class),
            guardRegistry: $guardRegistry,
            logger: new NullLogger(),
        );

        $envelope = $service->evaluateGuards(
            transition: $transition,
            case: ['id' => 'case-1'],
        );

        self::assertTrue($envelope['isSatisfied']);
    }//end testEvaluateGuardsPromotesAllowedRolesIntoRoleGuard()

    /**
     * @return void
     */
    public function testExecuteTransitionDelegatesToTransitionService(): void
    {
        $outcome = [
            'caseId'       => 'case-1',
            'transitionId' => 't1',
            'newStatus'    => 'In Review',
            'changedAt'    => '2026-06-10T00:00:00+00:00',
        ];

        $transitionService = $this->createMock(StatusTransitionService::class);
        $transitionService->expects(self::once())
            ->method('execute')
            ->with(
                caseId: 'case-1',
                transitionId: 't1',
                comment: 'Approved',
                userId: 'alice'
            )
            ->willReturn($outcome);

        $service = new WorkflowEngineService(
            definitionService: $this->createMock(WorkflowDefinitionService::class),
            transitionService: $transitionService,
            guardRegistry: $this->createMock(GuardRegistry::class),
            logger: new NullLogger(),
        );

        self::assertSame(
            $outcome,
            $service->executeTransition(
                caseId: 'case-1',
                transitionId: 't1',
                userId: 'alice',
                comment: 'Approved'
            )
        );
    }//end testExecuteTransitionDelegatesToTransitionService()
}//end class
