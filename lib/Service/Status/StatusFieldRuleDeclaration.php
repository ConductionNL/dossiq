<?php

/**
 * What a status asks of the fields on the case.
 *
 * One status says three kinds of thing about a field: fill it, do not show it,
 * do not change it. dossiq does not decide any of them. It reads what the
 * administrator declared on the statusType row and rewrites it into the shape
 * OpenRegister's `field-rules-by-state` publishes under
 * `x-openregister-lifecycle.states.<state>.fields`, and OpenRegister refuses
 * the save. ADR-023 rule 1: no app-side field filtering.
 *
 * 🔴 THE CONDITION IS TRANSLATED, NOT RE-EVALUATED. A rule may carry a
 * condition in the three kinds `derivedWhen` already uses, because a case type
 * with two condition vocabularies has two places to look and two ways to be
 * wrong. Here each kind becomes a JSONLogic node, which is the dialect
 * OpenRegister's `ConditionDialect` falls back to for any operator its JSON AST
 * catalogue does not own. JSONLogic rather than the AST on purpose: `!!` and
 * `==` are JSONLogic's own spellings and need no lookup in a catalogue this app
 * cannot read, so a translation cannot silently produce a node the engine
 * evaluates as false.
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
 * Reads a status type's field rules and writes them in OpenRegister's shape.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusFieldRuleDeclaration {

	/**
	 * The field may not be left empty while the case sits in this status.
	 */
	public const RULE_REQUIRED = 'required';

	/**
	 * The field is kept out of the read and refused on write.
	 */
	public const RULE_HIDDEN = 'hidden';

	/**
	 * The field is shown and refused on change.
	 */
	public const RULE_READ_ONLY = 'readOnly';

	/**
	 * The three rule names, spelled exactly as OpenRegister keys them.
	 *
	 * The spelling is the contract: `StateFieldRuleResolver::KINDS` reads
	 * `hidden`, `readOnly` and `required` off the block, and a block keyed
	 * `readonly` is not refused, it is simply never found. That is the silent
	 * no-op this constant exists to prevent.
	 *
	 * @var array<int, string>
	 */
	public const RULES = [
		self::RULE_HIDDEN,
		self::RULE_READ_ONLY,
		self::RULE_REQUIRED,
	];

	/**
	 * The rules one status declares, grouped the way OpenRegister reads them.
	 *
	 * A rule naming a kind this app does not know is DROPPED, for the reason
	 * {@see StatusDeclaration::derivedWhen()} drops an unknown condition kind:
	 * a declaration written for a later vocabulary must not freeze every case
	 * in the status. A rule naming no field is dropped too, because a rule
	 * about no field cannot be acted on and OpenRegister would refuse the whole
	 * schema save over it.
	 *
	 * Returns only the kinds that actually carry an entry. An empty
	 * `required: []` published beside two real kinds reads as "this status
	 * requires nothing", which is true, but it also makes every idempotency
	 * comparison in {@see CaseStateFieldRuleProjector} depend on whether the
	 * author once added and removed a row.
	 *
	 * @param array<string, mixed> $statusType The statusType row.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The `fields` block, possibly empty.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function fieldsBlock(array $statusType): array {
		$declared = ($statusType['fieldRules'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$block = [];
		foreach ($declared as $rule) {
			$entry = $this->entryOf(rule: $rule);
			if ($entry === null) {
				continue;
			}

			[$kind, $published] = $entry;
			$block[$kind][] = $published;
		}

		return $this->inPublishedOrder(block: $block);
	}//end fieldsBlock()

	/**
	 * One declared rule as OpenRegister publishes it, or null when it is unusable.
	 *
	 * @param mixed $rule One entry of the statusType's `fieldRules`.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The kind and the entry.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function entryOf(mixed $rule): ?array {
		if (is_array($rule) === false) {
			return null;
		}

		$kind = (string)($rule['rule'] ?? '');
		$field = trim((string)($rule['field'] ?? ''));
		if (in_array($kind, self::RULES, true) === false || $field === '') {
			return null;
		}

		// `fields` and not `field`: the resolver accepts both spellings, and
		// the plural is the one its own examples use.
		$entry = ['fields' => [$field]];

		$groups = $this->groupsOf(rule: $rule);
		if ($groups !== []) {
			$entry['groups'] = $groups;
		}

		$when = $this->conditionOf(condition: ($rule['condition'] ?? null));
		if ($when !== null) {
			$entry['when'] = $when;
		}

		$message = trim((string)($rule['message'] ?? ''));
		if ($message !== '') {
			$entry['message'] = $message;
		}

		return [$kind, $entry];
	}//end entryOf()

	/**
	 * The groups a rule names, with the blanks dropped.
	 *
	 * An entry with NO groups is published without the key, and OpenRegister
	 * reads that as everyone, administrators included. That is why a list that
	 * normalises to empty must not be published as `groups: []`: the resolver
	 * would find a key, find nobody in it, and the rule would apply to nobody
	 * while the editor shows it as declared.
	 *
	 * @param array<string, mixed> $rule One declared rule.
	 *
	 * @return array<int, string> The group names.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function groupsOf(array $rule): array {
		$declared = ($rule['groups'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$groups = [];
		foreach ($declared as $group) {
			$name = trim((string)$group);
			if ($name !== '' && in_array($name, $groups, true) === false) {
				$groups[] = $name;
			}
		}

		return $groups;
	}//end groupsOf()

	/**
	 * A declared condition as a JSONLogic node, or null when there is none.
	 *
	 * `documentPresent` is deliberately NOT translated. A document hangs off
	 * the case as a related object rather than as a property of it, and the
	 * document a condition reads is one OpenRegister cannot see in the four
	 * keys a condition document carries. Publishing a node that reads a
	 * property nothing writes would make the rule silently never apply, which
	 * is worse than the rule applying unconditionally: the author would see it
	 * declared, see it never fire, and have nothing to look at. So the kind is
	 * dropped and the rule is published without a condition, which is the
	 * reading the editor also shows.
	 *
	 * @param mixed $condition The declared condition.
	 *
	 * @return array<string, mixed>|null The JSONLogic node.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function conditionOf(mixed $condition): ?array {
		if (is_array($condition) === false) {
			return null;
		}

		$kind = (string)($condition['kind'] ?? '');
		$field = trim((string)($condition['field'] ?? ''));
		if ($field === '') {
			return null;
		}

		if ($kind === 'fieldPresent') {
			return ['!!' => ['var' => ('object.' . $field)]];
		}

		if ($kind === 'fieldEquals') {
			return ['==' => [['var' => ('object.' . $field)], (string)($condition['value'] ?? '')]];
		}

		return null;
	}//end conditionOf()

	/**
	 * The block with its kinds in the order OpenRegister lists them.
	 *
	 * Order changes nothing about what is enforced. It makes the block
	 * DIFFABLE: the projector only writes when the block differs from the live
	 * one, and two blocks that differ only in key order would rewrite the
	 * schema on every publish and log a change that did not happen.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $block The gathered block.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The block, ordered.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function inPublishedOrder(array $block): array {
		$ordered = [];
		foreach (self::RULES as $kind) {
			if (($block[$kind] ?? []) !== []) {
				$ordered[$kind] = $block[$kind];
			}
		}

		return $ordered;
	}//end inPublishedOrder()
}//end class
