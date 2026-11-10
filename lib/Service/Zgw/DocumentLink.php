<?php

/**
 * A document linked to a case or a besluit, by uuid (drc-004).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * The object an objectinformatieobject links a document to.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class DocumentLink {
	/**
	 * Constructor.
	 *
	 * @param string $objectType   The object type: case or decision.
	 * @param string $objectUuid   The case's or besluit's uuid.
	 * @param string $documentUuid The document's uuid.
	 */
	public function __construct(
		public readonly string $objectType,
		public readonly string $objectUuid,
		public readonly string $documentUuid,
	) {
	}//end __construct()

	/**
	 * The link as a refusal names it: `case <uuid> with document <uuid>`.
	 *
	 * @return string The words.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function label(): string {
		return $this->objectType . ' ' . $this->objectUuid . ' with document ' . $this->documentUuid;
	}//end label()
}//end class
