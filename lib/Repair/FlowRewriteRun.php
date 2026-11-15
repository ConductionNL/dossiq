<?php

/**
 * One run of the retired flow rewrite: OpenRegister's flow mapper and flow
 * version service, and the output the run reports to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;

/**
 * What one RewriteRetiredFlowNodes run reads and writes flows through.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class FlowRewriteRun {

	/**
	 * Constructor.
	 *
	 * @param object  $mapper   OpenRegister's FlowMapper.
	 * @param object  $versions OpenRegister's FlowVersionService.
	 * @param IOutput $output   Output sink.
	 */
	public function __construct(
		public readonly object $mapper,
		public readonly object $versions,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
