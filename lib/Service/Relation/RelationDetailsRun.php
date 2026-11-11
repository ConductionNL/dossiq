<?php

/**
 * One pass describing the related cases a reader may open.
 *
 * Built by `CaseRelationDetails::describe()` and never handed out: the
 * reader's read check, and the case type titles read so far so each is read
 * once.
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
 * The state of one related-cases description.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
final class RelationDetailsRun {
	/**
	 * Case type titles by case type id, as read so far.
	 *
	 * @var array<string, string>
	 */
	public array $caseTypeTitles = [];

	/**
	 * Constructor.
	 *
	 * @param callable(string): bool $canRead Whether the reader may open a case.
	 */
	public function __construct(
		public readonly mixed $canRead,
	) {
	}//end __construct()
}//end class
