<?php

/**
 * Where one bulk upsert stores its Woo assessments, on which case, and by whom.
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
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Where one bulk upsert stores its Woo assessments, on which case, and by whom.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 */
final class AssessmentBatchTarget {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param mixed  $register      The register as configured.
	 * @param mixed  $schema        The Woo assessment schema as configured.
	 * @param string $userId        Who gives the verdicts.
	 * @param string $caseId        The case UUID.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly mixed $register,
		public readonly mixed $schema,
		public readonly string $userId,
		public readonly string $caseId,
	) {
	}//end __construct()
}//end class
