<?php

/**
 * Dossiq document search outcome.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assessment
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assessment;

/**
 * Whether a case records that its search for documents found nothing.
 *
 * Decision D1 (Ruben, 14 October): a request whose search finds nothing is
 * not stuck, and it is not waved through either. The handler records the
 * outcome `nothing_found` with a note saying where was searched, and that
 * record counts as "every document assessed". An outcome without a note is
 * not a record, so it counts for nothing.
 *
 * The fields are the case's own (register.d/89-search-outcome-and-admissibility.json),
 * named by what they record, so any case type that searches for documents can use them.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
final class DocumentSearchOutcome {
	/**
	 * The case field holding the outcome.
	 */
	public const FIELD = 'documentSearchOutcome';

	/**
	 * The case field holding the handler's note.
	 */
	public const NOTE_FIELD = 'documentSearchNote';

	/**
	 * The outcome that says nothing was found.
	 */
	public const NOTHING_FOUND = 'nothing_found';

	/**
	 * Whether the case records, with a note, that nothing was found.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
	 */
	public static function recordsNothingFound(array $case): bool {
		if ((string)($case[self::FIELD] ?? '') !== self::NOTHING_FOUND) {
			return false;
		}

		return trim((string)($case[self::NOTE_FIELD] ?? '')) !== '';
	}//end recordsNothingFound()
}//end class
