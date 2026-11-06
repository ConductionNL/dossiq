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
use OCA\Dossiq\Service\Transitions\SuspendTermHandler;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Flow node that suspends the case's term (`dossiq.suspendTerm`).
 *
 * A thin wrapper: SuspendTermHandler keeps the logic, and DeadlinePauseService
 * applies the case type's suspension rules. A macro such as "Opschorten voor
 * aanvulling" uses it (DqZaakAssistent).
 *
 * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
 */
class DossiqTxSuspendTermNode extends DossiqTransitionNode {


    /**
     * Constructor.
     *
     * @param SuspendTermHandler $handler The handler this node runs.
     * @param IL10N         $l10n    The localisation service.
     * @param IURLGenerator $urls    The URL generator.
     *
     * @return void
     */
    public function __construct(
        private readonly SuspendTermHandler $handler,
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
        return 'dossiq.suspendTerm';

    }//end nodeId()


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    protected function requiredConfigKeys(): array {
        return ['durationDays'];

    }//end requiredConfigKeys()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Suspend the term');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
     */
    public function getDescription(): string {
        return $this->l10n->t('Suspend the case term for a number of days, under the case type\'s rules for suspending.');

    }//end getDescription()


}//end class
