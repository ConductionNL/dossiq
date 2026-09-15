<?php

/**
 * Dossiq case classification declaration.
 *
 * Reads the `caseClassification` declaration off a case type and answers two
 * questions: which of the four facets a case of this type records, and whether
 * the case may exist without a classification.
 *
 * OPENCASE'S CLAUSE IS THE TEST. "The classification is the access rule here,
 * and an unclassified case is unreachable rather than merely untidy." So where
 * a case type marks its classification an access rule, an unclassified case is
 * not created at all. That is the one place the intake declaration refuses
 * outright rather than recording an incomplete case, and it is refused for the
 * same reason a case with no reachable handler would be.
 *
 * ADR-102 decides the second refusal. A case type naming a classification
 * scheme this instance cannot resolve refuses creation and names the scheme,
 * because a rule that cannot be compiled is not a rule that lets everybody in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;

/**
 * The four facets a case type declares, and the one that gates creation.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */
class CaseClassification {

	/**
	 * The case-type property holding the declaration.
	 */
	public const PROPERTY = 'caseClassification';

	/**
	 * The case field the classification itself lands on.
	 */
	public const FIELD_CLASSIFICATION = 'classification';

	/**
	 * The four facets, in the order a form asks for them.
	 *
	 * @var array<int, string>
	 */
	public const FACETS = ['classification', 'sensitivity', 'actionFacet', 'insightLevel'];

	/**
	 * The rule a creation refused for a missing classification names.
	 */
	public const RULE_UNCLASSIFIED = 'classification-is-the-access-rule';

	/**
	 * The rule a creation refused for an unresolvable scheme names.
	 */
	public const RULE_SCHEME_UNRESOLVED = 'classification-scheme-does-not-resolve';

	/**
	 * The rule a creation refused for a value outside the scheme names.
	 */
	public const RULE_VALUE_OUTSIDE_SCHEME = 'classification-outside-its-scheme';

	/**
	 * Constructor.
	 *
	 * @param ClassificationSchemes $schemes The schemes this instance knows.
	 */
	public function __construct(
		private readonly ClassificationSchemes $schemes,
	) {
	}//end __construct()

	/**
	 * The declaration this case type carries.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{scheme: string, classificationIsAccessRule: bool, facets: array<int, string>}
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function declarationFor(array $caseType): array {
		$declared = ($caseType[self::PROPERTY] ?? null);
		if (is_array($declared) === false) {
			$declared = [];
		}

		$accessRule = (($declared['classificationIsAccessRule'] ?? false) === true);
		$facets = $this->facetList(value: ($declared['facets'] ?? []));

		// A classification that is the access rule is a declared facet whether
		// or not the list says so. Leaving it off the list while marking it an
		// access rule is a contradiction, and the access rule is the half that
		// decides who can reach the case.
		if ($accessRule === true && in_array(self::FIELD_CLASSIFICATION, $facets, true) === false) {
			array_unshift($facets, self::FIELD_CLASSIFICATION);
		}

		return [
			'scheme' => trim((string)($declared['scheme'] ?? '')),
			'classificationIsAccessRule' => $accessRule,
			'facets' => $facets,
		];
	}//end declarationFor()

	/**
	 * The facets a case of this type records.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The declared facets.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function facetsFor(array $caseType): array {
		return $this->declarationFor(caseType: $caseType)['facets'];
	}//end facetsFor()

	/**
	 * The facet values this case carries, for the facets its type declares.
	 *
	 * A facet the case type does not declare is not read, so a stray value
	 * left on the case by an import does not turn up on screen as though the
	 * case type had asked for it.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<string, string> Facet name to value, declared facets only.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function facetValues(array $case, array $caseType): array {
		$values = [];
		foreach ($this->facetsFor(caseType: $caseType) as $facet) {
			$values[$facet] = trim((string)($case[$facet] ?? ''));
		}

		return $values;
	}//end facetValues()

	/**
	 * Whether a case of this type may exist without a classification.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when the classification gates creation.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function classificationGatesCreation(array $caseType): bool {
		return $this->declarationFor(caseType: $caseType)['classificationIsAccessRule'];
	}//end classificationGatesCreation()

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
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function assertCreatable(array $case, array $caseType): void {
		$declaration = $this->declarationFor(caseType: $caseType);
		$gates = $declaration['classificationIsAccessRule'];
		$scheme = $declaration['scheme'];
		$value = trim((string)($case[self::FIELD_CLASSIFICATION] ?? ''));

		if ($gates === true && $this->schemes->resolves(scheme: $scheme) === false) {
			throw new RefusedException(
				rule: self::RULE_SCHEME_UNRESOLVED,
				sentence: 'This case type classifies against ' . $scheme
					. ', and this instance does not know that scheme.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($gates === true && $value === '') {
			throw new RefusedException(
				rule: self::RULE_UNCLASSIFIED,
				sentence: 'The classification decides who can reach a case of this type, '
					. 'so the case is not created without one.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($value !== '' && $this->schemes->allows(scheme: $scheme, value: $value) === false) {
			throw new RefusedException(
				rule: self::RULE_VALUE_OUTSIDE_SCHEME,
				sentence: 'The classification ' . $value . ' is not one this case type\'s scheme '
					. $scheme . ' carries.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertCreatable()

	/**
	 * One declared facet list, cleaned of anything that is not a facet.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, string> The facet names, in the canonical order.
	 */
	private function facetList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$facets = [];
		foreach (self::FACETS as $facet) {
			if (in_array($facet, $value, true) === true) {
				$facets[] = $facet;
			}
		}

		return $facets;
	}//end facetList()
}//end class
