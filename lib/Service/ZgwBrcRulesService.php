<?php

/**
 * Dossiq ZGW BRC (Besluiten) Business Rules Service
 *
 * Implements business rules for the Besluiten API as defined by VNG Realisatie.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * Business rules implemented:
 *
 * - brc-001: Valideren besluittype op het Besluit
 *   The besluittype must exist and be published (concept=false).
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-002: Garanderen uniciteit verantwoordelijkeOrganisatie en identificatie
 *   The combination of verantwoordelijkeOrganisatie + identificatie must be unique.
 *   Auto-generate identificatie if not provided. Identificatie and
 *   verantwoordelijkeOrganisatie are immutable after creation.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-003: Valideren informatieobject op BesluitInformatieObject
 *   The informatieobject URL must resolve to an existing document.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-004: Valideren aardRelatie op BesluitInformatieObject
 *   The aardRelatie is automatically set to 'legt_vast' on creation.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-005: Synchroniseren relaties met informatieobjecten (cross-register, in ZgwService)
 *   When a BesluitInformatieObject is created/deleted, sync to DRC.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-006: Synchroniseren relatie Besluit-Zaak met ZRC (cross-register, in ZgwService)
 *   When a Besluit has a zaak, a ZaakBesluit must be created/deleted in ZRC.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-007: Valideren zaak-besluittype relatie
 *   The zaak's zaaktype must be listed in the besluittype's zaaktypen.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * - brc-008: Valideren informatieobjecttype bij besluittype
 *   The informatieobjecttype of the linked informatieobject must appear
 *   in besluittype.informatieobjecttypen.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * BRC (Besluiten API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwBrcRulesService extends ZgwRulesBase {
	/**
	 * Rules for creating a besluit (POST /besluiten/v1/besluiten).
	 *
	 * Implements brc-001, brc-002, brc-007, in that order: the first refusal wins.
	 *
	 * @param array $body The ZGW request body (Dutch field names)
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenCreate(array $body): array {
		$decisionTypeUrl = $body['besluittype'] ?? '';

		$error = $this->checkBesluittype(decisionTypeUrl: $decisionTypeUrl)
			?? $this->checkDecisionIdentificationUnique(body: $body)
			?? $this->validateCaseBesluittypeRelation(caseUrl: $body['case'] ?? null, decisionTypeUrl: $decisionTypeUrl);
		if ($error !== null) {
			return $error;
		}

		// Brc-002: Auto-generate identificatie if not provided.
		if (empty($body['identificatie']) === true) {
			$body['identificatie'] = $this->generateIdentificatie(prefix: 'BESLUIT');
		}

		return $this->isValid(body: $body);
	}//end rulesBesluitenCreate()

	/**
	 * Brc-001: the besluittype URL points at a published besluittype.
	 *
	 * @param mixed $decisionTypeUrl The besluittype from the body
	 *
	 * @return array|null The refusal, or null when acceptable or nothing can be looked up
	 */
	private function checkBesluittype(mixed $decisionTypeUrl): ?array {
		if (empty($decisionTypeUrl) === true || $this->objectService === null) {
			return null;
		}

		return $this->validateTypeUrl(
			typeUrl: $decisionTypeUrl,
			fieldName: 'besluittype',
			schemaKey: 'decision_type_schema'
		);
	}//end checkBesluittype()

	/**
	 * Rules for updating a besluit (PUT /besluiten/v1/besluiten/{uuid}).
	 *
	 * Implements brc-001 and brc-002 immutability.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenUpdate(array $body, ?array $existingObject = null): array {
		$result = $this->isValid(body: $body);

		$result = $this->checkDecisionTypeImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		$result = $this->checkDecisionFieldImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		// Preserve immutable fields from existing object when omitted in PUT body.
		$result = $this->preserveImmutableDecisionFields(
			result: $result,
			existingObject: $existingObject
		);

		return $result;
	}//end rulesBesluitenUpdate()

	/**
	 * Rules for patching a besluit (PATCH /besluiten/v1/besluiten/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @see rulesBesluitenUpdate() Same immutability rules apply.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenPatch(array $body, ?array $existingObject = null): array {
		$result = $this->isValid(body: $body);

		$result = $this->checkDecisionTypeImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		return $this->checkDecisionFieldImmutability(
			result: $result,
			existingObject: $existingObject
		);
	}//end rulesBesluitenPatch()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * Check that besluittype is not changed on update/patch (brc-001).
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 */
	private function checkDecisionTypeImmutability(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];
		$newBesluittype = $body['besluittype'] ?? null;
		$existBesluittype = $existingObject['decisionType'] ?? '';

		if ($newBesluittype !== null && $existBesluittype !== '') {
			$newUuid = $this->extractUuid(url: $newBesluittype);
			if ($newUuid !== null && $newUuid !== $existBesluittype
				&& $this->extractUuid(url: $existBesluittype) !== $newUuid
			) {
				return $this->fieldImmutableError(fieldName: 'besluittype');
			}
		}

		return $result;
	}//end checkBesluitTypeImmutability()

	/**
	 * Check besluit field immutability (brc-002).
	 *
	 * Identificatie and verantwoordelijkeOrganisatie are immutable after creation.
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 */
	private function checkDecisionFieldImmutability(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];

		// Brc-002: identificatie is immutable.
		if (isset($body['identificatie']) === true) {
			$existingId = $existingObject['title'] ?? $existingObject['identifier'] ?? '';
			if ($existingId === '') {
				$existingId = $existingObject['identificatie'] ?? '';
			}

			if ($existingId !== '' && $body['identificatie'] !== $existingId) {
				return $this->fieldImmutableError(fieldName: 'identificatie');
			}
		}

		// Brc-002: verantwoordelijkeOrganisatie is immutable.
		if (isset($body['verantwoordelijkeOrganisatie']) === true) {
			$orgKey = 'responsibleOrganisation';
			$orgFallback = 'verantwoordelijkeOrganisatie';
			$existingOrg = $existingObject[$orgKey] ?? $existingObject[$orgFallback] ?? '';
			if ($existingOrg !== '' && $body['verantwoordelijkeOrganisatie'] !== $existingOrg) {
				return $this->fieldImmutableError(fieldName: 'verantwoordelijkeOrganisatie');
			}
		}

		return $result;
	}//end checkBesluitFieldImmutability()

	/**
	 * Preserve immutable besluit fields from existing object when omitted in PUT body.
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result with preserved fields
	 */
	private function preserveImmutableDecisionFields(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];

		if (isset($body['identificatie']) === false || $body['identificatie'] === '') {
			$existingId = $existingObject['title'] ?? $existingObject['identifier'] ?? '';
			if ($existingId === '') {
				$existingId = $existingObject['identificatie'] ?? '';
			}

			if ($existingId !== '') {
				$body['identificatie'] = $existingId;
			}
		}

		if (isset($body['verantwoordelijkeOrganisatie']) === false
			|| $body['verantwoordelijkeOrganisatie'] === ''
		) {
			$orgKey = 'responsibleOrganisation';
			$orgFallback = 'verantwoordelijkeOrganisatie';
			$existingOrg = $existingObject[$orgKey] ?? $existingObject[$orgFallback] ?? '';
			if ($existingOrg !== '') {
				$body['verantwoordelijkeOrganisatie'] = $existingOrg;
			}
		}

		$result['enrichedBody'] = $body;

		return $result;
	}//end preserveImmutableBesluitFields()

	/**
	 * Check that besluit identificatie + verantwoordelijkeOrganisatie is unique (brc-002).
	 *
	 * @param array $body The request body (ZGW Dutch field names)
	 *
	 * @return array|null Validation error, or null if unique
	 */
	private function checkDecisionIdentificationUnique(array $body): ?array {
		if (empty($body['identificatie']) === true || $this->objectService === null) {
			return null;
		}

		$identification = $body['identificatie'];
		$organisation = $body['verantwoordelijkeOrganisatie'] ?? '';

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->mappingConfig['sourceSchema'] ?? '';

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$searchParams = ['title' => $identification];
			if ($organisation !== '') {
				$searchParams['responsibleOrganisation'] = $organisation;
			}

			$query = $this->objectService->buildSearchQuery(
				requestParams: $searchParams,
				register: $register,
				schema: $schema
			);
			$result = $this->objectService->searchObjectsPaginated(query: $query);
			$total = $result['total'] ?? count($result['results'] ?? []);

			if ($total > 0) {
				return $this->error(
					status: 400,
					detail: 'De combinatie van verantwoordelijke_organisatie en identificatie is niet uniek.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'identificatie',
							code: 'identificatie-niet-uniek',
							reason: 'De combinatie van verantwoordelijke_organisatie en identificatie bestaat al.'
						),
					]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'brc-002: Could not check besluit identificatie uniqueness: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end checkBesluitIdentificatieUnique()

	/**
	 * Validate zaak-besluittype relation (brc-007).
	 *
	 * The zaak's zaaktype must be listed in the besluittype's zaaktypen, or the zaaktype must list
	 * the besluittype (by uuid or omschrijving). An unresolvable zaak or besluittype skips the check.
	 *
	 * @param mixed $caseUrl The zaak URL from the request
	 * @param mixed $decisionTypeUrl The besluittype URL from the request
	 *
	 * @return array|null Validation error, or null if valid
	 */
	private function validateCaseBesluittypeRelation(mixed $caseUrl, mixed $decisionTypeUrl): ?array {
		$register = $this->mappingConfig['sourceRegister'] ?? '';
		if ($caseUrl === null || $caseUrl === '' || empty($decisionTypeUrl) === true
			|| empty($register) === true || $this->objectService === null
		) {
			return null;
		}

		// Look up the zaak to get its zaaktype, and the besluittype.
		$caseCaseType = $this->getObjectByUrl(url: $caseUrl, schemaKey: 'case_schema')['caseType'] ?? '';
		if (empty($caseCaseType) === true) {
			return null;
		}

		$btData = $this->getObjectByUrl(url: $decisionTypeUrl, schemaKey: 'decision_type_schema');
		if ($btData === null) {
			return null;
		}

		$related = $this->isCaseTypeRelatedToDecisionType(
			caseTypeUuid: $this->extractUuid(url: $caseCaseType),
			btUuid: (string)$this->extractUuid(url: $decisionTypeUrl),
			btData: $btData
		);
		if ($related === true) {
			return null;
		}

		$detail = 'Het zaaktype van de zaak is niet gerelateerd aan het besluittype.';

		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(
					fieldName: 'nonFieldErrors',
					code: 'zaaktype-mismatch',
					reason: $detail
				),
			]
		);
	}//end validateCaseBesluittypeRelation()

	/**
	 * Brc-007: whether the besluittype lists the zaaktype, or else the zaaktype lists the besluittype.
	 *
	 * @param string|null $caseTypeUuid The zaak's zaaktype uuid
	 * @param string $btUuid The besluittype uuid
	 * @param array $btData The besluittype
	 *
	 * @return bool True when either side lists the other
	 */
	private function isCaseTypeRelatedToDecisionType(?string $caseTypeUuid, string $btUuid, array $btData): bool {
		// Check direction 1: BT.caseTypes contains the zaaktype UUID.
		foreach ($this->decodeList(value: $btData['caseTypes'] ?? []) as $ct) {
			$ctUuid = $this->extractUuid(url: (string)$ct);
			if ($ctUuid !== null && $ctUuid === $caseTypeUuid) {
				return true;
			}
		}

		// Check direction 2: ZT.decisionTypes contains the BT omschrijving or UUID.
		return $this->caseTypeListsDecisionType(caseTypeUuid: $caseTypeUuid, btUuid: $btUuid, btData: $btData);
	}//end isCaseTypeRelatedToDecisionType()

	/**
	 * Brc-007, direction 2: whether the zaaktype lists the besluittype by uuid or omschrijving.
	 *
	 * @param string|null $caseTypeUuid The zaak's zaaktype uuid
	 * @param string $btUuid The besluittype uuid
	 * @param array $btData The besluittype
	 *
	 * @return bool True when the zaaktype lists the besluittype
	 */
	private function caseTypeListsDecisionType(?string $caseTypeUuid, string $btUuid, array $btData): bool {
		$ztData = $this->findBySchemaKey(uuid: $caseTypeUuid, schemaKey: 'case_type_schema');
		if ($ztData === null) {
			return false;
		}

		$btOmschrijving = $btData['title'] ?? ($btData['name'] ?? '');
		foreach ($this->decodeList(value: $ztData['decisionTypes'] ?? []) as $dt) {
			$dtStr = (string)$dt;
			// Match by omschrijving or UUID.
			if ($this->extractUuid(url: $dtStr) === $btUuid || ($btOmschrijving !== '' && $dtStr === $btOmschrijving)) {
				return true;
			}
		}

		return false;
	}//end caseTypeListsDecisionType()

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

		return ['uuid' => $docTypeUuid, 'name' => $dtData['name'] ?? ''];
	}//end getDocumentTypeOfDocument()

	/**
	 * Look up the object a URL's uuid names in the given schema.
	 *
	 * @param string $url The object URL
	 * @param string $schemaKey Settings key of the object's schema
	 *
	 * @return array|null The object, or null when the URL holds no uuid or nothing is found
	 */
	private function getObjectByUrl(string $url, string $schemaKey): ?array {
		$uuid = $this->extractUuid(url: $url);
		if ($uuid === null) {
			return null;
		}

		return $this->findBySchemaKey(uuid: $uuid, schemaKey: $schemaKey);
	}//end getObjectByUrl()

	/**
	 * Read a list field that may arrive as an array or a JSON string.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return array The list, empty when the value is neither a list nor JSON for one
	 */
	private function decodeList(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true) ?? [];
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end decodeList()

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
