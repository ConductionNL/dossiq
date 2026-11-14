<?php

/**
 * Dossiq case type rule sentences.
 *
 * The Rules tab of a case type lists its rules as rows: "If <conditions>" on
 * top, "then <actions>" below it, and whether the rule is on. The rules are
 * stored as configuration (`caseType.rules`), conditions and actions as
 * kinds with operands; a declarative row cannot join them into a sentence, and
 * a status reads as a uuid until its name is looked up.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Kernel\Contracts\CaseTypeRuleSentencesInterface;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;

/**
 * Writes a case type's rules as "If ... / then ..." rows.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */
class CaseTypeRuleSentences implements CaseTypeRuleSentencesInterface {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver $resolver The effective case type and its statuses.
	 * @param IL10N            $l10n     The reader's language.
	 * @param TranslatedText   $text     A status name in the reader's language.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly IL10N $l10n,
		private readonly TranslatedText $text,
	) {
	}//end __construct()

	/**
	 * The case type's rules as rows, in their stored order.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array{rules: array<int, array{id: string, name: string, enabled: bool, state: string, moment: string, title: string, line: string}>}|null
	 *         The rows, or null when the case type cannot be read.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
	 */
	public function rulesFor(string $caseTypeId): ?array {
		$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return null;
		}

		$rules = ($caseType['rules'] ?? []);
		if (is_array($rules) === false || $rules === []) {
			return ['rules' => []];
		}

		$statuses = $this->statusNames(caseTypeId: $caseTypeId);
		$rows     = [];
		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$rows[] = $this->row(rule: $rule, statuses: $statuses);
		}

		return ['rules' => $rows];
	}//end rulesFor()

	/**
	 * One rule as a row.
	 *
	 * @param array<string, mixed>  $rule     The stored rule.
	 * @param array<string, string> $statuses Status names by statusType id.
	 *
	 * @return array{id: string, name: string, enabled: bool, state: string, moment: string, title: string, line: string} The row.
	 */
	private function row(array $rule, array $statuses): array {
		$enabled = (($rule['enabled'] ?? true) !== false);

		$conditions = [];
		foreach ($this->listOf(value: ($rule['conditions'] ?? [])) as $condition) {
			$conditions[] = $this->condition(condition: $condition, statuses: $statuses);
		}

		$actions = [];
		foreach ($this->listOf(value: ($rule['actions'] ?? [])) as $action) {
			$actions[] = $this->action(action: $action);
		}

		$title = $this->l10n->t('Always');
		if ($conditions !== []) {
			$title = $this->l10n->t('If %1$s', [implode($this->l10n->t(' and '), $conditions)]);
		}

		$line = $this->l10n->t('then nothing happens');
		if ($actions !== []) {
			$line = $this->l10n->t('then %1$s', [implode(', ', $actions)]);
		}

		$state = $this->l10n->t('Off');
		if ($enabled === true) {
			$state = $this->l10n->t('On');
		}

		return [
			'id'      => $this->scalar(value: ($rule['id'] ?? '')),
			'name'    => $this->scalar(value: ($rule['name'] ?? '')),
			'enabled' => $enabled,
			'state'   => $state,
			'moment'  => $this->moment(when: ($rule['when'] ?? null), statuses: $statuses),
			'title'   => $title,
			'line'    => $line,
		];
	}//end row()

	/**
	 * When the rule is checked.
	 *
	 * @param mixed                 $when     The stored moment.
	 * @param array<string, string> $statuses Status names by statusType id.
	 *
	 * @return string The moment in words, or '' when the rule names none.
	 */
	private function moment(mixed $when, array $statuses): string {
		if (is_array($when) === false) {
			return '';
		}

		return match ($when['event'] ?? null) {
			'created'       => $this->l10n->t('When a case is created'),
			'saved'         => $this->l10n->t('When a case is saved'),
			'statusEntered' => $this->l10n->t(
				'When a case enters status %1$s',
				[$this->statusName(id: ($when['status'] ?? ''), statuses: $statuses)]
			),
			default         => '',
		};
	}//end moment()

	/**
	 * One condition in words.
	 *
	 * @param array<string, mixed>  $condition The stored condition.
	 * @param array<string, string> $statuses  Status names by statusType id.
	 *
	 * @return string The condition in words.
	 */
	private function condition(array $condition, array $statuses): string {
		$field = $this->scalar(value: ($condition['field'] ?? ''));
		$value = $this->value(value: ($condition['value'] ?? null));

		return match ($condition['kind'] ?? null) {
			'fieldCompare'    => $this->comparison(field: $field, operator: ($condition['op'] ?? 'eq'), value: $value),
			'fieldEquals'     => $this->l10n->t('%1$s is %2$s', [$field, $value]),
			'fieldPresent'    => $this->l10n->t('%1$s is filled in', [$field]),
			'documentPresent' => $this->l10n->t(
				'the case has a document of type %1$s',
				[$this->scalar(value: ($condition['documentType'] ?? ''))]
			),
			'statusIs'        => $this->l10n->t(
				'the status is %1$s',
				[$this->statusName(id: ($condition['status'] ?? ''), statuses: $statuses)]
			),
			default           => $this->l10n->t('a condition dossiq does not know'),
		};
	}//end condition()

	/**
	 * A field compared with a value.
	 *
	 * @param string $field    The field.
	 * @param mixed  $operator The stored operator.
	 * @param string $value    The value in words.
	 *
	 * @return string The comparison in words.
	 */
	private function comparison(string $field, mixed $operator, string $value): string {
		return match ($operator) {
			'neq'   => $this->l10n->t('%1$s is not %2$s', [$field, $value]),
			'gt'    => $this->l10n->t('%1$s is greater than %2$s', [$field, $value]),
			'gte'   => $this->l10n->t('%1$s is at least %2$s', [$field, $value]),
			'lt'    => $this->l10n->t('%1$s is less than %2$s', [$field, $value]),
			'lte'   => $this->l10n->t('%1$s is at most %2$s', [$field, $value]),
			default => $this->l10n->t('%1$s is %2$s', [$field, $value]),
		};
	}//end comparison()

	/**
	 * One action in words.
	 *
	 * @param array<string, mixed> $action The stored action.
	 *
	 * @return string The action in words.
	 */
	private function action(array $action): string {
		return match ($action['action'] ?? null) {
			'addTask'         => $this->l10n->t('add task %1$s', [$this->scalar(value: ($action['taskType'] ?? ''))]),
			'setField'        => $this->setField(action: $action),
			'chooseVariant'   => $this->l10n->t('choose variant %1$s', [$this->scalar(value: ($action['variant'] ?? ''))]),
			'requireDocument' => $this->l10n->t(
				'require a document of type %1$s',
				[$this->scalar(value: ($action['documentType'] ?? ''))]
			),
			'sendMessage'     => $this->l10n->t(
				'send message %1$s',
				[$this->scalar(value: ($action['emailTemplate'] ?? ''))]
			),
			default           => $this->l10n->t('an action dossiq does not know'),
		};
	}//end action()

	/**
	 * A setField action: a value written, or the field made required.
	 *
	 * @param array<string, mixed> $action The stored action.
	 *
	 * @return string The action in words.
	 */
	private function setField(array $action): string {
		$field = $this->scalar(value: ($action['field'] ?? ''));
		if (array_key_exists('value', $action) === false || $action['value'] === null) {
			return $this->l10n->t('make %1$s required', [$field]);
		}

		return $this->l10n->t('set %1$s to %2$s', [$field, $this->value(value: $action['value'])]);
	}//end setField()

	/**
	 * The names of the case type's statuses by id.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<string, string> The names.
	 */
	private function statusNames(string $caseTypeId): array {
		$names = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $statusType) {
			$id = (string) ($statusType['id'] ?? ($statusType['@self']['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$names[$id] = $this->text->forReader(value: ($statusType['name'] ?? ''));
		}

		return $names;
	}//end statusNames()

	/**
	 * A status by its name, or by its id when the case type does not know it.
	 *
	 * @param mixed                 $id       The statusType id.
	 * @param array<string, string> $statuses Status names by statusType id.
	 *
	 * @return string The name.
	 */
	private function statusName(mixed $id, array $statuses): string {
		$key = $this->scalar(value: $id);
		if (($statuses[$key] ?? '') !== '') {
			return $statuses[$key];
		}

		return $key;
	}//end statusName()

	/**
	 * A stored value in words.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The words.
	 */
	private function value(mixed $value): string {
		if (is_bool($value) === true) {
			if ($value === true) {
				return $this->l10n->t('yes');
			}

			return $this->l10n->t('no');
		}

		if (is_array($value) === true) {
			return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		return $this->scalar(value: $value);
	}//end value()

	/**
	 * A scalar as a string; anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 */
	private function scalar(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string) $value;
	}//end scalar()

	/**
	 * The arrays in a stored list, skipping anything else.
	 *
	 * @param mixed $value The stored list.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $entry): bool => is_array($entry)));
	}//end listOf()
}//end class
