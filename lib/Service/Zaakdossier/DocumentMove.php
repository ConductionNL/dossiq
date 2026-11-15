<?php

/**
 * Dossiq DocumentMove
 *
 * An API-first document's file moving into the case its first join names.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * The record as it was read, its id, the case and the file's name.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
final class DocumentMove {

	/**
	 * The record's file name, '' when it names none.
	 *
	 * @var string
	 */
	public readonly string $fileName;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $record   The informatieobject as read, [] when not found.
	 * @param string               $recordId The informatieobject uuid.
	 * @param string               $caseId   The case uuid the join names.
	 */
	public function __construct(
		public readonly array $record,
		public readonly string $recordId,
		public readonly string $caseId,
	) {
		$this->fileName = (string)($record['fileName'] ?? '');
	}//end __construct()

	/**
	 * The line the move is logged with.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function logLine(): string {
		return 'Dossiq documents: moved ' . $this->fileName . ' of ' . $this->recordId . ' into case ' . $this->caseId;
	}//end logLine()
}//end class
