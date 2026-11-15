<?php

/**
 * Where a case's dossier documents and their links are stored.
 *
 * Read by `CaseArchiveReadiness` when it counts how many of a case's
 * documents are final. Assigned field by field by the reader that builds it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

/**
 * The ObjectService, register and the two dossier schemas.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
final class DossierDocumentStore {
	/**
	 * OpenRegister's ObjectService, or null when it is not available.
	 *
	 * @var object|null
	 */
	public ?object $objectService = null;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The schema of the case-to-document links.
	 *
	 * @var string
	 */
	public string $joinSchema = '';

	/**
	 * The schema of the documents.
	 *
	 * @var string
	 */
	public string $infoSchema = '';

	/**
	 * Whether the ObjectService, the register and both schemas are all there.
	 *
	 * @return bool True when the store can be read.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-001-zaak-objects-must-support-linked-documents-via-zgw-informatieobject-and-zaakinformatieobject
	 */
	public function isComplete(): bool {
		return ($this->objectService !== null && $this->register !== '' && $this->joinSchema !== '' && $this->infoSchema !== '');
	}//end isComplete()
}//end class
