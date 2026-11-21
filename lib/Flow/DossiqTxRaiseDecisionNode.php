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

use OCA\Dossiq\Flow\Support\RunsInjectedHandler;
use OCA\Dossiq\Kernel\Contracts\RaiseDecisionHandlerInterface;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Flow node that raises a delegated decision for the case (`dossiq.raiseDecision`).
 *
 * A thin wrapper: RaiseDecisionHandler keeps the logic, and
 * ContractDecisionDelegationService hands the decision to the decision app.
 * The aanbesteding flow uses it to hand a final award on to the contract.
 *
 * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
 */
class DossiqTxRaiseDecisionNode extends DossiqTransitionNode {

    use RunsInjectedHandler;

    /**
     * Constructor.
     *
     * @param RaiseDecisionHandlerInterface $handler The handler this node runs.
     * @param IL10N         $l10n    The localisation service.
     * @param IURLGenerator $urls    The URL generator.
     *
     * @return void
     */
    public function __construct(
        private readonly RaiseDecisionHandlerInterface $handler,
        IL10N $l10n,
        IURLGenerator $urls,
    ) {
        parent::__construct(l10n: $l10n, urls: $urls);

    }//end __construct()


    /**
     * This node's id.
     *
     * @return string The namespaced node id.
     */
    protected function nodeId(): string {
        return 'dossiq.raiseDecision';

    }//end nodeId()


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    protected function requiredConfigKeys(): array {
        return ['decisionType'];

    }//end requiredConfigKeys()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Raise a decision');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
     */
    public function getDescription(): string {
        return $this->l10n->t('Hand the case to the decision app as a decision of the configured type, such as the contract that follows an award.');

    }//end getDescription()


}//end class
