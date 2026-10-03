<?php

/**
 * The person who prepared this may not be the person who approves it.
 *
 * Mandaat is modelled and `MandaatVerifier` reads it.
 * `ConflictOfInterestService` asks whether the handler is related to the
 * applicant. Neither answers the ordinary four-eyes question, and nothing
 * stopped the author of a decision from approving it.
 *
 * 🔑 IT IS A NEGATIVE RULE ABOUT AN ACT, NOT A ROLE. "An approver may not be
 * the author" cannot be written as a role, because the same person
 * legitimately holds both roles on other cases. It is a statement about who
 * performed a named earlier act ON THIS CASE. So the transition names the act
 * and the engine reads who performed it, from the case's own status record
 * chain.
 *
 * 🔑 THE REFUSAL NAMES WHO AND WHEN. "You may not do this" sends the handler
 * to a colleague to ask why. "You prepared this decision on 3 March, so
 * somebody else approves it" sends them to the right colleague, and the case
 * type may name who that is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * Reads who performed a named earlier act, and refuses them the transition.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
class FourEyesRule {

	/**
	 * The declaration a transition carries, normalised.
	 *
	 * @param array<string, mixed> $transition The transition definition.
	 *
	 * @return array{act: string, askInstead: string}|null The rule, or null when
	 *         the transition declares none.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function declaredFor(array $transition): ?array {
		$declared = ($transition['notPerformedBy'] ?? null);
		if (is_array($declared) === false) {
			return null;
		}

		// The act is named by the LABEL of the earlier transition, because that
		// is what a case type author writes and reads. A transition id would be
		// exact and unreadable, and it changes when a workflow is republished.
		$act = trim((string)($declared['act'] ?? ($declared['transitionLabel'] ?? '')));
		if ($act === '') {
			return null;
		}

		return [
			'act' => $act,
			'askInstead' => trim((string)($declared['askInstead'] ?? '')),
		];
	}//end declaredFor()

	/**
	 * Who performed the named act on this case, and when.
	 *
	 * The LAST performance wins, not the first. A decision redrafted twice was
	 * prepared by whoever wrote the version being approved, and reading the
	 * first would refuse a colleague who never touched it while letting the
	 * actual author through.
	 *
	 * @param array<int, array<string, mixed>> $records The case's status records.
	 * @param string                           $act     The act's label.
	 *
	 * @return array{actor: string, at: string}|null The performer, or null when
	 *         the act has not been performed on this case.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function performerOf(array $records, string $act): ?array {
		$wanted = strtolower(trim($act));
		if ($wanted === '') {
			return null;
		}

		$found = null;
		foreach ($records as $record) {
			if (is_array($record) === false) {
				continue;
			}

			if (strtolower(trim((string)($record['transitionLabel'] ?? ''))) !== $wanted) {
				continue;
			}

			$actor = $this->actorOf(record: $record);
			if ($actor === '') {
				continue;
			}

			$found = [
				'actor' => $actor,
				'at' => (string)($record['createdAt'] ?? ($record['@self']['created'] ?? '')),
			];
		}

		return $found;
	}//end performerOf()

	/**
	 * Whether this user is refused this transition, and why.
	 *
	 * @param array<string, mixed>             $transition The transition definition.
	 * @param array<int, array<string, mixed>> $records    The case's status records.
	 * @param string                           $userId     The acting user.
	 *
	 * @return array{act: string, actor: string, at: string, askInstead: string}|null
	 *         The refusal, or null when the transition is open to this person.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function refusalFor(array $transition, array $records, string $userId): ?array {
		$rule = $this->declaredFor(transition: $transition);
		if ($rule === null || trim($userId) === '') {
			return null;
		}

		$performer = $this->performerOf(records: $records, act: $rule['act']);
		if ($performer === null || $performer['actor'] !== $userId) {
			return null;
		}

		return [
			'act' => $rule['act'],
			'actor' => $performer['actor'],
			'at' => $performer['at'],
			'askInstead' => $rule['askInstead'],
		];
	}//end refusalFor()

	/**
	 * Who a status record says performed the move.
	 *
	 * `actor` first, which the engine writes from this change on. A record that
	 * predates it falls back to the object's OWNER, which OpenRegister sets to
	 * whoever wrote the row. The fallback is what makes the rule work on
	 * history that already exists rather than only on cases started afterwards,
	 * and a case whose four-eyes rule only bound future work would be a rule
	 * nobody could rely on for a year.
	 *
	 * @param array<string, mixed> $record One status record.
	 *
	 * @return string The uid, or the empty string when the record names nobody.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	private function actorOf(array $record): string {
		$actor = trim((string)($record['actor'] ?? ''));
		if ($actor !== '') {
			return $actor;
		}

		return trim((string)($record['@self']['owner'] ?? ($record['owner'] ?? '')));
	}//end actorOf()
}//end class
