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
            $mk(ProcestSendEmailNode::class, 'procest.sendEmail'),
            $mk(ProcestNotifyRoleNode::class, 'procest.notifyRole'),
            $mk(ProcestCallWebhookNode::class, 'procest.callWebhook'),
            $mk(ProcestCreateDocumentNode::class, 'procest.createDocument'),
            $mk(ProcestMergeTemplateNode::class, 'procest.mergeTemplate'),
            $mk(ProcestScheduleReminderNode::class, 'procest.scheduleReminder'),
        );

    }//end listener()


    /**
     * All six actions land on the catalogue.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testAllSixActionsAreRegistered(): void {
        $event = new RegisterFlowNodesEvent();
        $this->listener()->handle($event);

        $ids = array_map(
            static fn ($node): string => $node->getId(),
            $event->getRegisteredNodes()
        );

        $this->assertSame(
            [
                'procest.sendEmail',
                'procest.notifyRole',
                'procest.callWebhook',
                'procest.createDocument',
                'procest.mergeTemplate',
                'procest.scheduleReminder',
            ],
            $ids
        );

    }//end testAllSixActionsAreRegistered()


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
