<?php

/**
 * One file to attach to a publication or document object.
 *
 * Built by `OpenCatalogiApiClient::attachFile()` and handed to the steps that add it and that report a
 * failed attach: the object, the file name and the base64 content.
 *
 * @category Publication
 * @package  OCA\Dossiq\Service\Publication
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

namespace OCA\Dossiq\Service\Publication;

/**
 * One file to attach to a publication or document object.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
final class FileAttachment {

	/**
	 * Constructor.
	 *
	 * @param string $objectId      The object id to attach the file to.
	 * @param string $fileName      The file name.
	 * @param string $base64Content The base64-encoded file content.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly string $fileName,
		public readonly string $base64Content,
	) {
	}//end __construct()
}//end class
