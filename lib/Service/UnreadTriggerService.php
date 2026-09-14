<?php

/**
 * Dossiq unread-trigger declaration.
 *
 * Reads the `unreadTriggers` declaration off a case type and answers one
 * question: which changes to a case are news to the people who have already
 * seen it. It is a declaration reader and nothing else. It holds no
 * OpenRegister handle, stores no read state and makes no write, because the
 * read state itself belongs to OpenRegister
 * (`openregister_object_read_state`, change `object-read-state`, merged as
 * openregister#3734) and dossiq keeps none of its own.
 *
 * WHY THIS IS DECLARED AND NOT INFERRED. Treating every write as news is what
 * makes a badge useless: one nightly recalculation of a computed field, or one
 * administrator correcting a field in bulk, lights up four hundred cases on a
 * Tuesday morning, and after that nobody reads the badge again. So the changes
 * that count are named, and a change nobody named is a technical touch.
 *
 * 🔴 WHAT IS ENFORCED AND WHERE. OpenRegister resolves
 * `x-openregister-read-state` PER SCHEMA (`SubstantiveChangeEvaluator::
 * annotation()` takes a `Schema`, never an object), so the enforced floor is
 * the block on the `case` schema in `lib/Settings/dossiq_register.json`, and
 * that block carries this vocabulary's whole property list. A case type that
 * names fewer triggers than the vocabulary is rendered and warned about here,
 * but cannot narrow the evaluator further until OpenRegister can resolve the
 * annotation per object. That ask is recorded in the change's tasks.md, and
 * {@see \OCA\Dossiq\Tests\Unit\Service\UnreadTriggerServiceTest} is what stops
 * the schema block and this vocabulary drifting apart in silence.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * What a case type says about which changes make a case unread.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
class UnreadTriggerService {

	/**
	 * Every trigger a case type may name.
	 *
	 * A name outside this list is a typo, and a typo that is quietly ignored
	 * means the case type declared nothing, which is why
	 * {@see self::publicationWarnings()} says so out loud rather than dropping
	 * it.
	 *
	 * @var array<int, string>
	 */
	public const VOCABULARY = [
		'status',
		'documents',
		'messages',
		'assignee',
		'deadline',
		'result',
	];

	/**
	 * What a case type that declares nothing is read as.
	 *
	 * The status moved, a document arrived, somebody wrote. Those three are
	 * what a handler means by "this case changed", and a case type has to opt
	 * out of them rather than opt in, because a badge nobody configured is
	 * still better than a badge that never lights up.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULTS = ['status', 'documents', 'messages'];

	/**
	 * The case property each trigger watches, for the triggers that are one.
	 *
	 * `documents` and `messages` are deliberately absent: neither is a
	 * property of the case row. Documents are the object's own files, which
	 * OpenRegister counts as the `files` sub-resource whether anything
	 * declares it or not, and a message is a `contactmoment` object that
	 * points back at the case. Mapping either onto a case property would
	 * declare a column that does not exist, and an undeclared property is
	 * dropped in silence rather than refused.
	 *
	 * @var array<string, string>
	 */
	public const PROPERTY_OF = [
		'status' => 'status',
		'assignee' => 'assignee',
		'deadline' => 'deadline',
		'result' => 'result',
	];

	/**
	 * The sub-resource each trigger badges, for the triggers that are one.
	 *
	 * @var array<string, string>
	 */
	public const SUB_RESOURCE_OF = ['documents' => 'files'];

	/**
	 * The triggers this case type declares, with the default filled in.
	 *
	 * An empty or absent declaration is the default, not "nothing counts". A
	 * case type that genuinely wants no badge names a shorter list; it cannot
	 * express "never" by leaving the field blank, because a blank field is
	 * what every case type that has never been edited carries.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The triggers, in vocabulary order, never empty.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function triggersFor(array $caseType): array {
		$declared = $this->declaredNames(caseType: $caseType);
		if ($declared === []) {
			return self::DEFAULTS;
		}

		$triggers = [];
		foreach (self::VOCABULARY as $trigger) {
			if (in_array($trigger, $declared, true) === true) {
				$triggers[] = $trigger;
			}
		}

		if ($triggers === []) {
			// Everything the case type named was outside the vocabulary, so it
			// has in effect declared nothing. Falling through to the default
			// keeps the badge working while the warning says the names are
			// wrong; reading it as "never" would turn a typo into a silently
			// dark feature.
			return self::DEFAULTS;
		}

		return $triggers;
	}//end triggersFor()

	/**
	 * Does a change of this kind make a case of this type unread.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param string               $trigger  The trigger name to ask about.
	 *
	 * @return boolean TRUE when the case type names it.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function declares(array $caseType, string $trigger): bool {
		return in_array($trigger, $this->triggersFor(caseType: $caseType), true);
	}//end declares()

	/**
	 * The case properties a set of triggers watches.
	 *
	 * This is the half of the declaration OpenRegister's evaluator reads, and
	 * it is what the `case` schema's `x-openregister-read-state.properties`
	 * has to equal for the whole vocabulary.
	 *
	 * @param array<int, string> $triggers The trigger names.
	 *
	 * @return array<int, string> The case property names, in vocabulary order.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function propertiesFor(array $triggers): array {
		$properties = [];
		foreach (self::VOCABULARY as $trigger) {
			if (in_array($trigger, $triggers, true) === false) {
				continue;
			}

			$property = (self::PROPERTY_OF[$trigger] ?? null);
			if ($property !== null) {
				$properties[] = $property;
			}
		}

		return $properties;
	}//end propertiesFor()

	/**
	 * The sub-resources a set of triggers badges.
	 *
	 * @param array<int, string> $triggers The trigger names.
	 *
	 * @return array<int, string> The sub-resource names OpenRegister counts under.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function subResourcesFor(array $triggers): array {
		$resources = [];
		foreach (self::VOCABULARY as $trigger) {
			if (in_array($trigger, $triggers, true) === false) {
				continue;
			}

			$resource = (self::SUB_RESOURCE_OF[$trigger] ?? null);
			if ($resource !== null) {
				$resources[] = $resource;
			}
		}

		return $resources;
	}//end subResourcesFor()

	/**
	 * What publishing this case type should say out loud about its badge.
	 *
	 * A warning and never a finding, for the reason
	 * {@see \OCA\Dossiq\Service\CaseTypePublishService::warnings()} gives: a
	 * case type may lawfully want a quieter badge than the default, so
	 * refusing would make a legitimate configuration unpublishable. What must
	 * not happen is a name nobody recognises being dropped in silence, leaving
	 * a case type that reads as configured and behaves as if it were not.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The warnings, empty when the declaration is clean.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function publicationWarnings(array $caseType): array {
		$declared = $this->declaredNames(caseType: $caseType);
		if ($declared === []) {
			return [];
		}

		$unknown = [];
		foreach ($declared as $name) {
			if (in_array($name, self::VOCABULARY, true) === false) {
				$unknown[] = $name;
			}
		}

		$warnings = [];
		if ($unknown !== []) {
			$warnings[] = 'This case type names a change nobody recognises as a reason to '
				. 'mark a case unread: '.implode(', ', $unknown).'. Nothing watches it, so '
				. 'the case type behaves as if it had named nothing.';
		}

		if ($this->triggersFor(caseType: $caseType) === self::DEFAULTS && $unknown === []) {
			return $warnings;
		}

		if (in_array('status', $declared, true) === false) {
			$warnings[] = 'A case of this type does not read unread when its status moves. '
				. 'A handler watching the list will not see it change.';
		}

		return $warnings;
	}//end publicationWarnings()

	/**
	 * The raw names this case type declared, trimmed and de-duplicated.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The declared names, empty when it declared none.
	 */
	private function declaredNames(array $caseType): array {
		$declared = ($caseType['unreadTriggers'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$names = [];
		foreach ($declared as $name) {
			if (is_string($name) === false && is_numeric($name) === false) {
				continue;
			}

			$name = trim((string)$name);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end declaredNames()
}//end class
