<?php

/**
 * Dossiq TermKind.
 *
 * The four clocks a gemeente actually runs, as one vocabulary.
 *
 * dossiq had one clock per case. A gemeente runs four: the statutory term the
 * citizen is told about, the service norm a teamleider steers on, the fase term
 * inside it, and the pause the Awb allows. Collapsing them into one field means
 * a case that is on time for the citizen and six weeks late for the team reads
 * green, and a phase that has eaten the whole term is invisible until the term
 * itself expires.
 *
 * Every clock is a TermijnInstance carrying one of these kinds, so there is one
 * calendar, one pause rule, one escalation path and one report. Three date
 * columns on `case` would have meant three places computing a working day,
 * which is the duplication ADR-011 exists to stop.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The kind a term instance carries (REQ-TERM-060).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
final class TermKind {
	/**
	 * The term the Awb sets, and the one the citizen is told about.
	 *
	 * @var string
	 */
	public const STATUTORY = 'statutory';

	/**
	 * The service norm the team gives itself, beside the statutory term.
	 *
	 * @var string
	 */
	public const PLANNED = 'planned';

	/**
	 * The teamleider's own target, which no citizen surface ever shows.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * One phase's own clock, running inside the case term.
	 *
	 * @var string
	 */
	public const PHASE = 'phase';

	/**
	 * Every kind, in the order a case page reads them.
	 *
	 * @var array<int, string>
	 */
	public const ALL = [
		self::STATUTORY,
		self::PLANNED,
		self::INTERNAL,
		self::PHASE,
	];

	/**
	 * Private constructor: this is a vocabulary, not an object.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Whether a value is one of the four kinds.
	 *
	 * @param string $kind The candidate.
	 *
	 * @return bool True when the vocabulary knows it.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public static function isKnown(string $kind): bool {
		return in_array($kind, self::ALL, true);
	}//end isKnown()

	/**
	 * The kind an instance row carries, defaulting to statutory.
	 *
	 * Every instance written before this change is a statutory term, so an
	 * absent `kind` reads as one. An unknown value reads as statutory too:
	 * treating a clock nobody recognises as the citizen's term is the reading
	 * that shows it rather than the reading that hides it.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return string One of the four kinds.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public static function ofInstance(array $instance): string {
		$kind = (string)($instance['kind'] ?? '');

		if (self::isKnown(kind: $kind) === true) {
			return $kind;
		}

		return self::STATUTORY;
	}//end ofInstance()

	/**
	 * Whether a citizen may be shown this clock (REQ-TERM-063).
	 *
	 * Only the statutory term is answered true. The internal target is refused
	 * by name in the requirement; the planned end and the phase term are
	 * refused for the same reason, that they are the team's working plan and
	 * not a promise made to anyone outside. A portal that quotes a service norm
	 * has made it a promise, and the next missed one is a complaint.
	 *
	 * @param string $kind The kind.
	 *
	 * @return bool True only for the statutory term.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public static function isCitizenVisible(string $kind): bool {
		return $kind === self::STATUTORY;
	}//end isCitizenVisible()

	/**
	 * Keep only the clocks a citizen-facing surface may render.
	 *
	 * @param array<int, array<string, mixed>> $terms The term instances.
	 *
	 * @return array<int, array<string, mixed>> The statutory ones, reindexed.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public static function citizenVisible(array $terms): array {
		$visible = [];

		foreach ($terms as $term) {
			if (self::isCitizenVisible(kind: self::ofInstance(instance: $term)) === true) {
				$visible[] = $term;
			}
		}//end foreach

		return $visible;
	}//end citizenVisible()
}//end class
