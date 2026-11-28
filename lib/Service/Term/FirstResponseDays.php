<?php

/**
 * The working days a case type promises its first response within.
 *
 * Split out of {@see \OCA\Dossiq\Service\ComplaintService}: the declared
 * `firstResponse` term of a case type wins, and a caller's statutory fallback
 * applies when nothing is declared.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use OCA\Dossiq\Service\TermKind;

/**
 * Reads a case type's declared first-response term as working days.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class FirstResponseDays {

	/**
	 * Constructor.
	 *
	 * @param TermResolution|null $resolution The case type's declared terms; without it the fallback applies.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly ?TermResolution $resolution = null,
	) {
	}//end __construct()

	/**
	 * The declared first-response days of the case type, or the fallback.
	 *
	 * @param string $caseType The case type whose term is read.
	 * @param int $fallback The days when the case type declares no term.
	 *
	 * @return int The working days.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
	 */
	public function days(string $caseType, int $fallback): int {
		$resolved = $this->resolution?->resolve(
			caseType: $caseType,
			context: [],
			kind: TermKind::FIRST_RESPONSE
		);

		$declared = (int)($resolved['definition']['standardDurationDays'] ?? 0);

		if ($declared > 0) {
			return $declared;
		}

		return $fallback;
	}//end days()
}//end class
