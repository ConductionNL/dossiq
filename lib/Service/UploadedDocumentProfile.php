<?php

/**
 * What an upload was resolved to before it is stored: type, confidentiality, direction and correspondents.
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

/**
 * What an upload was resolved to before it is stored: type, confidentiality, direction and correspondents.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class UploadedDocumentProfile {
	/**
	 * Constructor.
	 *
	 * @param string $type The document type uuid
	 * @param string $classification The confidentiality, already checked
	 * @param string $direction The direction, already coerced onto the enum
	 * @param array{sender $correspondents  string, recipients: array<int, string>}:Who the document is from and to
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $classification,
		public readonly string $direction,
		public readonly array $correspondents,
	) {
	}//end __construct()
}//end class
