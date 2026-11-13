<?php

/**
 * Dossiq redaction run: one officer's step on one partly public document.
 *
 * The case, the document and the officer, and, as the step goes, the
 * assessment that judged it partly public, the original's informatieobject,
 * its file, its text and the redaction record as it is being written.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-redaction/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

/**
 * The state of one redaction step, built and read by DocumentRedactionService only.
 *
 * @spec openspec/specs/document-redaction/spec.md
 */
final class RedactionRun {
	/**
	 * The assessment that judged the document partly public.
	 *
	 * @var array<string, mixed>
	 */
	public array $assessment = [];

	/**
	 * The original's informatieobject.
	 *
	 * @var array<string, mixed>
	 */
	public array $original = [];

	/**
	 * The Nextcloud file of the original.
	 *
	 * @var integer
	 */
	public int $fileId = 0;

	/**
	 * The text of the original.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The redaction record as this step writes it.
	 *
	 * @var array<string, mixed>
	 */
	public array $redaction = [];

	/**
	 * Constructor.
	 *
	 * @param string $caseId      The case.
	 * @param string $documentRef The partly public document.
	 * @param string $userId      The officer.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $documentRef,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
