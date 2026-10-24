<?php

/**
 * A case type rule's conditions as one JSONLogic expression over the case.
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
 * Translates the rule condition vocabulary into the flow engine's JSONLogic.
 *
 * Pure. The expression is evaluated by OpenRegister's flow filter against the
 * case the trigger fired on, which the filter reads under `json`. Dossiq writes
 * the expression and evaluates nothing.
 *
 * A field names either a top-level case property or one of the case type's
 * property definitions, whose answers the case keeps in `properties` as
 * `{ name, value }` entries. Both are read, so a rule does not have to know
 * which of the two a field is.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class RuleConditionLogic {

	/**
	 * The case properties a document may hang off, as DerivedStatusEvaluator reads them.
	 *
	 * @var array<int, string>
	 */
	private const DOCUMENT_FIELDS = ['documents', 'files', 'caseDocuments', 'attachments'];

	/**
	 * Comparison operators by their rule name.
	 *
	 * @var array<string, string>
	 */
	private const OPERATORS = [
		'eq' => '==',
		'neq' => '!=',
		'gt' => '>',
		'gte' => '>=',
		'lt' => '<',
		'lte' => '<=',
	];

	/**
	 * Every condition of a rule, plus the case type guard, as one expression.
	 *
	 * The case type guard comes first: the trigger fires for every case, and
	 * a rule of one case type must never act on a case of another.
	 *
	 * @param string $caseTypeId The case type the rule belongs to.
	 * @param array<int, mixed> $conditions The rule's conditions.
	 *
	 * @return array<string, mixed> The JSONLogic expression.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function forRule(string $caseTypeId, array $conditions): array {
		$parts = [['==' => [['var' => 'json.caseType'], $caseTypeId]]];
		foreach ($conditions as $condition) {
			if (is_array($condition) === true) {
				$parts[] = $this->forCondition(condition: $condition);
			}
		}

		return ['and' => $parts];
	}//end forRule()

	/**
	 * One condition as an expression.
	 *
	 * An unknown kind is false rather than skipped: a rule whose condition
	 * cannot be read must not act as if that condition held.
	 *
	 * @param array<string, mixed> $condition The condition.
	 *
	 * @return array<string, mixed>|bool The expression.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function forCondition(array $condition): array|bool {
		$kind = (string)($condition['kind'] ?? '');
		$field = (string)($condition['field'] ?? '');

		return match ($kind) {
			'fieldCompare' => $this->compare(field: $field, operator: (string)($condition['op'] ?? 'eq'), value: ($condition['value'] ?? null)),
			'fieldEquals' => $this->compare(field: $field, operator: 'eq', value: ($condition['value'] ?? null)),
			'fieldPresent' => $this->present(field: $field),
			'fieldDiffers' => $this->differs(field: $field, value: ($condition['value'] ?? null)),
			'statusIs' => ['==' => [['var' => 'json.status'], (string)($condition['status'] ?? '')]],
			'documentPresent' => $this->documentPresent(documentType: (string)($condition['documentType'] ?? '')),
			default => false,
		};
	}//end forCondition()

	/**
	 * A comparison on a case property or on a property definition's answer.
	 *
	 * @param string $field The field.
	 * @param string $operator The rule operator.
	 * @param mixed $value The value to compare with.
	 *
	 * @return array<string, mixed>|bool The expression.
	 */
	private function compare(string $field, string $operator, mixed $value): array|bool {
		$symbol = (self::OPERATORS[$operator] ?? null);
		if ($field === '' || $symbol === null) {
			return false;
		}

		$value = $this->typed(value: $value);

		return [
			'or' => [
				[$symbol => [['var' => 'json.' . $field], $value]],
				[
					'some' => [
						['var' => 'json.properties'],
						[
							'and' => [
								['==' => [['var' => 'name'], $field]],
								[$symbol => [['var' => 'value'], $value]],
							],
						],
					],
				],
			],
		];
	}//end compare()

	/**
	 * Neither the case property nor a definition's answer holds the value.
	 *
	 * The guard a write carries so the write it causes does not fire it again.
	 * `!=` alone is not enough for an answer: an absent case property differs
	 * from every value, so the answer is checked to hold it nowhere.
	 *
	 * @param string $field The field.
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|bool The expression.
	 */
	private function differs(string $field, mixed $value): array|bool {
		if ($field === '') {
			return false;
		}

		$value = $this->typed(value: $value);

		return [
			'and' => [
				['!=' => [['var' => 'json.' . $field], $value]],
				[
					'!' => [
						[
							'some' => [
								['var' => 'json.properties'],
								['and' => [['==' => [['var' => 'name'], $field]], ['==' => [['var' => 'value'], $value]]]],
							],
						],
					],
				],
			],
		];
	}//end differs()

	/**
	 * A field that is filled in, as a case property or a definition's answer.
	 *
	 * @param string $field The field.
	 *
	 * @return array<string, mixed>|bool The expression.
	 */
	private function present(string $field): array|bool {
		if ($field === '') {
			return false;
		}

		return [
			'or' => [
				['!!' => [['var' => 'json.' . $field]]],
				[
					'some' => [
						['var' => 'json.properties'],
						['and' => [['==' => [['var' => 'name'], $field]], ['!!' => [['var' => 'value']]]]],
					],
				],
			],
		];
	}//end present()

	/**
	 * A document of the type, in any of the places a case keeps documents.
	 *
	 * @param string $documentType The document type, uuid or slug.
	 *
	 * @return array<string, mixed>|bool The expression.
	 */
	private function documentPresent(string $documentType): array|bool {
		if ($documentType === '') {
			return false;
		}

		$any = [];
		foreach (self::DOCUMENT_FIELDS as $field) {
			$any[] = [
				'some' => [
					['var' => 'json.' . $field],
					[
						'or' => [
							['==' => [['var' => 'documentType'], $documentType]],
							['==' => [['var' => 'type'], $documentType]],
							['==' => [['var' => 'documentTypeSlug'], $documentType]],
						],
					],
				],
			];
		}

		return ['or' => $any];
	}//end documentPresent()

	/**
	 * A numeric string as a number, so "100000" compares as 100000.
	 *
	 * @param mixed $value The authored value.
	 *
	 * @return mixed The value to compare with.
	 */
	private function typed(mixed $value): mixed {
		if (is_string($value) === true && is_numeric($value) === true) {
			return ($value + 0);
		}

		return $value;
	}//end typed()
}//end class
