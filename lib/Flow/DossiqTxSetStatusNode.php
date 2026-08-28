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

use OCA\Dossiq\Service\Actions\ActionHandlerInterface as CatalogueActionHandler;
use OCA\Dossiq\Service\Transitions\ActionHandlerInterface as TransitionActionHandler;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Flow node for the `setStatus` transition action.
 *
 * A thin wrapper: SetStatusHandler keeps the logic.
 *
 * Distinct from `dossiq.setField` because `status` is not an ordinary field. It
 * is a reference to a `statusType` whose uuid is minted per installation, so a
 * SHIPPED flow can only name the status — never carry its id. This node takes
 * that name and the handler resolves it inside the case's own case type.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */
class DossiqTxSetStatusNode extends DossiqTransitionNode {


    /**
     * Constructor.
     *
     * @param SetStatusHandler $handler The transition handler this node runs.
     * @param IL10N            $l10n    The localisation service.
     * @param IURLGenerator    $urls    The URL generator.
     *
     * @return void
     */
    public function __construct(
        private readonly SetStatusHandler $handler,
        IL10N $l10n,
        IURLGenerator $urls,
    ) {
        parent::__construct(l10n: $l10n, urls: $urls);

    }//end __construct()


    /**
     * The handler this node runs.
     *
     * @return CatalogueActionHandler|TransitionActionHandler The action handler.
     */
    protected function handler(): CatalogueActionHandler|TransitionActionHandler {
        return $this->handler;

    }//end handler()


    /**
     * This node's id.
     *
     * @return string The namespaced node id.
     */
    protected function nodeId(): string {
        return 'dossiq.setStatus';

    }//end nodeId()


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    protected function requiredConfigKeys(): array {
        return ['status'];

    }//end requiredConfigKeys()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Set case status');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Move the case to a status of its case type, named rather than referenced by id.');

    }//end getDescription()


}//end class
