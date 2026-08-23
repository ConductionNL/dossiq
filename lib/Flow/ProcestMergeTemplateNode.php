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
use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Flow node for the `mergeTemplate` action.
 *
 * A thin wrapper: MergeTemplateHandler keeps the logic, this presents it to
 * OpenRegister's engine. See ProcestActionNode for why these are contributed
 * nodes rather than a mapping onto OpenRegister's own.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class ProcestMergeTemplateNode extends ProcestActionNode {


    /**
     * Constructor.
     *
     * @param MergeTemplateHandler $handler The action handler this node runs.
     * @param IL10N              $l10n    The localisation service.
     * @param IURLGenerator      $urls    The URL generator.
     *
     * @return void
     */
    public function __construct(
        private readonly MergeTemplateHandler $handler,
        IL10N $l10n,
        IURLGenerator $urls,
    ) {
        parent::__construct(l10n: $l10n, urls: $urls);

    }//end __construct()


    /**
     * The handler this node runs.
     *
     * @return CatalogueActionHandler The action handler.
     */
    protected function handler(): CatalogueActionHandler|TransitionActionHandler {
        return $this->handler;

    }//end handler()


    /**
     * Config keys without which this action cannot run.
     *
     * @return string[] The required key names.
     */
    protected function requiredConfigKeys(): array {
        return ['templateSlug', 'targetField'];

    }//end requiredConfigKeys()


    /**
     * The node's display name.
     *
     * @return string The translated name.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function getDisplayName(): string {
        return $this->l10n->t('Merge template');

    }//end getDisplayName()


    /**
     * What the node does.
     *
     * @return string The translated description.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function getDescription(): string {
        return $this->l10n->t('Render a template and write the result into a case field.');

    }//end getDescription()


}//end class
