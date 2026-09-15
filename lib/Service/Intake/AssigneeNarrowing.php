<?php

/**
 * Dossiq assignee narrowing declaration.
 *
 * Reads the `assigneeNarrowing` declaration off a case type and answers which
 * teams and which people may take a case of that type at creation.
 *
 * ENFORCED ON THE WRITE, NOT ONLY DRAWN IN THE PICKER. A picker that lists
 * three teams over an API that accepts thirty is a narrowing that exists on
 * screen and nowhere else, and every integration walks straight through it.
 * The Dimpact claim is about who may be CHOSEN, so the same declaration feeds
 * the picker and refuses the write.
 *
 * A refusal names the declaration that refused it. "Team Handhaving is not one
 * this case type allows" sends an administrator to the case type, which is
 * where the fix is. "Not allowed" sends them looking through group memberships
 * for an hour.
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Who a case type lets a handler pick.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
class AssigneeNarrowing {

	/**
	 * The case-type property holding the declaration.
	 */
	public const PROPERTY = 'assigneeNarrowing';

	/**
	 * The case field naming the team.
	 */
	public const FIELD_GROUP = 'assignedGroup';

	/**
	 * The case field naming the person.
	 */
	public const FIELD_USER = 'assignee';

	/**
	 * The rule a write refused for a team outside the narrowing names.
	 */
	public const RULE_GROUP_OUTSIDE = 'team-outside-the-case-type-narrowing';

	/**
	 * The rule a write refused for a person outside the narrowing names.
	 */
	public const RULE_USER_OUTSIDE = 'handler-outside-the-case-type-narrowing';

	/**
	 * The declaration this case type carries.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{allowedGroups: array<int, string>, allowedUsers: array<int, string>}
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function declarationFor(array $caseType): array {
		$declared = ($caseType[self::PROPERTY] ?? null);
		if (is_array($declared) === false) {
			$declared = [];
		}

		return [
			'allowedGroups' => $this->referenceList(value: ($declared['allowedGroups'] ?? [])),
			'allowedUsers' => $this->referenceList(value: ($declared['allowedUsers'] ?? [])),
		];
	}//end declarationFor()

	/**
	 * Whether this case type narrows anything at all.
	 *
	 * A case type declaring neither list keeps every choice. That is the
	 * behaviour every existing case type has today, and it stays the default
	 * so this change narrows nothing nobody asked to narrow.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when every team and every person stays choosable.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function narrowsNothing(array $caseType): bool {
		$declaration = $this->declarationFor(caseType: $caseType);

		return ($declaration['allowedGroups'] === [] && $declaration['allowedUsers'] === []);
	}//end narrowsNothing()

	/**
	 * The teams a picker may offer, out of the ones it knows.
	 *
	 * Answers the offered list unchanged when the case type narrows no teams,
	 * so a picker never has to know whether a narrowing exists.
	 *
	 * @param array<int, string>   $offered  The teams the picker would show.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The teams that survive the narrowing.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function narrowGroups(array $offered, array $caseType): array {
		return $this->narrow(
			offered: $offered,
			allowed: $this->declarationFor(caseType: $caseType)['allowedGroups']
		);
	}//end narrowGroups()

	/**
	 * The people a picker may offer, out of the ones it knows.
	 *
	 * @param array<int, string>   $offered  The people the picker would show.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The people that survive the narrowing.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function narrowUsers(array $offered, array $caseType): array {
		return $this->narrow(
			offered: $offered,
			allowed: $this->declarationFor(caseType: $caseType)['allowedUsers']
		);
	}//end narrowUsers()

	/**
	 * Whether one team may hold a case of this type.
	 *
	 * @param string               $group    The team.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when the team is allowed.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function allowsGroup(string $group, array $caseType): bool {
		return $this->allows(
			value: $group,
			allowed: $this->declarationFor(caseType: $caseType)['allowedGroups']
		);
	}//end allowsGroup()

	/**
	 * Whether one person may hold a case of this type.
	 *
	 * @param string               $user     The person.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when the person is allowed.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function allowsUser(string $user, array $caseType): bool {
		return $this->allows(
			value: $user,
			allowed: $this->declarationFor(caseType: $caseType)['allowedUsers']
		);
	}//end allowsUser()

	/**
	 * Refuse a write naming a team or a person outside the narrowing.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the team or the person is outside the narrowing.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function assertWritable(array $case, array $caseType): void {
		$group = trim((string)($case[self::FIELD_GROUP] ?? ''));
		if ($group !== '' && $this->allowsGroup(group: $group, caseType: $caseType) === false) {
			throw new RefusedException(
				rule: self::RULE_GROUP_OUTSIDE,
				sentence: 'The team ' . $group . ' is not one this case type allows to handle it. '
					. 'The list lives on the case type, under who may handle this.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$user = trim((string)($case[self::FIELD_USER] ?? ''));
		if ($user !== '' && $this->allowsUser(user: $user, caseType: $caseType) === false) {
			throw new RefusedException(
				rule: self::RULE_USER_OUTSIDE,
				sentence: 'The handler ' . $user . ' is not one this case type allows to handle it. '
					. 'The list lives on the case type, under who may handle this.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertWritable()

	/**
	 * One offered list, narrowed by one allowed list.
	 *
	 * @param array<int, string> $offered The offered references.
	 * @param array<int, string> $allowed The allowed references.
	 *
	 * @return array<int, string> The survivors, in the offered order.
	 */
	private function narrow(array $offered, array $allowed): array {
		if ($allowed === []) {
			return array_values($offered);
		}

		$kept = [];
		foreach ($offered as $entry) {
			if (in_array(trim((string)$entry), $allowed, true) === true) {
				$kept[] = $entry;
			}
		}

		return $kept;
	}//end narrow()

	/**
	 * Whether one reference passes one allowed list.
	 *
	 * @param string             $value   The reference.
	 * @param array<int, string> $allowed The allowed references.
	 *
	 * @return boolean True when the list is empty or carries the reference.
	 */
	private function allows(string $value, array $allowed): bool {
		if ($allowed === []) {
			return true;
		}

		return in_array(trim($value), $allowed, true);
	}//end allows()

	/**
	 * One declared list, cleaned of blanks and duplicates.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, string> The references.
	 */
	private function referenceList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$references = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$name = trim($entry);
			if ($name === '' || in_array($name, $references, true) === true) {
				continue;
			}

			$references[] = $name;
		}

		return $references;
	}//end referenceList()
}//end class
