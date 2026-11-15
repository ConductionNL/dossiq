<?php

/**
 * One attempt to send a case note on: the case and the note.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notes;

/**
 * The case a note is on, and the note as OpenRegister answers it.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class NoteAttempt {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId The case the note is on.
	 * @param array<string, mixed> $note   The note.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly array $note,
	) {
	}//end __construct()
}//end class
