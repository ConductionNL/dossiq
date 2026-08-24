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

/**
 * Base for the CONFIGURED-ACTION catalogue nodes.
 *
 * `lib/Service/Actions/` — the six reusable actions administered as
 * `automaticAction` objects. They take the `procest.action.*` id space, because
 * the live transition vocabulary owns the plain names and both systems ship a
 * `sendEmail`.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
abstract class ProcestActionNode extends ProcestFlowNodeBase {

	/**
	 * This node's id.
	 *
	 * Derived from the handler's own type slug, so it cannot drift from the
	 * handler it runs.
	 *
	 * @return string The namespaced node id.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
	 */
	protected function nodeId(): string {
		return 'procest.action.' . $this->handler()->type();
	}//end nodeId()

}//end class
