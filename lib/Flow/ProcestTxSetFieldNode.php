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
use OCA\Dossiq\Service\Transitions\SetFieldHandler;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Flow node for the live `setField` transition action.
 *
 * A thin wrapper: SetFieldHandler keeps the logic. This is the vocabulary
 * SideEffectDispatcher actually fires on every status change, which is why it
 * takes the plain `procest.setField` id rather than the `procest.action.*`
 * prefix the configured-action catalogue uses.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class ProcestTxSetFieldNode extends ProcestTransitionNode {


    /**
     * Constructor.
     *
     * @param SetFieldHandler $handler The transition handler this node runs.
     * @param IL10N         $l10n    The localisation service.
     * @param IURLGenerator $urls    The URL generator.
     *
     * @return void
     */
    public function __construct(
        private readonly SetFieldHandler $handler,
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
        return 'procest.setField';

    }//end nodeId()


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    protected function requiredConfigKeys(): array {
        return ['field'];

    }//end requiredConfigKeys()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Set field');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Write a value onto the case as part of the transition.');

    }//end getDescription()


}//end class
