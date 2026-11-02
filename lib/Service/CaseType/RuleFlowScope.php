<?php

/**
 * The case type a rule's flows belong to.
 *
 * What every flow of one case type shares: the case type id and title, the
 * register and schema its cases live in, and its property definition names.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * The case type, register, schema and definition names of one projection.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
final class RuleFlowScope {

	/**
	 * The register slug cases live in.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The case schema slug.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * Constructor.
	 *
	 * @param string                                  $caseTypeId      The case type uuid.
	 * @param string                                  $caseTypeTitle   The case type's title, for the flow's name.
	 * @param array{register: string, schema: string} $cases           Where cases live: the register and case schema slugs.
	 * @param array<int, string>                      $definitionNames The case type's property definition names, inherited ones included.
	 */
	public function __construct(
		public readonly string $caseTypeId,
		public readonly string $caseTypeTitle,
		array $cases,
		public readonly array $definitionNames = [],
	) {
		$this->register = $cases['register'];
		$this->schema = $cases['schema'];
	}//end __construct()
}//end class
