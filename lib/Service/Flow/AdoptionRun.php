<?php

/**
 * One adoption pass over the shipped flows.
 *
 * Built by `ShippedFlowAdoption::adoptAll()` and never handed out: the
 * FlowService, the adopting user, and whether to enable and whether to write.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * What one adoption pass adopts with.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class AdoptionRun {
	/**
	 * Also arm the trigger by enabling each adopted flow.
	 *
	 * @var boolean
	 */
	public bool $enable = false;

	/**
	 * Report what would happen and write nothing.
	 *
	 * @var boolean
	 */
	public bool $dryRun = false;

	/**
	 * Constructor.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param string $uid         The adopting user.
	 */
	public function __construct(
		public readonly object $flowService,
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
