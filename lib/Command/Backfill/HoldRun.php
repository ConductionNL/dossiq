<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command\Backfill;

/**
 * One legal-hold backfill run: the OpenRegister services it uses and the operator's options.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
final class HoldRun {
	/**
	 * Constructor.
	 *
	 * @param object $objectMapper     OpenRegister object mapper.
	 * @param object $legalHoldService OpenRegister legal hold service.
	 * @param bool   $apply            Whether to write.
	 * @param bool   $includeDeleted   Whether soft-deleted cases are in scope.
	 */
	public function __construct(
		public readonly object $objectMapper,
		public readonly object $legalHoldService,
		public readonly bool $apply,
		public readonly bool $includeDeleted,
	) {
	}//end __construct()
}//end class
