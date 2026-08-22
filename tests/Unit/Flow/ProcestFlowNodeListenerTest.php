<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Procest\Tests\Unit\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\Procest\Flow\ProcestCallWebhookNode;
use OCA\Procest\Flow\ProcestCreateDocumentNode;
use OCA\Procest\Flow\ProcestFlowNodeListener;
use OCA\Procest\Flow\ProcestMergeTemplateNode;
use OCA\Procest\Flow\ProcestNotifyRoleNode;
use OCA\Procest\Flow\ProcestScheduleReminderNode;
use OCA\Procest\Flow\ProcestSendEmailNode;
use OCA\Procest\Flow\ProcestTxSendEmailNode;
use OCA\Procest\Flow\ProcestTxCreateTaskNode;
use OCA\Procest\Flow\ProcestTxCreateSubCaseNode;
use OCA\Procest\Flow\ProcestTxWebhookNode;
use OCA\Procest\Flow\ProcestTxSetFieldNode;
use OCA\Procest\Flow\ProcestTxNotifyNode;
use OCA\Procest\Flow\ProcestTxBesluitvormingActivateNode;
use OCA\Procest\Flow\ProcestTxBesluitvormingPublishNode;
use OCA\Procest\Flow\ProcestTxEvaluateDecisionNode;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves procest actually contributes all six case actions.
 *
 * A node class that exists but is never registered is invisible to the flow
 * editor — and looks identical to one that works, right up until somebody tries
 * to build a flow with it.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class ProcestFlowNodeListenerTest extends TestCase {

    /**
     * The id each node class reports, in the order the listener registers them.
     *
     * @var array<class-string, string>
     */
    private const EXPECTED_IDS = [
        ProcestTxSendEmailNode::class => 'procest.sendEmail',
        ProcestTxCreateTaskNode::class => 'procest.createTask',
        ProcestTxCreateSubCaseNode::class => 'procest.createSubCase',
        ProcestTxWebhookNode::class => 'procest.webhook',
        ProcestTxSetFieldNode::class => 'procest.setField',
        ProcestTxNotifyNode::class => 'procest.notify',
        ProcestTxBesluitvormingActivateNode::class => 'procest.besluitvormingActivate',
        ProcestTxBesluitvormingPublishNode::class => 'procest.besluitvormingPublish',
        ProcestTxEvaluateDecisionNode::class => 'procest.evaluateDecision',
        ProcestSendEmailNode::class => 'procest.action.sendEmail',
        ProcestNotifyRoleNode::class => 'procest.action.notifyRole',
        ProcestCallWebhookNode::class => 'procest.action.callWebhook',
        ProcestCreateDocumentNode::class => 'procest.action.createDocument',
        ProcestMergeTemplateNode::class => 'procest.action.mergeTemplate',
        ProcestScheduleReminderNode::class => 'procest.action.scheduleReminder',
    ];



    /**
     * Build the listener over a container that yields id-reporting nodes.
     *
     * The listener resolves its nodes from a class-string list, so the test
     * asserts what reaches the CATALOGUE rather than what was injected — which
     * is the thing that actually matters: a node class that exists but never
     * registers is invisible to the flow editor and looks identical to one that
     * works.
     *
     * @param string[] $failing Class names the container should refuse to build.
     *
     * @return ProcestFlowNodeListener The listener under test.
     */
    private function listener(array $failing=[]): ProcestFlowNodeListener {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $class) use ($failing): IFlowNode {
                if (in_array($class, $failing, true) === true) {
                    throw new RuntimeException('cannot construct ' . $class);
                }

                $node = $this->createMock(IFlowNode::class);
                $node->method('getId')->willReturn(self::EXPECTED_IDS[$class]);
                return $node;
            }
        );

        return new ProcestFlowNodeListener($container, $this->createMock(LoggerInterface::class));

    }//end listener()


    /**
     * All fifteen actions land on the catalogue — both vocabularies.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testAllFifteenActionsAreRegistered(): void {
        $event = new RegisterFlowNodesEvent();
        $this->listener()->handle($event);

        $ids = array_map(
            static fn ($node): string => $node->getId(),
            $event->getRegisteredNodes()
        );

        $this->assertSame(
            array_values(self::EXPECTED_IDS),
            $ids
        );

    }//end testAllFifteenActionsAreRegistered()


    /**
     * An unrelated event is ignored rather than half-handled.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnrelatedEventIsIgnored(): void {
        $other = new class extends Event {
        };

        $this->listener()->handle($other);

        $this->addToAssertionCount(1);

    }//end testUnrelatedEventIsIgnored()

    /**
     * One unbuildable node does not cost the other fourteen their place.
     *
     * The list-based resolution introduced this branch: if a single node's
     * dependencies cannot be constructed, aborting would empty the whole
     * catalogue. A skipped node is visible — the editor simply does not offer
     * it — where a failed registration takes everything down with it.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testOneUnbuildableNodeDoesNotCostTheRest(): void {
        $event = new RegisterFlowNodesEvent();
        $this->listener(failing: [ProcestTxSetFieldNode::class])->handle($event);

        $ids = array_map(
            static fn ($node): string => $node->getId(),
            $event->getRegisteredNodes()
        );

        $this->assertCount(14, $ids);
        $this->assertNotContains('procest.setField', $ids);
        $this->assertContains('procest.sendEmail', $ids);
        $this->assertContains('procest.action.sendEmail', $ids);

    }//end testOneUnbuildableNodeDoesNotCostTheRest()


}//end class
