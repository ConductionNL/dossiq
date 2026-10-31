<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Flow
 * @package   OCA\Dossiq\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use OCA\Dossiq\Service\Transitions\ActionHandlerInterface as TransitionActionHandler;
use OCA\Dossiq\Service\Transitions\SendTemplateMessageHandler;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Flow node: send one of the case type's message templates to a role on the case.
 *
 * A thin wrapper over SendTemplateMessageHandler. A case type rule's
 * sendMessage action is projected onto a flow ending in this step.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class DossiqTxSendMessageNode extends DossiqTransitionNode {


    /**
     * Constructor.
     *
     * @param SendTemplateMessageHandler $handler The handler this node runs.
     * @param IL10N                      $l10n    The localisation service.
     * @param IURLGenerator              $urls    The URL generator.
     *
     * @return void
     */
    public function __construct(
        private readonly SendTemplateMessageHandler $handler,
        IL10N $l10n,
        IURLGenerator $urls,
    ) {
        parent::__construct(l10n: $l10n, urls: $urls);

    }//end __construct()


    /**
     * The handler this node runs.
     *
     * @return TransitionActionHandler The action handler.
     */
    protected function handler(): TransitionActionHandler {
        return $this->handler;

    }//end handler()


    /**
     * This node's id.
     *
     * @return string The namespaced node id.
     */
    protected function nodeId(): string {
        return 'dossiq.sendMessage';

    }//end nodeId()


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    protected function requiredConfigKeys(): array {
        return ['emailTemplate'];

    }//end requiredConfigKeys()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Send a message');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
     */
    public function getDescription(): string {
        return $this->l10n->t('Send one of the case type\'s message templates to a role on the case.');

    }//end getDescription()


}//end class
