<?php

/**
 * Dossiq SavedMailFile.
 *
 * The saved mail file one import reads: the case it is on and its name.
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * A saved mail file on a case.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class SavedMailFile {
	/**
	 * Name a file on a case.
	 *
	 * @param string $caseId   The case the file is on.
	 * @param string $fileName The file's name.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $fileName,
	) {
	}//end __construct()
}//end class
