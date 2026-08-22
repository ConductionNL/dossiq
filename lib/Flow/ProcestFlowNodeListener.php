<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Procest\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Presents procest's six case actions to OpenRegister's flow engine.
 *
 * ADR-065: OpenRegister owns the flow engine and no leaf app grows a second
 * one. procest does not keep one — it CONTRIBUTES the six things its cases can
 * do, which is what FlowNodeRegistry is built for and what hermiq already does
 * with its agent nodes.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class ProcestFlowNodeListener implements IEventListener {


    /**
     * Constructor.
     *
     * @param ProcestSendEmailNode        $sendEmail        Send a templated email.
     * @param ProcestNotifyRoleNode       $notifyRole       Notify a role on the case.
     * @param ProcestCallWebhookNode      $callWebhook      POST to a configured endpoint.
     * @param ProcestCreateDocumentNode   $createDocument   Render a document onto the case.
     * @param ProcestMergeTemplateNode    $mergeTemplate    Render a template into a field.
     * @param ProcestScheduleReminderNode $scheduleReminder Queue a reminder.
     * @param ProcestTxSendEmailNode $sendEmailTx Live transition action `sendEmail`.
     * @param ProcestTxCreateTaskNode $createTaskTx Live transition action `createTask`.
     * @param ProcestTxCreateSubCaseNode $createSubCaseTx Live transition action `createSubCase`.
     * @param ProcestTxWebhookNode $webhookTx Live transition action `webhook`.
     * @param ProcestTxSetFieldNode $setFieldTx Live transition action `setField`.
     * @param ProcestTxNotifyNode $notifyTx Live transition action `notify`.
     * @param ProcestTxBesluitvormingActivateNode $besluitvormingActivateTx Live transition action `besluitvormingActivate`.
     * @param ProcestTxBesluitvormingPublishNode $besluitvormingPublishTx Live transition action `besluitvormingPublish`.
     * @param ProcestTxEvaluateDecisionNode $evaluateDecisionTx Live transition action `evaluateDecision`.
     *
     * @return void
     */
    public function __construct(
        private readonly ProcestSendEmailNode $sendEmail,
        private readonly ProcestNotifyRoleNode $notifyRole,
        private readonly ProcestCallWebhookNode $callWebhook,
        private readonly ProcestCreateDocumentNode $createDocument,
        private readonly ProcestMergeTemplateNode $mergeTemplate,
        private readonly ProcestScheduleReminderNode $scheduleReminder,
        private readonly ProcestTxSendEmailNode $sendEmailTx,
        private readonly ProcestTxCreateTaskNode $createTaskTx,
        private readonly ProcestTxCreateSubCaseNode $createSubCaseTx,
        private readonly ProcestTxWebhookNode $webhookTx,
        private readonly ProcestTxSetFieldNode $setFieldTx,
        private readonly ProcestTxNotifyNode $notifyTx,
        private readonly ProcestTxBesluitvormingActivateNode $besluitvormingActivateTx,
        private readonly ProcestTxBesluitvormingPublishNode $besluitvormingPublishTx,
        private readonly ProcestTxEvaluateDecisionNode $evaluateDecisionTx,
    ) {

    }//end __construct()


    /**
     * Register procest's nodes on the catalogue.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function handle(Event $event): void {
        if (($event instanceof RegisterFlowNodesEvent) === false) {
            return;
        }

        $event->registerNode(node: $this->sendEmail);
        $event->registerNode(node: $this->notifyRole);
        $event->registerNode(node: $this->callWebhook);
        $event->registerNode(node: $this->createDocument);
        $event->registerNode(node: $this->mergeTemplate);
        $event->registerNode(node: $this->scheduleReminder);

        // The LIVE vocabulary: what SideEffectDispatcher fires on every status
        // change. These take the plain `procest.*` ids; the configured-action
        // catalogue above takes `procest.action.*`, because both ship a sendEmail.
        $event->registerNode(node: $this->sendEmailTx);
        $event->registerNode(node: $this->createTaskTx);
        $event->registerNode(node: $this->createSubCaseTx);
        $event->registerNode(node: $this->webhookTx);
        $event->registerNode(node: $this->setFieldTx);
        $event->registerNode(node: $this->notifyTx);
        $event->registerNode(node: $this->besluitvormingActivateTx);
        $event->registerNode(node: $this->besluitvormingPublishTx);
        $event->registerNode(node: $this->evaluateDecisionTx);

    }//end handle()


}//end class
