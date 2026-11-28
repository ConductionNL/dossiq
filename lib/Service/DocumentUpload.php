<?php

/**
 * A document as it was handed in for upload: the case, the file and its metadata.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use RuntimeException;

/**
 * A document as it was handed in for upload: the case, the file and its metadata.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class DocumentUpload {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case (zaak) UUID the document is linked to
	 * @param string $fileName The original filename
	 * @param string $content The raw binary file content
	 * @param array<string, mixed> $metadata The submitted document metadata
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $fileName,
		public readonly string $content,
		public readonly array $metadata,
	) {
	}//end __construct()

	/**
	 * Check the required upload input and return the document type.
	 *
	 * @return string The document type uuid.
	 *
	 * @throws RuntimeException When the case, file name or type is missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function requireType(): string {
		if (trim($this->caseId) === '') {
			throw new RuntimeException('caseId is required');
		}

		if (trim($this->fileName) === '') {
			throw new RuntimeException('bestandsnaam is required');
		}

		$type = (string)($this->metadata['informatieobjecttype'] ?? '');
		if ($type === '') {
			throw new RuntimeException('informatieobjecttype is required');
		}

		return $type;
	}//end requireType()
}//end class
