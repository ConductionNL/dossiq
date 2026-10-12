<?php

/**
 * Dossiq Decision Document Validator.
 *
 * Lists what keeps a decision document from being approved: missing fields, a missing remedy clause, and rules that do not hold.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
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
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The validity check for a decision document of any kind.
 *
 * RULES ARE OPENREGISTER NAMED CONDITIONS. A rule's `when` is a condition
 * node in OpenRegister's dialect and may reference the definition's named
 * conditions by `$condition`. A rule that cannot be evaluated (OpenRegister
 * absent, a reference that does not resolve) is reported as a problem, never
 * passed: a check that could not run is not a check that passed (ADR-005).
 *
 * @spec openspec/specs/decision-document/spec.md
 */
class DecisionDocumentValidator {

	/**
	 * OpenRegister's named-condition evaluator, resolved lazily: dossiq runs without it.
	 *
	 * @var string
	 */
	private const EVALUATOR = 'OCA\OpenRegister\Service\Rules\NamedConditionEvaluator';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's evaluator.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * What keeps the document from being approved.
	 *
	 * @param DecisionDocumentDefinition $definition The kind's definition.
	 * @param array<string, mixed> $document The document.
	 *
	 * @return array<int, array{field: string, rule: string, message: string}> The problems; empty when valid.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function problems(DecisionDocumentDefinition $definition, array $document): array {
		$problems = [];
		foreach ($definition->requiredFields as $field) {
			if ($this->isEmpty(value: ($document[$field] ?? null)) === true) {
				$problems[] = ['field' => $field, 'rule' => 'required', 'message' => sprintf('%s is required.', $field)];
			}
		}

		if ($definition->remedyRequired === true && $this->isEmpty(value: ($document['remedyClause'] ?? null)) === true) {
			$problems[] = ['field' => 'remedyClause', 'rule' => 'remedy-clause-required', 'message' => 'The decision must state how to object or appeal.'];
		}

		foreach ($definition->rules as $rule) {
			$holds = $this->holds(rule: $rule, document: $document, library: $definition->conditions);
			if ($holds === true) {
				continue;
			}

			$message = $rule['message'];
			if ($holds === null) {
				$message = sprintf('Rule %s could not be checked, so the decision cannot be approved yet.', $rule['id']);
			}

			$problems[] = ['field' => '', 'rule' => $rule['id'], 'message' => $message];
		}

		return $problems;
	}//end problems()

	/**
	 * Whether a rule holds, or null when it cannot be evaluated.
	 *
	 * @param array{id: string, when: mixed, message: string} $rule The rule.
	 * @param array<string, mixed> $document The document.
	 * @param array<string, mixed> $library The named conditions.
	 *
	 * @return bool|null True, false, or null when unevaluable.
	 */
	private function holds(array $rule, array $document, array $library): ?bool {
		try {
			$evaluator = $this->container->get(self::EVALUATOR);
			return (bool)$evaluator->holds($rule['when'], $document, $library);
		} catch (Throwable $unevaluable) {
			return null;
		}
	}//end holds()

	/**
	 * Whether a value counts as not filled.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for null, a blank string and [].
	 */
	private function isEmpty(mixed $value): bool {
		return $value === null || $value === [] || (is_string($value) === true && trim($value) === '');
	}//end isEmpty()
}//end class
