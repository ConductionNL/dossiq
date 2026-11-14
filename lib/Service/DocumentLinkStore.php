<?php

/**
 * Where a case's document links are read and written: the object service, register and caseDocument schema.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Where a case's document links are read and written: the object service, register and caseDocument schema.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class DocumentLinkStore {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register slug.
	 * @param string $schema        The caseDocument schema.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
