<?php

/**
 * The bytes of one pick, as WooGatherAdd stores them on a Woo case.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * A picked file: its name, its bytes and the MIME type its source gave.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 */
final class WooPickedFile {
	/**
	 * Constructor.
	 *
	 * @param string $fileName The file name.
	 * @param string $content  The bytes.
	 * @param string $mimeType The MIME type the source gave, '' when none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $fileName,
		public readonly string $content,
		public readonly string $mimeType = '',
	) {
	}//end __construct()

	/**
	 * The SHA-256 of the bytes, which is how a duplicate is recognised.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	public function sha256(): string {
		return hash('sha256', $this->content);
	}//end sha256()
}//end class
