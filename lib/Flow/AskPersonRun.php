<?php

/**
 * One execution of the askPerson flow node.
 *
 * Built once by `DossiqAskPersonNode::execute()` and handed through its private steps: the items, the
 * step configuration, the run context and this node's resume slot.
 *
 * @category Flow
 * @package  OCA\Dossiq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;

/**
 * One execution of the askPerson flow node.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
final class AskPersonRun {

	/**
	 * Constructor.
	 *
	 * @param array               $items   The input items; the first carries the case.
	 * @param array               $config  The step configuration.
	 * @param array               $context Run-level metadata.
	 * @param FlowNodeResumeState $resume  This node's resume slot.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $config,
		public readonly array $context,
		public readonly FlowNodeResumeState $resume,
	) {
	}//end __construct()
}//end class
