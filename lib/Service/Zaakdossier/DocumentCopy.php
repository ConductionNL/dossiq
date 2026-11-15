<?php

/**
 * Dossiq DocumentCopy
 *
 * One document to copy, who asks, and the case it goes to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/specs/documents-copy-to-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCP\IUser;

/**
 * The caller, the document and the target case of one copy.
 *
 * @spec openspec/specs/documents-copy-to-case/spec.md
 */
final class DocumentCopy {
	/**
	 * Constructor.
	 *
	 * @param IUser  $user         The caller.
	 * @param string $id           The document id.
	 * @param string $targetCaseId The case the copy goes to.
	 */
	public function __construct(
		public readonly IUser $user,
		public readonly string $id,
		public readonly string $targetCaseId,
	) {
	}//end __construct()
}//end class
