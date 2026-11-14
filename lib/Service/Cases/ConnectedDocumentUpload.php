<?php

/**
 * A document from a connected system as the upload path takes it: the case, the file, its media type and metadata.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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

namespace OCA\Dossiq\Service\Cases;

/**
 * A document from a connected system as the upload path takes it: the case, the file, its media type and metadata.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ConnectedDocumentUpload {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case uuid
	 * @param string $fileName The file name
	 * @param string $content The bytes
	 * @param string $format The media type, '' when the system named none
	 * @param array<string, mixed> $metadata The document metadata
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $fileName,
		public readonly string $content,
		public readonly string $format,
		public readonly array $metadata,
	) {
	}//end __construct()
}//end class
