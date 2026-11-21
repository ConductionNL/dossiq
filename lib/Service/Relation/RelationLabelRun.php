<?php

/**
 * One read of a case's typed peer relations.
 *
 * Built by `CaseRelationLabels::rowsFor()` and never handed out: the case
 * being read, and its own `relatedCases` entries, which carry the
 * clarification of each link.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * The case and its stored entries one relation read labels against.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
final class RelationLabelRun {
	/**
	 * Constructor.
	 *
	 * @param string                           $caseId The case being read.
	 * @param array<int, array<string, mixed>> $stored The case's own `relatedCases` entries.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly array $stored,
	) {
	}//end __construct()
}//end class
