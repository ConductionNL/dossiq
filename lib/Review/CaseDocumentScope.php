<?php

/**
 * The case whose documents are being read, and where they are read from.
 *
 * Built by `OutstandingDocuments` once per call and handed through its private steps: OpenRegister's
 * object service, the configured register and the case.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

/**
 * The case whose documents are being read, and where they are read from.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 */
final class CaseDocumentScope {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param mixed  $register      Configured register identifier.
	 * @param string $caseId        The case UUID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly mixed $register,
		public readonly string $caseId,
	) {
	}//end __construct()
}//end class
