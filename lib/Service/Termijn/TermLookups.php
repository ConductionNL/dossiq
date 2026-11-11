<?php

/**
 * Dossiq TermLookups
 *
 * The cases, case types and term definitions a batch of terms names, read once.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * Three batched reads, each by id.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
final class TermLookups {
	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $cases       The cases, by id.
	 * @param array<string, array<string, mixed>> $caseTypes   Their case types, by id.
	 * @param array<string, array<string, mixed>> $definitions The term definitions, by id.
	 */
	public function __construct(
		public readonly array $cases,
		public readonly array $caseTypes,
		public readonly array $definitions,
	) {
	}//end __construct()
}//end class
