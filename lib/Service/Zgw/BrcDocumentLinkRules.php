<?php

/**
 * BRC rules for linking a document to a besluit
 *
 * A besluitinformatieobject names an existing document (brc-003), of a type
 * the besluittype allows (brc-008), and gets its relation text (brc-004).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwRulesBase;

/**
 * Create rules of a besluitinformatieobject.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrcDocumentLinkRules extends ZgwRulesBase {
	use ReadsLinkedObjects;

	/**
	 * Rules for creating a BesluitInformatieObject.
	 *
	 * Implements brc-003, brc-004, brc-008.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function rulesBesluitinformatieobjectenCreate(array $body): array {
		// Brc-003: Validate informatieobject URL.
		$ioUrl = $body['informatieobject'] ?? '';
		if ($ioUrl !== '' && $this->objectService !== null) {
			$error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
			if ($error !== null) {
				return $error;
			}
		}

		// Brc-008: Validate informatieobjecttype in besluittype.informatieobjecttypen.
		$decisionUrl = $body['decision'] ?? '';
		if ($decisionUrl !== '' && $ioUrl !== '' && $this->objectService !== null) {
			$iotError = $this->validateBioInformatieobjecttype(
				decisionUrl: $decisionUrl,
				ioUrl: $ioUrl
			);
			if ($iotError !== null) {
				return $iotError;
			}
		}

		// Brc-004: Set aardRelatieWeergave automatically.
		$body['natureRelationshipDisplay'] = 'Legt vast, omgekeerd: wordt vastgelegd door';

		return $this->isValid(body: $body);
	}//end rulesBesluitinformatieobjectenCreate()

	/**
	 * Validate informatieobjecttype is in besluittype.informatieobjecttypen (brc-008).
	 *
	 * A besluittype that allows no documenttypes refuses every document. An unresolvable besluit,
	 * document or documenttype skips the check.
	 *
	 * @param string $decisionUrl The besluit URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return array|null Validation error, or null if valid
	 */
	private function validateBioInformatieobjecttype(string $decisionUrl, string $ioUrl): ?array {
		if ($this->objectService === null) {
			return null;
		}

		// Get the besluit to find its besluittype, then the besluittype.
		$decisionTypeId = $this->getObjectByUrl(url: $decisionUrl, schemaKey: 'decision_schema')['decisionType'] ?? '';
		if (empty($decisionTypeId) === true) {
			return null;
		}

		$btData = $this->getObjectByUrl(url: $decisionTypeId, schemaKey: 'decision_type_schema');
		if ($btData === null) {
			return null;
		}

		// Get the allowed documentTypes from besluittype.
		$allowedDocTypes = $this->decodeList(value: $btData['documentTypes'] ?? '[]');
		if (empty($allowedDocTypes) === true) {
			return $this->missingInformatieobjecttypeError();
		}

		$docType = $this->getDocumentTypeOfDocument(ioUrl: $ioUrl);
		if ($docType === null) {
			return null;
		}

		// Check if the documentType is in the allowed list, by name or by uuid.
		if (in_array($docType['name'], $allowedDocTypes, true) === true
			|| in_array($docType['uuid'], $allowedDocTypes, true) === true
		) {
			return null;
		}

		return $this->missingInformatieobjecttypeError();
	}//end validateBioInformatieobjecttype()

	/**
	 * The documenttype of the document an informatieobject URL points at.
	 *
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return array{uuid: string, name: mixed}|null The documenttype's uuid and name, or null when
	 *                                               the document or its type cannot be resolved
	 */
	private function getDocumentTypeOfDocument(string $ioUrl): ?array {
		$docTypeId = $this->getObjectByUrl(url: $ioUrl, schemaKey: 'document_schema')['documentType'] ?? '';
		if (empty($docTypeId) === true) {
			return null;
		}

		$docTypeUuid = $this->extractUuid(url: $docTypeId);
		if ($docTypeUuid === null) {
			return null;
		}

		$dtData = $this->findBySchemaKey(uuid: $docTypeUuid, schemaKey: 'document_type_schema');
		if ($dtData === null) {
			return null;
		}

		return ['uuid' => $docTypeUuid, 'name' => $this->textOf(value: ($dtData['name'] ?? ''))];
	}//end getDocumentTypeOfDocument()

	/**
	 * The brc-008 refusal: the document's type is not one the besluittype allows.
	 *
	 * @return array The validation error
	 */
	private function missingInformatieobjecttypeError(): array {
		$detail = 'Het informatieobjecttype van het informatieobject is niet gespecificeerd in '
			.'het besluittype.informatieobjecttypen.';

		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(
					fieldName: 'nonFieldErrors',
					code: 'missing-informatieobjecttype',
					reason: $detail
				),
			]
		);
	}//end missingInformatieobjecttypeError()
}//end class
