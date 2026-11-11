<?php

/**
 * Dossiq document relation lookup.
 *
 * What looking for the relations that point at one document shares between
 * its steps.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

/**
 * One lookup: the object service it searches through and the document it looks for.
 *
 * Built by {@see DrcController} only; it never leaves it.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class EioRelationLookup {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $eioUuid       The document UUID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $eioUuid,
	) {
	}//end __construct()
}//end class
