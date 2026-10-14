<?php

/**
 * Dossiq ZGW DRC (Documenten) Business Rules Service
 *
 * Implements business rules for the Documenten API as defined by VNG Realisatie.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * Business rules implemented:
 *
 * - drc-001: Valideren informatieobjecttype op EnkelvoudigInformatieObject
 *   The informatieobjecttype must exist and be published (concept=false).
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-002: Valideren ObjectInformatieObject (OIO) relatie
 *   An ObjectInformatieObject must reference a valid informatieobject and
 *   the combination of informatieobject+object must be unique.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-003: Valideren status bij enkelvoudiginformatieobject update
 *   When status is 'definitief', only bestandsomvang may be updated (lock check).
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-004: Valideren gebruiksrechten bij status definitief
 *   To set status to 'definitief', gebruiksrechten must exist.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-005: Vertrouwelijkheidaanduiding afleiden van informatieobjecttype
 *   If not explicitly set, derive vertrouwelijkheidaanduiding from the
 *   informatieobjecttype.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-006: Lock-mechanisme voor update/patch
 *   Documents with status 'definitief' require a valid lock ID for modification.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-007: Verwijderen van informatieobjecten met relaties
 *   Cannot destroy an informatieobject that has ObjectInformatieObject relations.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * - drc-008: Identificatie uniciteit
 *   Combination of identificatie + bronorganisatie must be unique.
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Zgw\DrcDocumentRelations;
use OCA\Dossiq\Service\Zgw\DrcObjectLinkRules;
use Psr\Log\LoggerInterface;

/**
 * DRC (Documenten API) business rule validation and enrichment.
 *
 * Holds the rules of the document itself and hands the rules of an
 * objectinformatieobject, and the reading of stored relations, to their own
 * classes.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZgwDrcRulesService extends ZgwRulesBase {
	/**
	 * The stored relations of a document.
	 *
	 * @var DrcDocumentRelations
	 */
	private DrcDocumentRelations $relations;

	/**
	 * The create rules of an objectinformatieobject.
	 *
	 * @var DrcObjectLinkRules
	 */
	private DrcObjectLinkRules $objectLinks;

	/**
	 * Constructor.
	 *
	 * Takes what every rules class takes and builds its two collaborators over
	 * its own lookups, so they read in the request context it is given, and the
	 * dispatcher and the container still see one DRC rules service.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 */
	public function __construct(LoggerInterface $logger, SettingsService $settingsService, FieldValidator $fieldValidator) {
		parent::__construct(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->relations = new DrcDocumentRelations(
			lookups: $this->lookups,
			results: $this->results,
			settingsService: $settingsService,
			fieldValidator: $fieldValidator,
			logger: $logger
		);
		$this->objectLinks = new DrcObjectLinkRules(
			lookups: $this->lookups,
			results: $this->results,
			settingsService: $settingsService,
			fieldValidator: $fieldValidator,
			logger: $logger,
			relations: $this->relations
		);
	}//end __construct()

	/**
	 * Rules for creating an ObjectInformatieObject.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @see DrcObjectLinkRules::rulesObjectinformatieobjectenCreate()
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesObjectinformatieobjectenCreate(array $body): array {
		// Drc-002: the informatieobject URL is checked first, before the object is looked at.
		$ioUrl = $body['informatieobject'] ?? '';
		if ($ioUrl !== '') {
			$error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->objectLinks->rulesObjectinformatieobjectenCreate(body: $body);
	}//end rulesObjectinformatieobjectenCreate()

	/**
	 * Rules for creating an EnkelvoudigInformatieObject.
	 *
	 * Implements drc-001, drc-005, drc-008.
	 *
	 * @param array $body The ZGW request body (Dutch field names)
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesEnkelvoudiginformatieobjectenCreate(array $body): array {
		// Drc-001: Validate informatieobjecttype is published (not concept).
		$iotUrl = $body['informatieobjecttype'] ?? '';
		$error  = $this->checkInformatieobjecttype(iotUrl: $iotUrl);
		if ($error !== null) {
			return $error;
		}

		$body = $this->applyCreateDefaults(body: $body, iotUrl: $iotUrl);

		// Drc-006b: If indicatieGebruiksrecht is explicitly true, gebruiksrechten must exist.
		// On create the document does not yet exist, so indicatieGebruiksrecht=true is ALWAYS an error.
		if ($body['indicatieGebruiksrecht'] === true && $this->objectService !== null) {
			return $this->validateIndicationGebruiksrechtTrue();
		}

		// Drc-008: Check unique identificatie + bronorganisatie.
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

		// Drc-008: Auto-generate identificatie if not provided.
		if (empty($body['identificatie']) === true) {
			$body['identificatie'] = $this->generateIdentificatie(prefix: 'DOCUMENT');
		}

		return $this->isValid(body: $body);
	}//end rulesEnkelvoudiginformatieobjectenCreate()

	/**
	 * Drc-001: the informatieobjecttype URL points at a published type.
	 *
	 * @param mixed $iotUrl The informatieobjecttype from the body
	 *
	 * @return array|null The refusal, or null when acceptable or nothing can be looked up
	 */
	private function checkInformatieobjecttype(mixed $iotUrl): ?array {
		if ($iotUrl === '' || $this->objectService === null) {
			return null;
		}

		return $this->validateTypeUrl(
			typeUrl: $iotUrl,
			fieldName: 'informatieobjecttype',
			schemaKey: 'document_type_schema'
		);
	}//end checkInformatieobjecttype()

	/**
	 * The defaults a new document gets before its rules run (drc-005, drc-006a).
	 *
	 * The confidentiality comes from the informatieobjecttype when the body has none, and an
	 * absent or false indicatieGebruiksrecht becomes null.
	 *
	 * @param array $body The request body
	 * @param mixed $iotUrl The informatieobjecttype from the body
	 *
	 * @return array The body with its defaults
	 */
	private function applyCreateDefaults(array $body, mixed $iotUrl): array {
		if (empty($body['vertrouwelijkheidaanduiding']) === true && $iotUrl !== '') {
			$body = $this->deriveVertrouwelijkheidaanduiding(body: $body, iotUrl: $iotUrl);
		}

		if (array_key_exists('indicatieGebruiksrecht', $body) === false
			|| $body['indicatieGebruiksrecht'] === false
		) {
			$body['indicatieGebruiksrecht'] = null;
		}

		return $body;
	}//end applyCreateDefaults()

	/**
	 * Rules for updating an EnkelvoudigInformatieObject (PUT).
	 *
	 * Implements drc-003, drc-006.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing EIO data
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesEnkelvoudiginformatieobjectenUpdate(
		array $body,
		?array $existingObject = null,
	): array {
		// Drc-006: Check lock requirement.
		$lockError = $this->validateLock(body: $body, existingObject: $existingObject);
		if ($lockError !== null) {
			return $lockError;
		}

		return $this->isValid(body: $body);
	}//end rulesEnkelvoudiginformatieobjectenUpdate()

	/**
	 * Rules for patching an EnkelvoudigInformatieObject (PATCH).
	 *
	 * Implements drc-003, drc-006.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing EIO data
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesEnkelvoudiginformatieobjectenPatch(
		array $body,
		?array $existingObject = null,
	): array {
		// Drc-006: Check lock requirement.
		$lockError = $this->validateLock(body: $body, existingObject: $existingObject);
		if ($lockError !== null) {
			return $lockError;
		}

		return $this->isValid(body: $body);
	}//end rulesEnkelvoudiginformatieobjectenPatch()

	/**
	 * Rules for destroying an EnkelvoudigInformatieObject (DELETE).
	 *
	 * Implements drc-007.
	 *
	 * @param array $body The ZGW request body (usually empty)
	 * @param array|null $existingObject The existing EIO data
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesEnkelvoudiginformatieobjectenDestroy(
		array $body,
		?array $existingObject = null,
	): array {
		if ($existingObject === null || $this->objectService === null) {
			return $this->isValid(body: $body);
		}

		$existingId = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
		if ($existingId === null) {
			return $this->isValid(body: $body);
		}

		// Drc-007: Check for ObjectInformatieObject relations.
		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$oioSchema = $this->settingsService->getConfigValue(key: 'document_link_schema');

		if ($register !== '' && $oioSchema !== '') {
			// OIO stores the full informatieobject URL in the 'document' field,
			// so search with both UUID and partial match to find any OIO relations.
			$relatedIds = $this->relations->findOioRelationsForDocument(
				register: $register,
				schema: $oioSchema,
				docUuid: $existingId
			);
			if (empty($relatedIds) === false) {
				return $this->error(
					status: 400,
					detail: 'Het informatieobject kan niet verwijderd worden omdat er nog relaties aan gekoppeld zijn.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'nonFieldErrors',
							code: 'pending-relations',
							reason: 'Het informatieobject heeft nog ObjectInformatieObject relaties.'
						),
					]
				);
			}
		}//end if

		return $this->isValid(body: $body);
	}//end rulesEnkelvoudiginformatieobjectenDestroy()

	/**
	 * Validate that indicatieGebruiksrecht=true requires existing gebruiksrechten (drc-006b).
	 *
	 * @return array Validation error
	 */
	private function validateIndicationGebruiksrechtTrue(): array {
		// On create, the document does not yet exist so there can be no gebruiksrechten.
		return $this->error(
			status: 400,
			detail: 'indicatieGebruiksrecht kan niet true zijn zonder dat er gebruiksrechten bestaan.',
			invalidParams: [
				$this->fieldError(
					fieldName: 'indicatieGebruiksrecht',
					code: 'missing-gebruiksrechten',
					reason: 'Er zijn geen gebruiksrechten voor dit informatieobject.'
				),
			]
		);
	}//end validateIndicatieGebruiksrechtTrue()

	/**
	 * Validate lock requirement for document modifications (drc-006).
	 *
	 * @param array $body The request body
	 * @param array|null $existingObject The existing EIO data
	 *
	 * @return array|null Validation error, or null if valid
	 */
	private function validateLock(array $body, ?array $existingObject): ?array {
		if ($existingObject === null) {
			return null;
		}

		// Check if the document is locked.
		$existingLock = $existingObject['locked'] ?? ($existingObject['lock'] ?? '');
		if ($existingLock === '' || $existingLock === false) {
			return null;
		}

		// Drc-006: Require valid lock ID.
		$requestLock = $body['lock'] ?? '';
		if ($requestLock === '') {
			return $this->error(
				status: 400,
				detail: 'Het document is vergrendeld. Geef een geldig lock ID mee.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'lock',
						code: 'required',
						reason: 'Het document is vergrendeld.'
					),
				]
			);
		}

		if ($requestLock !== $existingLock) {
			return $this->error(
				status: 400,
				detail: 'Het opgegeven lock ID is niet geldig.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'lock',
						code: 'incorrect-lock-id',
						reason: 'Het opgegeven lock ID komt niet overeen.'
					),
				]
			);
		}

		return null;
	}//end validateLock()

	/**
	 * Derive vertrouwelijkheidaanduiding from informatieobjecttype (drc-005).
	 *
	 * @param array $body The request body
	 * @param string $iotUrl The informatieobjecttype URL
	 *
	 * @return array The body with derived vertrouwelijkheidaanduiding
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 */
	private function deriveVertrouwelijkheidaanduiding(array $body, string $iotUrl): array {
		$uuid = $this->extractUuid(url: $iotUrl);
		if ($uuid === null) {
			return $body;
		}

		$iotData = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'document_type_schema');
		if ($iotData === null) {
			return $body;
		}

		$val = $iotData['confidentiality'] ?? $iotData['confidentialityDesignation'] ?? $iotData['vertrouwelijkheidaanduiding'] ?? '';
		if ($val !== '') {
			$body['vertrouwelijkheidaanduiding'] = $val;
		}

		return $body;
	}//end deriveVertrouwelijkheidaanduiding()
}//end class
