<?php

/**
 * What a status says about itself.
 *
 * A status in dossiq used to be a name, an order, a colour and a checklist.
 * Three more things it has to say are read here and nowhere else: what makes
 * it true, who the case waits on while it sits there, and how long it may sit
 * there. One reader rather than three, because all three arrive on the same
 * row and a second reader is how one of them starts being spelled differently
 * from the others.
 *
 * Every method takes the statusType ROW rather than its id, so the whole class
 * is exercisable without a register.
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
 * Reads the three declarations a statusType may carry.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusDeclaration {

	/**
	 * The case waits on nobody in particular: it is ours to move.
	 *
	 * This is what an UNDECLARED status reads as, and that is deliberate. A
	 * fourth bucket called "not declared" would put most of the queue in it on
	 * every case type nobody has annotated yet, which answers the team lead's
	 * question with a shrug.
	 */
	public const WAITING_ON_US = 'us';

	/**
	 * The case waits on the person who asked for it.
	 *
	 * Separate from {@see self::WAITING_ON_THIRD_PARTY} because the Awb treats
	 * the two differently: a hersteltermijn suspends the beslistermijn and an
	 * advice request does not.
	 */
	public const WAITING_ON_APPLICANT = 'applicant';

	/**
	 * The case waits on somebody who is neither us nor the applicant.
	 */
	public const WAITING_ON_THIRD_PARTY = 'thirdParty';

	/**
	 * The three values, in the order the schema enumerates them.
	 *
	 * @var array<int, string>
	 */
	public const WAITING_ON_VALUES = [
		self::WAITING_ON_US,
		self::WAITING_ON_APPLICANT,
		self::WAITING_ON_THIRD_PARTY,
	];

	/**
	 * The condition kinds a derivation may be written in.
	 *
	 * The vocabulary is OpenRegister's `lifecycleCondition` rule kind, which is
	 * not specified there yet. Writing dossiq's evaluation against the same
	 * three names is what makes the move a DELETION later rather than a
	 * rewrite: the declarations stay as they are and this class goes away.
	 *
	 * @var array<int, string>
	 */
	public const CONDITION_KINDS = [
		'fieldPresent',
		'fieldEquals',
		'documentPresent',
	];

	/**
	 * The conditions under which this status holds, normalised.
	 *
	 * A condition whose kind is not one this app evaluates is DROPPED rather
	 * than failed. An unknown kind is a declaration written for a version of
	 * the vocabulary this install does not have, and refusing it would hold
	 * every case in the status before it with a reason nobody can act on.
	 * Dropping it is visible in the other direction: the status simply is not
	 * derived by that condition.
	 *
	 * @param array<string, mixed> $statusType The statusType row.
	 *
	 * @return array<int, array<string, mixed>> The conditions worth evaluating.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function derivedWhen(array $statusType): array {
		$declared = ($statusType['derivedWhen'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$conditions = [];
		foreach ($declared as $condition) {
			if (is_array($condition) === false) {
				continue;
			}

			$kind = (string)($condition['kind'] ?? '');
			if (in_array($kind, self::CONDITION_KINDS, true) === false) {
				continue;
			}

			$conditions[] = [
				'kind' => $kind,
				'field' => (string)($condition['field'] ?? ''),
				'value' => (string)($condition['value'] ?? ''),
				'documentType' => (string)($condition['documentType'] ?? ''),
				'label' => (string)($condition['label'] ?? ''),
			];
		}

		return $conditions;
	}//end derivedWhen()

	/**
	 * Whether this status is derived rather than picked.
	 *
	 * A status that declares its conditions leaves the list of moves a handler
	 * chooses from. If it could be both, the two disagree within a week and
	 * nobody knows which one is the record.
	 *
	 * @param array<string, mixed> $statusType The statusType row.
	 *
	 * @return bool True when the status declares at least one condition.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function isDerived(array $statusType): bool {
		return $this->derivedWhen(statusType: $statusType) !== [];
	}//end isDerived()

	/**
	 * Who the case waits on while it sits in this status.
	 *
	 * @param array<string, mixed> $statusType The statusType row.
	 *
	 * @return string One of the three values; `us` when the status declares nothing.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function waitingOn(array $statusType): string {
		$declared = trim((string)($statusType['waitingOn'] ?? ''));
		if (in_array($declared, self::WAITING_ON_VALUES, true) === false) {
			return self::WAITING_ON_US;
		}

		return $declared;
	}//end waitingOn()

	/**
	 * How many working days a case may sit in this status.
	 *
	 * @param array<string, mixed> $statusType The statusType row.
	 *
	 * @return int|null The maximum, or null when the status declares none.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function maximumDwell(array $statusType): ?int {
		$declared = ($statusType['maximumDwell'] ?? null);
		if (is_numeric($declared) === false) {
			return null;
		}

		$days = (int)$declared;
		if ($days < 1) {
			return null;
		}

		return $days;
	}//end maximumDwell()
}//end class
