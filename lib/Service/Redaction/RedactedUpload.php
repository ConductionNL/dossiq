<?php

/**
 * Dossiq redacted upload: a version the officer redacted elsewhere.
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
 * The uploaded file's name and bytes.
 *
 * @spec openspec/specs/document-redaction/spec.md
 */
final class RedactedUpload {
	/**
	 * Constructor.
	 *
	 * @param string $fileName The uploaded file's name.
	 * @param string $content  The uploaded bytes.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $fileName,
		public readonly string $content,
	) {
	}//end __construct()
}//end class
