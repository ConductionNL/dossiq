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
     * Build the listener over mocked nodes that report the given ids.
     *
     * @return ProcestFlowNodeListener The listener under test.
     */
    private function listener(): ProcestFlowNodeListener {
        $mk = function (string $class, string $id) {
            $node = $this->createMock($class);
            $node->method('getId')->willReturn($id);
            return $node;
        };

        return new ProcestFlowNodeListener(
            $mk(ProcestSendEmailNode::class, 'procest.action.sendEmail'),
            $mk(ProcestNotifyRoleNode::class, 'procest.action.notifyRole'),
            $mk(ProcestCallWebhookNode::class, 'procest.action.callWebhook'),
            $mk(ProcestCreateDocumentNode::class, 'procest.action.createDocument'),
            $mk(ProcestMergeTemplateNode::class, 'procest.action.mergeTemplate'),
            $mk(ProcestScheduleReminderNode::class, 'procest.action.scheduleReminder'),
            $mk(ProcestTxSendEmailNode::class, 'procest.sendEmail'),
            $mk(ProcestTxCreateTaskNode::class, 'procest.createTask'),
            $mk(ProcestTxCreateSubCaseNode::class, 'procest.createSubCase'),
            $mk(ProcestTxWebhookNode::class, 'procest.webhook'),
            $mk(ProcestTxSetFieldNode::class, 'procest.setField'),
            $mk(ProcestTxNotifyNode::class, 'procest.notify'),
            $mk(ProcestTxBesluitvormingActivateNode::class, 'procest.besluitvormingActivate'),
            $mk(ProcestTxBesluitvormingPublishNode::class, 'procest.besluitvormingPublish'),
            $mk(ProcestTxEvaluateDecisionNode::class, 'procest.evaluateDecision'),
        );

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
            [
                'procest.action.sendEmail',
                'procest.action.notifyRole',
                'procest.action.callWebhook',
                'procest.action.createDocument',
                'procest.action.mergeTemplate',
                'procest.action.scheduleReminder',
                'procest.sendEmail',
                'procest.createTask',
                'procest.createSubCase',
                'procest.webhook',
                'procest.setField',
                'procest.notify',
                'procest.besluitvormingActivate',
                'procest.besluitvormingPublish',
                'procest.evaluateDecision',
            ],
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


}//end class
