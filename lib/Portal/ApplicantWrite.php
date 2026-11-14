<?php

/**
 * One write the applicant made on their case in the portal.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * One write the applicant made on their case in the portal.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class ApplicantWrite {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId     The case the write landed on.
	 * @param string               $act        `amendment`, `document`, `task-answer` or `answer`.
	 * @param array<int, string>   $fields     The field names the act touched.
	 * @param string               $occurredAt The moment of the write, ISO 8601.
	 * @param array<string, mixed> $mandate    The mandate the write ran under, or none.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $act,
		public readonly array $fields,
		public readonly string $occurredAt,
		public readonly array $mandate,
	) {
	}//end __construct()
}//end class
