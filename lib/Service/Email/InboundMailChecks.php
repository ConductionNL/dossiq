<?php

/**
 * The checks an inbound mail passes before it is filed: the filter pipeline, the authentication verdict and threading.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Email\Filters\FilterPipeline;

/**
 * The checks an inbound mail passes before it is filed: the filter pipeline, the authentication verdict and threading.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class InboundMailChecks {
	/**
	 * Constructor.
	 *
	 * @param FilterPipeline        $pipeline  The declared filter order.
	 * @param AuthenticationVerdict $verdicts  The four authentication results.
	 * @param ThreadingCheck        $threading The threading claim checker.
	 */
	public function __construct(
		public readonly FilterPipeline $pipeline,
		public readonly AuthenticationVerdict $verdicts,
		public readonly ThreadingCheck $threading,
	) {
	}//end __construct()
}//end class
