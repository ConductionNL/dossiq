<?php

/**
 * One dossier archive while its documents are written into it.
 *
 * Built by `ZipManifestBuilder::buildZip()` and never handed out: it holds
 * the open archive, the layout and the entry names used so far.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use ZipArchive;

/**
 * The state of one dossier archive being written.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
final class DossierZipRun {
	/**
	 * Entry names used so far, with how often each was taken.
	 *
	 * @var array<string, int>
	 */
	public array $usedNames = [];

	/**
	 * Constructor.
	 *
	 * @param ZipArchive $zip    The open archive.
	 * @param string     $layout ZipManifestBuilder::LAYOUT_PER_TYPE or ZipManifestBuilder::LAYOUT_FLAT.
	 */
	public function __construct(
		public readonly ZipArchive $zip,
		public readonly string $layout,
	) {
	}//end __construct()
}//end class
