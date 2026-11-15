<?php

/**
 * Whether one user may access one case, with where that case is stored.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

/**
 * Whether one user may access one case, with where that case is stored.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
final class CaseAccessQuestion {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The configured register id.
	 * @param string $caseSchema    The configured case schema id.
	 * @param string $caseId        The case UUID.
	 * @param string $userId        The caller's user ID.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseSchema,
		public readonly string $caseId,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
