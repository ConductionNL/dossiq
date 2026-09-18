<?php

/**
 * Which term a case actually gets, and why.
 *
 * Every term dossiq knew was one number on one case type. A gemeenschappelijke
 * regeling runs one case type for five municipalities that agreed different
 * service norms, so either the norms were wrong or the case type was
 * duplicated five times and drifted. The same shape appears inside one
 * organisation the moment a service is offered at two levels, and again once
 * priority is derived and an urgent case ought to promise less time.
 *
 * So a case type may carry several definitions, and this class picks between
 * them in ONE declared order: the organisation, then the service, then the
 * priority, then the case type's own term. Any order works as long as it is
 * one order everybody can read; leaving it implicit is how two municipalities
 * end up with the same configuration and different dates.
 *
 * 🔴 THE RESOLUTION IS RECORDED, NOT RE-DERIVED. A term somebody disputes has
 * to be explainable a year later, and the configuration will have changed by
 * then. `snapshotOf()` copies what the decision was made from at the moment it
 * was made, so the explanation does not depend on the configuration as it
 * stands now.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;

/**
 * Resolves a term definition for a case, and says which rule produced it.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-resolves-from-the-organisation-the-service-and-the-priority-req-tcf-02
 */
class TermResolution {
	/**
	 * The order, most specific first. It is a constant rather than a setting
	 * because the point of the change is that there is ONE order and everybody
	 * can read it.
	 *
	 * @var array<int, string>
	 */
	public const ORDER = ['organisation', 'service', 'priority'];

	/**
	 * What a resolution answers when nothing more specific was declared.
	 */
	public const FALLBACK = 'caseType';

	/**
	 * Constructor.
	 *
	 * @param TermijnService $terms The store of term definitions.
	 */
	public function __construct(private readonly TermijnService $terms) {
	}//end __construct()

	/**
	 * The definition a case gets, with the rule that produced it.
	 *
	 * @param string               $caseType The zaaktype slug.
	 * @param array<string, mixed> $context  The case's organisation, service and priority.
	 * @param string               $kind     Which clock is being resolved.
	 *
	 * @return array{definition: array<string, mixed>, resolvedFrom: string, snapshot: array<string, mixed>}|null
	 *         The resolution, or null when the case type declares no term at all.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-resolves-from-the-organisation-the-service-and-the-priority-req-tcf-02
	 */
	public function resolve(string $caseType, array $context, string $kind = TermKind::STATUTORY): ?array {
		$candidates = $this->ofKind(rows: $this->terms->definitionsFor(caseType: $caseType), kind: $kind);
		if ($candidates === []) {
			return null;
		}

		foreach (self::ORDER as $field) {
			$wanted = trim((string)($context[$field] ?? ''));
			if ($wanted === '') {
				continue;
			}

			foreach ($candidates as $candidate) {
				if (trim((string)($candidate[$field] ?? '')) === $wanted) {
					return $this->answer(
						definition: $candidate,
						resolvedFrom: $field,
						caseType: $caseType,
						context: $context
					);
				}
			}
		}

		// The case type's own term: the one declaration that names no
		// organisation, service or priority. A definition that names one and
		// did not match is NOT a fallback, because falling back to somebody
		// else's agreed norm is worse than having none.
		foreach ($candidates as $candidate) {
			if ($this->isGeneral(definition: $candidate) === true) {
				return $this->answer(
					definition: $candidate,
					resolvedFrom: self::FALLBACK,
					caseType: $caseType,
					context: $context
				);
			}
		}

		return null;
	}//end resolve()

	/**
	 * The definitions of one kind. An absent kind reads as statutory, because
	 * every definition written before the kinds existed sets that clock.
	 *
	 * @param array<int, array<string, mixed>> $rows The candidates.
	 * @param string                           $kind The kind wanted.
	 *
	 * @return array<int, array<string, mixed>> The candidates of that kind.
	 */
	private function ofKind(array $rows, string $kind): array {
		$of = [];
		foreach ($rows as $row) {
			$declared = trim((string)($row['kind'] ?? ''));
			if ($declared === '') {
				$declared = TermKind::STATUTORY;
			}

			if ($declared === $kind) {
				$of[] = $row;
			}
		}

		return $of;
	}//end ofKind()

	/**
	 * Whether a definition is the case type's own, naming no narrower scope.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return bool True when it names no organisation, service or priority.
	 */
	private function isGeneral(array $definition): bool {
		foreach (self::ORDER as $field) {
			if (trim((string)($definition[$field] ?? '')) !== '') {
				return false;
			}
		}

		return true;
	}//end isGeneral()

	/**
	 * One resolution, with the snapshot that explains it later.
	 *
	 * @param array<string, mixed> $definition   The chosen definition.
	 * @param string               $resolvedFrom The rule that chose it.
	 * @param string               $caseType     The zaaktype slug.
	 * @param array<string, mixed> $context      The case's own values.
	 *
	 * @return array{definition: array<string, mixed>, resolvedFrom: string, snapshot: array<string, mixed>}
	 */
	private function answer(array $definition, string $resolvedFrom, string $caseType, array $context): array {
		return [
			'definition' => $definition,
			'resolvedFrom' => $resolvedFrom,
			'snapshot' => [
				'caseType' => $caseType,
				'organisation' => trim((string)($context['organisation'] ?? '')),
				'service' => trim((string)($context['service'] ?? '')),
				'priority' => trim((string)($context['priority'] ?? '')),
				'durationDays' => (int)($definition['standardDurationDays'] ?? 0),
				'deadlineDefinition' => (string)($definition['id'] ?? ''),
				'resolvedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
			],
		];
	}//end answer()
}//end class
