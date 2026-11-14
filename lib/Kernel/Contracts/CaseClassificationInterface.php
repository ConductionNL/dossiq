<?php

/**
 * Contract of CaseClassification
 *
 * The public surface of CaseClassification that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of CaseClassification.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseClassificationInterface {

	/**
	 * The declaration this case type carries.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{scheme: string, classificationIsAccessRule: bool, facets: array<int, string>}
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 */
	public function declarationFor(array $caseType): array;

	/**
	 * Refuse this creation when the classification cannot do its job.
	 *
	 * Three refusals, in the order they can be decided. The scheme goes first,
	 * because a case type whose scheme does not resolve cannot judge the value
	 * it was given either, and answering "your classification is wrong" when
	 * the truth is "this instance does not know the scheme" sends an
	 * administrator looking in the wrong place.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the scheme, or the classification, refuses.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 */
	public function assertCreatable(array $case, array $caseType): void;
}//end interface
