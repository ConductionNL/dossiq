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

/**
 * Base for the LIVE transition-action nodes.
 *
 * `lib/Service/Transitions/` — the nine actions SideEffectDispatcher fires on
 * every case status change. They take the plain `procest.*` id space because
 * they are the vocabulary that actually runs.
 *
 * The subclass states its own id: these handlers carry no `type()` of their
 * own — ActionHandlerRegistry maps them by key — so there is nothing to derive
 * from.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
abstract class ProcestTransitionNode extends ProcestFlowNodeBase {

}//end class
