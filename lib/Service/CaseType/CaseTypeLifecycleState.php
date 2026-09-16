<?php

/**
 * Draft, in use or retired, named as one value.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The state of a case type, derived from the three fields that already answer it.
 *
 * 🔑 NO FOURTH FIELD. `isDraft`, `validFrom` and `validUntil` were already on
 * the schema, and they already carry the answer between them; adding a
 * `retired` boolean beside them would have made two sources of truth that drift
 * the first time somebody sets an end date without pressing Retire. So the
 * state is derived and named, and retiring is the act of writing `validUntil`.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class CaseTypeLifecycleState {

	/**
	 * Not published. It takes no cases and is not offered.
	 */
	public const DRAFT = 'draft';

	/**
	 * Published and inside its validity window. It takes new cases.
	 */
	public const IN_USE = 'in_use';

	/**
	 * Published and past its validity window, or not yet inside it. It takes no
	 * new case, and every case it already has stays readable and finishable.
	 */
	public const RETIRED = 'retired';

	/**
	 * The state of one case type on a given day.
	 *
	 * @param array<string, mixed>   $caseType The case type row.
	 * @param DateTimeInterface|null $onDay    The day to judge it on, or null for today.
	 *
	 * @return string One of draft, in_use or retired.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function stateOf(array $caseType, ?DateTimeInterface $onDay = null): string {
		if (($caseType['isDraft'] ?? false) === true) {
			return self::DRAFT;
		}

		$today = ($onDay ?? new DateTimeImmutable('today'));
		$day = $today->format('Y-m-d');

		$from = $this->day(value: ($caseType['validFrom'] ?? null));
		if ($from !== '' && $day < $from) {
			return self::RETIRED;
		}

		$until = $this->day(value: ($caseType['validUntil'] ?? null));
		if ($until !== '' && $day > $until) {
			return self::RETIRED;
		}

		return self::IN_USE;
	}//end stateOf()

	/**
	 * Whether a case type will take a new case today.
	 *
	 * @param array<string, mixed>   $caseType The case type row.
	 * @param DateTimeInterface|null $onDay    The day to judge it on, or null for today.
	 *
	 * @return boolean True when a handler may start a case of this type.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function acceptsNewCases(array $caseType, ?DateTimeInterface $onDay = null): bool {
		return ($this->stateOf(caseType: $caseType, onDay: $onDay) === self::IN_USE);
	}//end acceptsNewCases()

	/**
	 * A date value as a `Y-m-d` day, whichever shape it was stored in.
	 *
	 * OpenRegister stores a `format: date` property as `Y-m-d`, but a value
	 * that arrived through the API can carry a time and a zone. Comparing the
	 * raw strings would then make `2026-09-15T00:00:00+02:00` sort after
	 * `2026-09-15`, and a case type would retire itself a day early.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The day, or '' when there is none.
	 */
	private function day(mixed $value): string {
		if (is_string($value) === false || trim($value) === '') {
			return '';
		}

		$stamp = strtotime($value);
		if ($stamp === false) {
			return '';
		}

		return gmdate('Y-m-d', $stamp);
	}//end day()
}//end class
