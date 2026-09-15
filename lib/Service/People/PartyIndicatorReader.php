<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * What the indicators on a case's parties refuse.
 *
 * An indicator lives on the party, not on the case, so one protected person
 * reaches every case they are on without any of those cases being written.
 * OpenRegister evaluates the effect where the act happens; dossiq has two
 * such acts of its own, a publication and a file request, and this is what
 * they ask before doing them.
 *
 * Every read degrades to "nothing refuses it" when OpenRegister is absent or
 * predates the party model, because a case must stay workable on an instance
 * that has no parties at all. That is a deliberate fail-open: the refusal is
 * also enforced inside OpenRegister, at the same two acts, so this reader is
 * the sentence a handler sees rather than the only thing standing in the way.
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
 */
class PartyIndicatorReader {

	/**
	 * OpenRegister's guard. Resolved through the generic class resolver, the
	 * same exception ADR-084 makes for the file and contact services: the
	 * published object contract has no party method.
	 */
	private const GUARD = 'OCA\\OpenRegister\\Service\\Party\\PartyIndicatorGuard';

	/**
	 * The effect that refuses publishing a file on the object.
	 */
	public const EFFECT_REFUSE_PUBLICATION = 'refuse-publication';

	/**
	 * The effect that refuses an outbound message to the party.
	 */
	public const EFFECT_REFUSE_SEND = 'refuse-send';

	/**
	 * @param SettingsService $settingsService Resolves OpenRegister's classes.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Every indicator every party on a case carries.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The indicators, each with `party`, `role`, `key`, `label`, `effect`.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function indicatorsOn(string $caseId): array {
		$guard = $this->guard();
		if ($guard === null) {
			return [];
		}

		try {
			$found = $guard->indicatorsForObject($caseId);
		} catch (Throwable) {
			return [];
		}

		if (is_array($found) === false) {
			return [];
		}

		return array_values(array_filter($found, static fn ($row): bool => is_array($row) === true));
	}//end indicatorsOn()

	/**
	 * The indicator refusing publication on a case, or null when none does.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The refusing indicator.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function publicationRefusal(string $caseId): ?array {
		foreach ($this->indicatorsOn(caseId: $caseId) as $indicator) {
			if ((string)($indicator['effect'] ?? '') === self::EFFECT_REFUSE_PUBLICATION) {
				return $indicator;
			}
		}

		return null;
	}//end publicationRefusal()

	/**
	 * The label of the indicator refusing an outbound message to one party,
	 * or null when nothing refuses it.
	 *
	 * Per party rather than per case, on purpose: one protected party must
	 * not silence the letter to the other five.
	 *
	 * @param string $partyUuid The party, '' when the link names none.
	 *
	 * @return string|null The refusing indicator's label.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function sendRefusalFor(string $partyUuid): ?string {
		if (trim($partyUuid) === '') {
			return null;
		}

		$guard = $this->guard();
		if ($guard === null) {
			return null;
		}

		try {
			$label = $guard->sendRefusalFor($partyUuid);
		} catch (Throwable) {
			return null;
		}

		if (is_string($label) === false || trim($label) === '') {
			return null;
		}

		return $label;
	}//end sendRefusalFor()

	/**
	 * The party uuid a person link names, '' when it names none.
	 *
	 * A user or a contact link carries no party, which is not an error: it
	 * is what every link written before the party model looks like.
	 *
	 * @param array<string, mixed> $link The link, as the contacts listing answers it.
	 *
	 * @return string The party uuid.
	 */
	public function partyUuidOf(array $link): string {
		return trim((string)($link['partyUuid'] ?? ''));
	}//end partyUuidOf()

	/**
	 * OpenRegister's indicator guard, or null when this instance has none.
	 *
	 * @return object|null The guard.
	 */
	private function guard(): ?object {
		$guard = $this->settingsService->getOpenRegisterClass(class: self::GUARD);
		if ($guard === null) {
			return null;
		}

		// A guard that cannot answer both questions is a guard that would
		// fatal on the first call. An OpenRegister older than the party
		// model resolves the class name to nothing, but a partial one is
		// worth refusing here rather than at the act.
		if (is_callable([$guard, 'indicatorsForObject']) === false
			|| is_callable([$guard, 'sendRefusalFor']) === false
		) {
			return null;
		}

		return $guard;
	}//end guard()
}//end class
