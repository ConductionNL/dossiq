<?php

/**
 * What a case type asks of the fields, per role rather than per status.
 *
 * The sibling of {@see \OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration}.
 * That one answers "what does THIS STATUS ask of the fields"; this one answers
 * "what may THIS ROLE see and change, in every status". Both are declarations
 * dossiq translates and OpenRegister enforces. Neither evaluates anything:
 * ADR-023 rule 1, no app-side field filtering.
 *
 * 🔴 THE TWO ENFORCEMENT SURFACES SPELL THE SAME RULE WITH OPPOSITE POLARITY,
 * AND NEITHER REPORTS A CONFUSION. This is the single fact this class exists to
 * hold in one place:
 *
 * - A lifecycle field rule (`x-openregister-lifecycle.states.<s>.fields`) names
 *   the groups the rule is TAKEN FROM. openregister's own scenario reads
 *   "state `intake` hides `internalNote` for group `frontdesk`", and an entry
 *   with no `groups` key applies to everyone, administrators included. It is a
 *   DENY list.
 * - A property authorization block (`properties.<f>.authorization`) names the
 *   groups that KEEP the field. openregister's scenario reads "property `bsn`
 *   with `read: [{group: bsn-geautoriseerd}]`", everyone else is stripped by
 *   `PropertyRbacHandler::filterReadableProperties()`. It is an ALLOW list.
 *
 * Put a deny list where an allow list is read and the field is withheld from
 * exactly the people who were supposed to keep it, with no error anywhere. So a
 * declared rule carries BOTH lists under names that say which is which
 * (`groups` restricts, `heldBy` keeps), and this class never derives one from
 * the other. Deriving would mean inventing the set of every group on the
 * instance, which dossiq cannot know and must not guess.
 *
 * 🔴 A GROUP IN BOTH LISTS IS RESOLVED TOWARDS THE RESTRICTION. The two
 * resolutions are not symmetric: keeping the group would publish an allow-list
 * entry for somebody the author also wrote down as restricted, which discloses
 * the field. Dropping it withholds a field from somebody who may have been
 * meant to keep it, which is visible and complainable. A security rule resolves
 * towards the outcome that can be reported.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * Reads a case type's per-role field rules and writes them in OpenRegister's shapes.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
class FieldRoleRuleDeclaration {

	/**
	 * The property the rules are declared under, on the case type.
	 */
	public const DECLARATION_KEY = 'fieldRoleRules';

	/**
	 * The field is kept out of the restricted role's read and refused on write.
	 */
	public const RULE_HIDDEN = 'hidden';

	/**
	 * The field is shown to the restricted role and refused on change.
	 */
	public const RULE_READ_ONLY = 'readOnly';

	/**
	 * The two rule names, spelled exactly as OpenRegister keys them.
	 *
	 * `required` is deliberately absent. Requiring a field of a role and not of
	 * another is a rule about the work, not about access, and the status half
	 * (openregister#3771) already owns it per state. A second, state-blind
	 * `required` here would fire on a case in a state that does not ask for the
	 * field, and the author would have two places to look for one refusal.
	 *
	 * @var array<int, string>
	 */
	public const RULES = [
		self::RULE_HIDDEN,
		self::RULE_READ_ONLY,
	];

	/**
	 * The verbs a property authorization block is keyed by.
	 *
	 * `read` and `update` are the two `PropertyRbacHandler` reads:
	 * `filterReadableProperties()` for the first and
	 * `getUnauthorizedProperties()` for the second.
	 */
	public const VERB_READ = 'read';

	/**
	 * The write verb of a property authorization block.
	 */
	public const VERB_UPDATE = 'update';

	/**
	 * The rules one case type declares, in the lifecycle's shape.
	 *
	 * The shape is the one {@see \OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration}
	 * publishes, so the two halves land in one block per state and OpenRegister
	 * reads them with one resolver. A role rule carries no condition: it is
	 * about who is asking, and the object's own data does not change the answer.
	 *
	 * A rule whose `groups` normalises to empty produces NO lifecycle entry. An
	 * entry with no `groups` applies to everyone including administrators, so
	 * publishing one for a rule whose restricted list is blank would take the
	 * field away from the whole instance because somebody opened the editor and
	 * changed their mind.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The `fields` block, possibly empty.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function lifecycleFields(array $caseType): array {
		$block = [];
		foreach ($this->rulesOf(caseType: $caseType) as $rule) {
			$restricted = $this->restrictedGroups(rule: $rule);
			if ($restricted === []) {
				continue;
			}

			$entry = [
				'fields' => [$rule['field']],
				'groups' => $restricted,
			];

			if ($rule['reason'] !== '') {
				$entry['message'] = $rule['reason'];
			}

			$block[$rule['rule']][] = $entry;
		}

		return $this->inPublishedOrder(block: $block);
	}//end lifecycleFields()

	/**
	 * The rules one case type declares, as property authorization blocks.
	 *
	 * Keyed by property name, each value the `{read, update}` block OpenRegister
	 * reads off `properties.<name>.authorization`. A `hidden` rule restricts
	 * both verbs, because a field somebody may not see is not one they may
	 * write over; a `readOnly` rule restricts `update` alone and leaves the read
	 * open to everybody, which is what "shown and refused on change" means.
	 *
	 * A rule whose `heldBy` normalises to empty produces NO block, for the
	 * mirror of the reason above: `read: []` is a non-empty authorization key
	 * holding nobody, so the field would be stripped for every non-administrator
	 * on the instance while the editor shows a rule naming one role.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return array<string, array<string, array<int, array<string, string>>>> Blocks by property name.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function propertyAuthorization(array $caseType): array {
		$blocks = [];
		foreach ($this->rulesOf(caseType: $caseType) as $rule) {
			$holders = $this->holdingGroups(rule: $rule);
			if ($holders === []) {
				continue;
			}

			$grants = array_map(static fn (string $group): array => ['group' => $group], $holders);

			$field = $rule['field'];
			$blocks[$field][self::VERB_UPDATE] = $this->union(
				current: ($blocks[$field][self::VERB_UPDATE] ?? []),
				added: $grants
			);

			if ($rule['rule'] === self::RULE_HIDDEN) {
				$blocks[$field][self::VERB_READ] = $this->union(
					current: ($blocks[$field][self::VERB_READ] ?? []),
					added: $grants
				);
			}
		}

		return $blocks;
	}//end propertyAuthorization()

	/**
	 * Two grant lists as one, with the repeats gone.
	 *
	 * Public because a projector merging one case type's blocks onto another's
	 * needs the SAME union this class uses. Two unions that disagree about what
	 * a repeat is would publish a group twice, and a duplicated grant makes
	 * every idempotency comparison report a change that did not happen.
	 *
	 * @param array<int, array<string, string>> $current The grants already gathered.
	 * @param array<int, array<string, string>> $added   The grants to add.
	 *
	 * @return array<int, array<string, string>> The union.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	public function union(array $current, array $added): array {
		$union = $current;
		foreach ($added as $grant) {
			if (in_array($grant, $union, true) === false) {
				$union[] = $grant;
			}
		}

		return $union;
	}//end union()

	/**
	 * The declared rules, each normalised or dropped.
	 *
	 * A rule naming a kind this app does not know is DROPPED rather than
	 * published, for the reason the status half drops an unknown condition
	 * kind: a declaration written for a later vocabulary must not take a field
	 * away from everybody on an instance that cannot read it. A rule naming no
	 * field is dropped too, because OpenRegister refuses the whole schema save
	 * over a rule naming a field it cannot find, and a blank row is what an
	 * author leaves behind every time they hesitate.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return array<int, array{field: string, rule: string, reason: string, groups: mixed, heldBy: mixed}> The rules.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function rulesOf(array $caseType): array {
		$declared = ($caseType[self::DECLARATION_KEY] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$rules = [];
		foreach ($declared as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$kind = (string)($rule['rule'] ?? '');
			$field = trim((string)($rule['field'] ?? ''));
			if (in_array($kind, self::RULES, true) === false || $field === '') {
				continue;
			}

			$rules[] = [
				'field' => $field,
				'rule' => $kind,
				'reason' => trim((string)($rule['reason'] ?? '')),
				'groups' => ($rule['groups'] ?? null),
				'heldBy' => ($rule['heldBy'] ?? null),
			];
		}

		return $rules;
	}//end rulesOf()

	/**
	 * The groups one rule takes the field away from.
	 *
	 * @param array<string, mixed> $rule One normalised rule.
	 *
	 * @return array<int, string> The group names.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function restrictedGroups(array $rule): array {
		return $this->names(value: ($rule['groups'] ?? null));
	}//end restrictedGroups()

	/**
	 * The groups one rule leaves the field with.
	 *
	 * A group the same rule also restricts is removed here rather than there:
	 * see the class docblock for why the restriction is the side that wins.
	 *
	 * @param array<string, mixed> $rule One normalised rule.
	 *
	 * @return array<int, string> The group names.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function holdingGroups(array $rule): array {
		$restricted = $this->restrictedGroups(rule: $rule);

		return array_values(
			array_filter(
				$this->names(value: ($rule['heldBy'] ?? null)),
				static fn (string $name): bool => in_array($name, $restricted, true) === false
			)
		);
	}//end holdingGroups()

	/**
	 * A declared list of group names, with the blanks and the repeats gone.
	 *
	 * @param mixed $value The declared list.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
	 */
	private function names(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$names = [];
		foreach ($value as $entry) {
			if (is_array($entry) === true || is_object($entry) === true) {
				continue;
			}

			$name = trim((string)$entry);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end names()

	/**
	 * The block with its kinds in the order OpenRegister lists them.
	 *
	 * Order changes nothing about what is enforced. It makes the block
	 * DIFFABLE, which is what keeps the projector from rewriting the schema on
	 * every publish over a difference that is not one.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $block The gathered block.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The block, ordered.
	 *
	 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
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
