<?php

/**
 * Dossiq PackageImport.
 *
 * One case definition package being imported: the opened archive and the
 * conflict strategy every component is written with.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use ZipArchive;

/**
 * An opened package and how its conflicts are resolved.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class PackageImport {
	/**
	 * Name one import.
	 *
	 * @param ZipArchive $zip      The opened package.
	 * @param string     $strategy `skip`, `overwrite` or `merge`.
	 */
	public function __construct(
		public readonly ZipArchive $zip,
		public readonly string $strategy,
	) {
	}//end __construct()
}//end class
