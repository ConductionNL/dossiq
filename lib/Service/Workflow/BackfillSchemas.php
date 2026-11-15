<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

/**
 * The schemas a workflow backfill reads and writes.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
final class BackfillSchemas {

	/**
	 * Constructor.
	 *
	 * @param string $caseType The case type schema.
	 * @param string $status   The status type schema.
	 * @param string $template The workflow template schema.
	 * @param string $cases    The case schema, or '' when open cases are not pinned.
	 */
	public function __construct(
		public readonly string $caseType,
		public readonly string $status,
		public readonly string $template,
		public readonly string $cases,
	) {
	}//end __construct()
}//end class
