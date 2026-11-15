<?php

/**
 * Dossiq saved mail read.
 *
 * What reading one saved mail file onto a case shares between its steps.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCP\Files\File;

/**
 * One saved mail file being read onto a case.
 *
 * Built by {@see SavedMailController} only; it never leaves it.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class SavedMailRead {

	/**
	 * The case as the route named it.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The file id as the route named it.
	 *
	 * @var integer
	 */
	public int $fileId = 0;

	/**
	 * The file.
	 *
	 * @var File
	 */
	public File $node;
}//end class
