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

use OCA\Dossiq\Service\Zgw\BrcDocumentLinkRules;
use OCA\Dossiq\Service\Zgw\BrcImmutabilityRules;
use OCA\Dossiq\Service\Zgw\ReadsLinkedObjects;
use Psr\Log\LoggerInterface;

/**
 * BRC (Besluiten API) business rule validation and enrichment.
 *
 * Holds the create rules of a besluit and hands the update, patch and document
 * link rules to their own groups.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwBrcRulesService extends ZgwRulesBase {
	use ReadsLinkedObjects;

	/**
	 * What a besluit may not change on update and patch.
	 *
	 * @var BrcImmutabilityRules
	 */
	private BrcImmutabilityRules $immutability;

	/**
	 * The create rules of a besluitinformatieobject.
	 *
	 * @var BrcDocumentLinkRules
	 */
	private BrcDocumentLinkRules $documentLinks;

	/**
	 * Constructor.
	 *
	 * Takes what every rules class takes and builds its two rule groups from
	 * the same three, so the dispatcher and the container still see one BRC
	 * rules service.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 */
	public function __construct(LoggerInterface $logger, SettingsService $settingsService, FieldValidator $fieldValidator) {
		parent::__construct(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->immutability = new BrcImmutabilityRules(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->documentLinks = new BrcDocumentLinkRules(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
	}//end __construct()

	/**
	 * Set the request context here and on both rule groups.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService.
	 * @param array|null $mappingConfig The mapping configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		parent::setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->immutability->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->documentLinks->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
	}//end setContext()

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
	 * Rules for updating a besluit (PUT /besluiten/v1/besluiten/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @see BrcImmutabilityRules::rulesBesluitenUpdate()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenUpdate(array $body, ?array $existingObject = null): array {
		return $this->immutability->rulesBesluitenUpdate(body: $body, existingObject: $existingObject);
	}//end rulesBesluitenUpdate()

	/**
	 * Rules for patching a besluit (PATCH /besluiten/v1/besluiten/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @see BrcImmutabilityRules::rulesBesluitenPatch()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitenPatch(array $body, ?array $existingObject = null): array {
		return $this->immutability->rulesBesluitenPatch(body: $body, existingObject: $existingObject);
	}//end rulesBesluitenPatch()

	/**
	 * Rules for creating a BesluitInformatieObject.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @see BrcDocumentLinkRules::rulesBesluitinformatieobjectenCreate()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluitinformatieobjectenCreate(array $body): array {
		return $this->documentLinks->rulesBesluitinformatieobjectenCreate(body: $body);
	}//end rulesBesluitinformatieobjectenCreate()

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
	}//end checkDecisionIdentificationUnique()

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

		$btOmschrijving = $this->textOf(value: ($btData['title'] ?? ($btData['name'] ?? '')));
		foreach ($this->decodeList(value: $ztData['decisionTypes'] ?? []) as $dt) {
			$dtStr = (string)$dt;
			// Match by omschrijving or UUID.
			if ($this->extractUuid(url: $dtStr) === $btUuid || ($btOmschrijving !== '' && $dtStr === $btOmschrijving)) {
				return true;
			}
		}

		return false;
	}//end caseTypeListsDecisionType()
}//end class
