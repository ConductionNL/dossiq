<?php

/**
 * Dossiq ZGW ZTC (Catalogi) Business Rules Service
 *
 * Implements business rules for the Catalogi API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * Business rules implemented:
 *
 * - ztc-001: Valideren selectielijstProcestype op zaaktype
 * - ztc-002: Valideren selectielijstklasse + resultaattypeomschrijving (enrichment)
 *            — in ZgwZtcResultaattypeRules
 * - ztc-003: Valideren afleidingswijze vs selectielijstklasse.procestermijn
 *            — in BrondatumArchiefValidator
 * - ztc-004: Valideren datumkenmerk vereist/verboden op basis van afleidingswijze
 *            — in BrondatumArchiefValidator
 * - ztc-005: Valideren einddatumBekend verboden voor afgehandeld/termijn
 *            — in BrondatumArchiefValidator
 * - ztc-006: Valideren objecttype vereist/verboden op basis van afleidingswijze
 *            — in BrondatumArchiefValidator
 * - ztc-007: Valideren registratie vereist voor ander_datumkenmerk
 *            — in BrondatumArchiefValidator
 * - ztc-008: Valideren procestermijn vereist voor termijn afleidingswijze
 *            — in BrondatumArchiefValidator
 * - ztc-009: Concept/gepubliceerd bescherming: types met concept=false mogen niet
 *            gewijzigd of verwijderd worden (behalve eindeGeldigheid via PATCH)
 * - ztc-010: Sub-resources van gepubliceerde zaaktypen mogen niet gewijzigd worden
 *            (behalve CREATE voor eigenschappen/roltypen/statustypen/ZIOTs)
 * - ztc-011: History model — beginGeldigheid + eindeGeldigheid + concept consistency
 * - ztc-012: Publish validation — all relations must be published before publish
 * - ztc-013: Cross-catalogus — zaaktype must belong to the specified catalogus
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Zgw\ZtcConceptRules;
use OCA\Dossiq\Service\Zgw\ZtcPublishAndDeleteChecks;
use OCA\Dossiq\Service\Zgw\ZtcTypeReferences;
use Psr\Log\LoggerInterface;

/**
 * ZTC (Catalogi API) business rule validation and enrichment.
 *
 * Holds the create rules of a zaaktype, a besluittype and a
 * zaaktype-informatieobjecttype, and hands the concept rules, the reference
 * resolution and the publish and delete checks to their own classes.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwZtcRulesService extends ZgwRulesBase {
	/**
	 * ZTC resources that are subject to concept/published protection.
	 *
	 * @var array<string>
	 */
	public const CONCEPT_RESOURCES = ZtcConceptRules::CONCEPT_RESOURCES;

	/**
	 * ZTC sub-resources tied to a parent zaaktype (ztc-010).
	 *
	 * @var array<string>
	 */
	public const ZAAKTYPE_SUB_RESOURCES = ZtcConceptRules::ZAAKTYPE_SUB_RESOURCES;

	/**
	 * Concept defaults and the protection of published types.
	 *
	 * @var ZtcConceptRules
	 */
	private ZtcConceptRules $concept;

	/**
	 * Reference lists of a type, resolved to uuids.
	 *
	 * @var ZtcTypeReferences
	 */
	private ZtcTypeReferences $references;

	/**
	 * Publish and delete checks of a case type.
	 *
	 * @var ZtcPublishAndDeleteChecks
	 */
	private ZtcPublishAndDeleteChecks $lifecycle;

	/**
	 * Constructor.
	 *
	 * Takes what every rules class takes and builds its three collaborators
	 * from the same three, so the dispatcher and the container still see one
	 * ZTC rules service.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 */
	public function __construct(LoggerInterface $logger, SettingsService $settingsService, FieldValidator $fieldValidator) {
		parent::__construct(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->concept = new ZtcConceptRules(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->references = new ZtcTypeReferences(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->lifecycle = new ZtcPublishAndDeleteChecks(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
	}//end __construct()

	/**
	 * Set the request context here and on the two collaborators that look things up.
	 *
	 * The concept rules read nothing from the store, so they get no context.
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
		$this->references->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->lifecycle->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
	}//end setContext()

	/**
	 * Check concept/published protection for ZTC resources (ztc-009, ztc-010).
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $action The action (create/update/patch/destroy)
	 * @param array $body The request body
	 * @param array|null $existingObject The existing object data
	 * @param bool|null $parentCaseTypeDraft Whether the parent zaaktype isDraft
	 *
	 * @return array|null Validation error result, or null if check passes
	 *
	 * @see ZtcConceptRules::checkConceptProtection()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function checkConceptProtection(
		string $resource,
		string $action,
		array $body,
		?array $existingObject,
		?bool $parentCaseTypeDraft,
	): ?array {
		return $this->concept->checkConceptProtection(
			resource: $resource,
			action: $action,
			body: $body,
			existingObject: $existingObject,
			parentCaseTypeDraft: $parentCaseTypeDraft
		);
	}//end checkConceptProtection()

	/**
	 * Default concept=true for new ZTC concept resources.
	 *
	 * @param array $body The request body
	 * @param string $resource The resource name
	 *
	 * @return array The body with concept defaulted
	 *
	 * @see ZtcConceptRules::defaultConcept()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function defaultConcept(array $body, string $resource): array {
		return $this->concept->defaultConcept(body: $body, resource: $resource);
	}//end defaultConcept()

	/**
	 * Preserve existing concept value on update/patch.
	 *
	 * @param array $body The request body
	 * @param string $resource The resource name
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The body with concept preserved
	 *
	 * @see ZtcConceptRules::preserveConcept()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function preserveConcept(array $body, string $resource, ?array $existingObject): array {
		return $this->concept->preserveConcept(body: $body, resource: $resource, existingObject: $existingObject);
	}//end preserveConcept()

	/**
	 * Validate a caseType is publishable (isDraft true → false).
	 *
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<int, string> Error strings (empty = valid).
	 *
	 * @see ZtcPublishAndDeleteChecks::validatePublish()
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function validatePublish(string $register, string $caseTypeId): array {
		return $this->lifecycle->validatePublish(register: $register, caseTypeId: $caseTypeId);
	}//end validatePublish()

	/**
	 * Validate a caseType can be safely deleted (no active cases).
	 *
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<string, mixed>
	 *
	 * @see ZtcPublishAndDeleteChecks::validateDeletion()
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function validateDeletion(string $register, string $caseTypeId): array {
		return $this->lifecycle->validateDeletion(register: $register, caseTypeId: $caseTypeId);
	}//end validateDeletion()


	/**
	 * Rules for creating a zaaktype (POST /catalogi/v1/zaaktypen).
	 *
	 * Implements:
	 * - ztc-001: Validate selectielijstProcestype URL points to a valid procestype resource.
	 *
	 * Also resolves reference arrays (informatieobjecttypen, besluittypen,
	 * deelzaaktypen, gerelateerdeZaaktypen) from omschrijving/identificatie to UUIDs.
	 *
	 * @param array $body The ZGW request body (Dutch field names)
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesZaaktypenCreate(array $body): array {
		// Ztc-001: Validate selectielijstProcestype URL.
		$error = $this->checkSelectielijstProcestype(procesTypeUrl: $body['selectielijstProcestype'] ?? '');
		if ($error !== null) {
			return $error;
		}

		// Resolve reference arrays by omschrijving/identificatie to UUIDs.
		$body = $this->references->resolveTypeReferences(
			body: $body,
			field: 'informatieobjecttypen',
			schemaKey: 'document_type_schema',
			lookupField: 'name'
		);
		$body = $this->references->resolveTypeReferences(
			body: $body,
			field: 'besluittypen',
			schemaKey: 'decision_type_schema',
			lookupField: 'name'
		);
		$body = $this->references->resolveTypeReferences(
			body: $body,
			field: 'deelzaaktypen',
			schemaKey: 'case_type_schema',
			lookupField: 'identifier'
		);
		$body = $this->references->resolveGerelateerdeZaaktypen(body: $body);

		// Store resolved array fields via _directFields (bypasses Twig mapping).
		// RelatedCaseTypes is JSON-encoded since it is a string field in the schema.
		$body = $this->references->withDirectFields(
			body: $body,
			map: ['deelzaaktypen' => 'subCaseTypes', 'besluittypen' => 'decisionTypes', 'gerelateerdeZaaktypen' => 'relatedCaseTypes'],
			jsonFields: ['relatedCaseTypes']
		);

		return $this->isValid(body: $body);
	}//end rulesZaaktypenCreate()

	/**
	 * Ztc-001: a selectielijstProcestype must resolve to a procestype resource.
	 *
	 * @param mixed $procesTypeUrl The selectielijstProcestype from the body
	 *
	 * @return array|null The refusal, or null when absent or valid
	 */
	private function checkSelectielijstProcestype(mixed $procesTypeUrl): ?array {
		if (empty($procesTypeUrl) === true) {
			return null;
		}

		$procesTypeData = $this->fetchExternalUrl(url: $procesTypeUrl);
		if ($procesTypeData !== null && isset($procesTypeData['nummer']) === true) {
			return null;
		}

		$detail = 'De selectielijstProcestype URL is ongeldig of wijst niet naar een procestype resource.';
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [$this->fieldError(fieldName: 'selectielijstProcestype', code: 'invalid-resource', reason: $detail)]
		);
	}//end checkSelectielijstProcestype()

	/**
	 * Rules for creating a besluittype (POST /catalogi/v1/besluittypen).
	 *
	 * Resolves reference arrays (informatieobjecttypen, zaaktypen) from
	 * omschrijving/identificatie to UUIDs.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesBesluittypenCreate(array $body): array {
		// Resolve reference arrays by omschrijving/identificatie to UUIDs.
		$body = $this->references->resolveTypeReferences(
			body: $body,
			field: 'informatieobjecttypen',
			schemaKey: 'document_type_schema',
			lookupField: 'name'
		);
		$body = $this->references->resolveTypeReferences(
			body: $body,
			field: 'zaaktypen',
			schemaKey: 'case_type_schema',
			lookupField: 'identifier'
		);

		// Store resolved arrays as _directFields (bypass Twig mapping for array fields).
		$body = $this->references->withDirectFields(
			body: $body,
			map: ['informatieobjecttypen' => 'documentTypes', 'zaaktypen' => 'caseTypes']
		);

		return $this->isValid(body: $body);
	}//end rulesBesluittypenCreate()

	/**
	 * Rules for creating a zaaktype-informatieobjecttype (ZIOT).
	 *
	 * Resolves the informatieobjecttype field from omschrijving to UUID when
	 * the value is not a URL or UUID.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesZaaktypeinformatieobjecttypenCreate(array $body): array {
		// Resolve informatieobjecttype: omschrijving → UUID, or bare UUID → verify.
		$iotRef = $body['informatieobjecttype'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: 'document_type_schema');
		$register = $this->mappingConfig['sourceRegister'] ?? '';
		if ($iotRef === '' || $this->objectService === null || empty($register) === true || empty($schema) === true
			|| $this->needsNameLookup(iotRef: $iotRef) === false
		) {
			return $this->isValid(body: $body);
		}

		// Not a URL, or bare UUID that didn't resolve — resolve by name.
		$found = $this->findObjectByField(register: $register, schema: $schema, field: 'name', value: $iotRef);
		if ($found !== null) {
			$body['informatieobjecttype'] = $found;
		}

		return $this->isValid(body: $body);
	}//end rulesZaaktypeinformatieobjecttypenCreate()

	/**
	 * Whether an informatieobjecttype reference must be resolved by name.
	 *
	 * A URL is left to the reverse mapping, which extracts its uuid. A bare uuid is kept when it
	 * names an existing type. Anything else, including a bare uuid that names nothing, is a name.
	 *
	 * @param string $iotRef The informatieobjecttype reference
	 *
	 * @return bool True when the reference is resolved by name
	 */
	private function needsNameLookup(string $iotRef): bool {
		if (str_starts_with($iotRef, 'http://') === true || str_starts_with($iotRef, 'https://') === true) {
			return false;
		}

		$uuid = $this->extractUuid(url: $iotRef);
		if ($uuid === null) {
			return true;
		}

		return $this->findBySchemaKey(uuid: $uuid, schemaKey: 'document_type_schema') === null;
	}//end needsNameLookup()

}//end class
