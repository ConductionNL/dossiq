<?php

/**
 * Dossiq ZGW ZRC (Zaken) Business Rules Service
 *
 * Implements business rules for the Zaken API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
 *
 * Business rules implemented:
 *
 * - zrc-001: Valideren zaaktype op de Zaak-resource
 * - zrc-002: Garanderen uniciteit bronorganisatie en identificatie
 * - zrc-003: Valideren informatieobject op ZaakInformatieObject (in ZgwZrcZaakinformatieobjectRules)
 * - zrc-004: Zetten relatieinformatie op ZaakInformatieObject (in ZgwZrcZaakinformatieobjectRules)
 * - zrc-005: Synchroniseren relaties met informatieobjecten (cross-register, in ZgwService)
 * - zrc-006: Data filteren op basis van zaaktypes (in ZrcController)
 * - zrc-007: Afsluiten zaak (in ZrcController handleEindstatusEffect)
 * - zrc-008: Heropenen zaak (in ZrcController)
 * - zrc-009: Vertrouwelijkheidaanduiding van een zaak
 * - zrc-010: Valideren communicatiekanaal
 * - zrc-011: Valideren relevanteAndereZaken
 * - zrc-012: Gegevensgroepen (opschorting, verlenging)
 * - zrc-013: Valideren hoofdzaak
 * - zrc-014: Betalingsindicatie en laatsteBetaaldatum
 * - zrc-015: Valideren productenOfDiensten bij een Zaak
 * - zrc-016: Valideren statustype bij Zaak.zaaktype
 * - zrc-017: Valideren informatieobjecttype bij Zaak.zaaktype (in ZgwZrcZaakinformatieobjectRules)
 * - zrc-018: Valideren eigenschap bij Zaak.zaaktype
 * - zrc-019: Valideren roltype bij Zaak.zaaktype
 * - zrc-020: Valideren resultaattype bij Zaak.zaaktype
 * - zrc-021: Afleiden archiveringsparameters (in ZrcController)
 * - zrc-022: Zetten Zaak.archiefstatus
 * - zrc-023: Vernietigen van zaken (cascade delete, in ZrcController)
 *
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Zgw\ZrcCaseFieldRules;
use OCA\Dossiq\Service\Zgw\ZrcCaseTypeDefaults;
use OCA\Dossiq\Service\Zgw\ZrcSubResourceRules;
use Psr\Log\LoggerInterface;

/**
 * ZRC (Zaken API) business rule validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZgwZrcRulesService extends ZgwRulesBase {
	/**
	 * The field rules a zaak body passes on create, update and patch.
	 *
	 * @var ZrcCaseFieldRules
	 */
	private ZrcCaseFieldRules $caseFields;

	/**
	 * What a zaak takes from its zaaktype.
	 *
	 * @var ZrcCaseTypeDefaults
	 */
	private ZrcCaseTypeDefaults $typeDefaults;

	/**
	 * The create rules of statussen, resultaten, rollen and zaakeigenschappen.
	 *
	 * @var ZrcSubResourceRules
	 */
	private ZrcSubResourceRules $subResources;

	/**
	 * Constructor.
	 *
	 * Takes what every rules class takes and builds its three rule groups from
	 * the same three, so the dispatcher and the container still see one ZRC
	 * rules service.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 */
	public function __construct(LoggerInterface $logger, SettingsService $settingsService, FieldValidator $fieldValidator) {
		parent::__construct(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->caseFields = new ZrcCaseFieldRules(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->typeDefaults = new ZrcCaseTypeDefaults(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->subResources = new ZrcSubResourceRules(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
	}//end __construct()

	/**
	 * Set the request context here and on every rule group.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService.
	 * @param array|null $mappingConfig The mapping configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		parent::setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->caseFields->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->typeDefaults->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->subResources->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
	}//end setContext()

	/**
	 * Rules for creating a zaak (POST /zaken/v1/zaken).
	 *
	 * Implements:
	 * - zrc-001: Validate zaaktype URL exists and is published (concept=false).
	 * - zrc-002: Guarantee unique combination of identificatie + bronorganisatie.
	 *   Auto-generate identificatie if not provided.
	 * - zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not explicitly set.
	 * - zrc-022: Set default archiefstatus to 'nog_te_archiveren'.
	 *
	 * @param array $body The ZGW request body (Dutch field names)
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZakenCreate(array $body): array {
		// Zrc-001: Validate zaaktype URL.
		$caseTypeUrl = $body['caseType'] ?? '';
		$error = $this->validateCaseTypeReference(caseTypeUrl: $caseTypeUrl);
		if ($error !== null) {
			return $error;
		}

		// Zrc-002: Check unique identificatie + bronorganisatie.
		if (empty($body['identificatie']) === false) {
			$error = $this->checkFieldUniqueness(
				field1Value: $body['identificatie'],
				field1Search: 'identifier',
				field2Value: $body['bronorganisatie'] ?? '',
				field2Search: 'sourceOrganisation',
				errorField: 'identificatie'
			);
			if ($error !== null) {
				return $error;
			}
		}

		// Zrc-002: Auto-generate identificatie if not provided.
		if (empty($body['identificatie']) === true) {
			$body['identificatie'] = $this->generateIdentificatie(prefix: 'ZAAK');
		}

		// Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype — always override
		// template defaults to prevent leakage (incoming value used only as fallback).
		if (empty($caseTypeUrl) === false) {
			$body = $this->typeDefaults->deriveVertrouwelijkheidaanduiding(body: $body, caseTypeUrl: $caseTypeUrl);
		}

		// Zrc-022: Set default archiefstatus.
		if (empty($body['archiefstatus']) === true) {
			$body['archiefstatus'] = 'nog_te_archiveren';
		}

		// Intake channel: mark ZGW API as source channel.
		if (empty($body['intakeChannel']) === true) {
			$body['intakeChannel'] = 'zgw-api';
		}

		// Auto-assign handler from zaaktype defaultAssignee if no handler set.
		$body = $this->typeDefaults->applyDefaultAssignee(body: $body, caseTypeUrl: $caseTypeUrl);

		return $this->caseFields->validateCaseFields(result: $this->isValid(body: $body), existingObject: null);
	}//end rulesZakenCreate()

	/**
	 * Validate the zaaktype reference on a create body (zrc-001).
	 *
	 * Returns null when there is nothing to validate — no zaaktype was supplied, or OpenRegister
	 * is unavailable — which is exactly what the inline guard did.
	 *
	 * @param mixed $caseTypeUrl The `zaaktype` value from the request body
	 *
	 * @return array|null The validation error, or null when the reference is acceptable
	 */
	private function validateCaseTypeReference(mixed $caseTypeUrl): ?array {
		if (empty($caseTypeUrl) === true || $this->objectService === null) {
			return null;
		}

		return $this->validateTypeUrl(
			typeUrl: $caseTypeUrl,
			fieldName: 'caseType',
			schemaKey: 'case_type_schema'
		);
	}//end validateZaaktypeReference()



	/**
	 * Rules for updating a zaak (PUT /zaken/v1/zaken/{uuid}).
	 *
	 * Implements shared zaak field validations.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing zaak data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZakenUpdate(array $body, ?array $existingObject = null): array {
		// Zrc-002: Preserve immutable identificatie on PUT if not provided.
		// If the PUT body omits identificatie, carry it forward from the existing object
		// to prevent the stored identifier from being erased.
		if (isset($body['identificatie']) === false && $existingObject !== null) {
			$existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
			if ($existingId !== '') {
				$body['identificatie'] = $existingId;
			}
		}

		// Zrc-002: Preserve immutable bronorganisatie on PUT if not provided.
		if (isset($body['bronorganisatie']) === false && $existingObject !== null) {
			$existingOrg = $existingObject['sourceOrganisation'] ?? ($existingObject['bronorganisatie'] ?? '');
			if ($existingOrg !== '') {
				$body['bronorganisatie'] = $existingOrg;
			}
		}

		// Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype — always override.
		$caseTypeUrl = $body['caseType'] ?? '';
		if (empty($caseTypeUrl) === false) {
			$body = $this->typeDefaults->deriveVertrouwelijkheidaanduiding(body: $body, caseTypeUrl: $caseTypeUrl);
		}

		return $this->caseFields->validateCaseFields(
			result: $this->isValid(body: $body),
			existingObject: $existingObject
		);
	}//end rulesZakenUpdate()

	/**
	 * Rules for patching a zaak (PATCH /zaken/v1/zaken/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing zaak data
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZakenPatch(array $body, ?array $existingObject = null): array {
		// Zrc-009: Derive vertrouwelijkheidaanduiding from zaaktype if not set.
		// For PATCH, the zaaktype might not be in the body — check existing object.
		$caseTypeUrl = $body['caseType'] ?? '';
		if ($caseTypeUrl === '' && $existingObject !== null) {
			$caseType = $existingObject['caseType'] ?? '';
			if ($caseType !== '') {
				$caseTypeUrl = $caseType;
			}
		}

		// Ensure zaaktype is available in body for downstream validations
		// (zrc-010, zrc-015) that need the zaaktype URL from the existing object.
		if (($body['caseType'] ?? '') === '' && $caseTypeUrl !== '') {
			$body['caseType'] = $caseTypeUrl;
		}

		// Zrc-009: Always override from zaaktype to prevent template leakage.
		if (empty($caseTypeUrl) === false) {
			$body = $this->typeDefaults->deriveVertrouwelijkheidaanduiding(body: $body, caseTypeUrl: $caseTypeUrl);
		}

		return $this->caseFields->validateCaseFields(
			result: $this->isValid(body: $body),
			existingObject: $existingObject
		);
	}//end rulesZakenPatch()
	/**
	 * Rules for creating a status (POST /zaken/v1/statussen): zrc-016, answered by the sub-resource rules.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesStatussenCreate(array $body): array {
		return $this->subResources->rulesStatussenCreate(body: $body);
	}//end rulesStatussenCreate()

	/**
	 * Rules for creating a resultaat (POST /zaken/v1/resultaten): zrc-020, answered by the sub-resource rules.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesResultatenCreate(array $body): array {
		return $this->subResources->rulesResultatenCreate(body: $body);
	}//end rulesResultatenCreate()

	/**
	 * Rules for creating a rol (POST /zaken/v1/rollen): zrc-019, answered by the sub-resource rules.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesRollenCreate(array $body): array {
		return $this->subResources->rulesRollenCreate(body: $body);
	}//end rulesRollenCreate()

	/**
	 * Rules for creating a zaakeigenschap (POST /zaken/{zaakUuid}/zaakeigenschappen): zrc-018, answered by the sub-resource rules.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZaakeigenschappenCreate(array $body): array {
		return $this->subResources->rulesZaakeigenschappenCreate(body: $body);
	}//end rulesZaakeigenschappenCreate()
}//end class
