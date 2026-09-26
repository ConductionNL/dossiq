<?php

/**
 * Whether a derived status holds, and what is missing while it does not.
 *
 * Pure computation: the case is passed in, nothing is read from OpenRegister,
 * so the whole evaluation is exercisable without a register. That matters more
 * here than elsewhere, because "Complete never arrives" is the failure this
 * change exists to prevent and it is only findable by asking the evaluator
 * what it thinks is missing.
 *
 * The three condition kinds are OpenRegister's `lifecycleCondition`
 * vocabulary, which that app has not specified yet. When it does, this class
 * is deleted and the declarations stay where they are.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Status;

/**
 * Evaluates a status's declared conditions against a case.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class DerivedStatusEvaluator {

	/**
	 * The case properties a document may hang off, in the order they are read.
	 *
	 * The same four {@see \OCA\Dossiq\Service\Transitions\RequiredDocumentGuard}
	 * reads, and deliberately so: a document that satisfies a required-document
	 * guard has to satisfy a documentPresent condition, or the two halves of
	 * "the file is complete" disagree on the same case.
	 *
	 * @var array<int, string>
	 */
	private const DOCUMENT_FIELDS = [
		'documents',
		'files',
		'caseDocuments',
		'attachments',
	];

	/**
	 * Constructor.
	 *
	 * @param StatusDeclaration $declaration The reader over a statusType's declarations.
	 */
	public function __construct(
		private readonly StatusDeclaration $declaration,
	) {
	}//end __construct()

	/**
	 * Evaluate a status's declaration against a case.
	 *
	 * A status that declares NOTHING is not satisfied by this method, and that
	 * is not a quirk: an undeclared status is reached by a transition somebody
	 * picks, so asking whether it derives has no answer. Callers ask
	 * {@see StatusDeclaration::isDerived()} first.
	 *
	 * @param array<string, mixed> $statusType The statusType row.
	 * @param array<string, mixed> $case       The case.
	 *
	 * @return array{derived: bool, satisfied: bool, unmet: array<int, string>}
	 *         `derived` says whether the status declares anything at all,
	 *         `satisfied` whether every condition holds, and `unmet` names what
	 *         is missing, in declaration order.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $statusType, array $case): array {
		$conditions = $this->declaration->derivedWhen(statusType: $statusType);
		if ($conditions === []) {
			return ['derived' => false, 'satisfied' => false, 'unmet' => []];
		}

		$unmet = [];
		foreach ($conditions as $condition) {
			if ($this->holds(condition: $condition, case: $case) === true) {
				continue;
			}

			$unmet[] = $this->reasonFor(condition: $condition);
		}

		return ['derived' => true, 'satisfied' => $unmet === [], 'unmet' => $unmet];
	}//end evaluate()

	/**
	 * Whether one condition holds on this case.
	 *
	 * @param array<string, mixed> $condition The normalised condition.
	 * @param array<string, mixed> $case      The case.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function holds(array $condition, array $case): bool {
		$kind = (string)($condition['kind'] ?? '');

		if ($kind === 'documentPresent') {
			return $this->hasDocumentOfType(case: $case, documentType: (string)($condition['documentType'] ?? ''));
		}

		$field = (string)($condition['field'] ?? '');
		if ($field === '') {
			return false;
		}

		$value = $this->read(case: $case, path: $field);

		if ($kind === 'fieldEquals') {
			return $this->scalar(value: $value) === (string)($condition['value'] ?? '');
		}

		return $this->isPresent(value: $value);
	}//end holds()

	/**
	 * Whether the case carries a document of the named type.
	 *
	 * The type matches on its uuid OR on its slug, because a case type authored
	 * by hand names the slug and one published from a template carries the
	 * uuid. Refusing one of the two would make the same declaration work on one
	 * install and silently never fire on another.
	 *
	 * @param array<string, mixed> $case         The case.
	 * @param string               $documentType The type, as a uuid or a slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function hasDocumentOfType(array $case, string $documentType): bool {
		if ($documentType === '') {
			return false;
		}

		$wanted = strtolower(trim($documentType));
		foreach (self::DOCUMENT_FIELDS as $field) {
			$documents = ($case[$field] ?? null);
			if (is_array($documents) === false) {
				continue;
			}

			foreach ($documents as $document) {
				if (is_array($document) === false) {
					continue;
				}

				foreach (['documentType', 'type', 'documentTypeSlug'] as $key) {
					if (strtolower(trim((string)($document[$key] ?? ''))) === $wanted) {
						return true;
					}
				}
			}
		}

		return false;
	}//end hasDocumentOfType()

	/**
	 * Read a dotted property path off the case.
	 *
	 * A declaration names `aanvulling.antwoord` as often as it names `title`,
	 * because the answers a form writes land in an object. Walking the path is
	 * what keeps the vocabulary the same as the one every other declarative
	 * block in this app uses.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string               $path The dotted path.
	 *
	 * @return mixed The value, or null when the path resolves to nothing.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function read(array $case, string $path): mixed {
		$cursor = $case;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end read()

	/**
	 * Whether a read value counts as present.
	 *
	 * An empty string, an empty array and `false` all count as ABSENT. A case
	 * whose form field holds the empty string has not answered it, and a status
	 * that derived on that would be wrong in the direction that matters: it
	 * would say the file is complete.
	 *
	 * @param mixed $value The value read off the case.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function isPresent(mixed $value): bool {
		if ($value === null || $value === false) {
			return false;
		}

		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return true;
	}//end isPresent()

	/**
	 * A read value as the string a comparison is made against.
	 *
	 * @param mixed $value The value read off the case.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function scalar(mixed $value): string {
		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end scalar()

	/**
	 * What the handler is told is missing.
	 *
	 * The author's own label wins, because "the signed consent form" is
	 * actionable and "documentPresent failed" is not. When they wrote none, the
	 * condition names its own subject, which is still a thing rather than a
	 * rule.
	 *
	 * @param array<string, mixed> $condition The normalised condition.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function reasonFor(array $condition): string {
		$label = trim((string)($condition['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		$kind = (string)($condition['kind'] ?? '');
		if ($kind === 'documentPresent') {
			return (string)($condition['documentType'] ?? '');
		}

		return (string)($condition['field'] ?? '');
	}//end reasonFor()
}//end class
