<?php

/**
 * Dossiq ZGW ZTC resultaattype rules.
 *
 * The Catalogi API rules for the resultaattypen resource, split out of
 * ZgwZtcRulesService. Resultaattypen are the one ZTC resource whose creation
 * reaches outside the instance entirely: both `selectielijstklasse` and
 * `resultaattypeomschrijving` are external VNG URLs that must be fetched, the
 * body is then enriched from what they return, and the fetched
 * selectielijstklasse constrains both the zaaktype's procestype and the nested
 * brondatumArchiefprocedure. That whole ztc-002…ztc-008 chain lives here; the
 * nested cross-field table itself lives in {@see BrondatumArchiefValidator}.
 *
 * Business rules implemented:
 *
 * - ztc-002: Valideren selectielijstklasse + resultaattypeomschrijving (enrichment)
 * - ztc-003 to ztc-008: brondatumArchiefprocedure (delegated)
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwRulesBase;
use Psr\Log\LoggerInterface;

/**
 * ZTC resultaattypen validation and enrichment.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwZtcResultaattypeRules extends ZgwRulesBase {
	/**
	 * The archive field each selectielijstklasse value is derived into (ztc-002f/g), in that order.
	 *
	 * @var array<string, string>
	 */
	private const ARCHIVE_FIELD_SOURCES = [
		'archiefnominatie' => 'waardering',
		'archiefactietermijn' => 'bewaartermijn',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param SettingsService $settingsService The settings service
	 * @param FieldValidator $fieldValidator The stateless field-format validator
	 * @param BrondatumArchiefValidator $brondatumValidator The brondatumArchiefprocedure cross-field rules
	 *
	 * @return void
	 */
	public function __construct(
		LoggerInterface $logger,
		SettingsService $settingsService,
		FieldValidator $fieldValidator,
		private readonly BrondatumArchiefValidator $brondatumValidator,
	) {
		parent::__construct(
			logger: $logger,
			settingsService: $settingsService,
			fieldValidator: $fieldValidator
		);
	}//end __construct()

	/**
	 * Set the per-request services on this service and its collaborators.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array|null $mappingConfig The mapping config
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		parent::setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->brondatumValidator->setContext($objectService, $mappingConfig);
	}//end setContext()

	/**
	 * Rules for creating a resultaattype (POST /catalogi/v1/resultaattypen).
	 *
	 * Implements:
	 * - ztc-002: Validate and fetch selectielijstklasse + resultaattypeomschrijving.
	 *   Enrich with omschrijvingGeneriek, archiefnominatie, archiefactietermijn.
	 *
	 * - ztc-003: Validate afleidingswijze vs selectielijstklasse.procestermijn.
	 *   procestermijn=nihil only afgehandeld; procestermijn=bestaansduur_procesobject only termijn.
	 * - ztc-004: datumkenmerk required for eigenschap/zaakobject/ander_datumkenmerk, forbidden otherwise.
	 * - ztc-005: einddatumBekend must be false for afgehandeld/termijn.
	 * - ztc-006: objecttype required for zaakobject/ander_datumkenmerk, forbidden otherwise.
	 * - ztc-007: registratie required only for ander_datumkenmerk.
	 * - ztc-008: procestermijn required only for termijn afleidingswijze.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function rulesResultaattypenCreate(array $body): array {
		// Ztc-002: Validate and fetch external URLs for enrichment.
		$references = $this->fetchResultaattypeReferences(body: $body);
		$selectielijstData = $references['selectielijstData'];
		$rtoData = $references['rtoData'];
		$errors = $references['errors'];

		if (empty($errors) === false) {
			return $this->error(status: 400, detail: $errors[0]['reason'], invalidParams: $errors);
		}

		// Ztc-002b/f/g: Enrich body with derived fields from external data.
		$body = self::withGenericDescription(body: $body, rtoData: $rtoData);
		$body = self::withArchiveFields(body: $body, selectielijstData: $selectielijstData);

		// Ztc-002e: Validate selectielijstklasse procesType matches zaaktype selectielijstProcestype.
		if ($selectielijstData !== null) {
			$procestypeError = $this->validateProcestypeMatch(body: $body, selectielijstData: $selectielijstData);
			if ($procestypeError !== null) {
				return $procestypeError;
			}
		}

		// Validate brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
		$archive = $body['brondatumArchiefprocedure'] ?? null;
		if ($archive !== null) {
			$errors = $this->brondatumValidator->validate(archive: $archive, selectielijstData: $selectielijstData);
		}

		if (empty($errors) === false) {
			return $this->error(status: 400, detail: $errors[0]['reason'], invalidParams: $errors);
		}

		return $this->isValid(body: $body);
	}//end rulesResultaattypenCreate()

	/**
	 * Fetch the two external VNG references a resultaattype is built from (ztc-002).
	 *
	 * Both `selectielijstklasse` and `resultaattypeomschrijving` are external URLs.
	 * Each is optional, each is fetched independently, and a fetch failure is a
	 * field error rather than an abort — so both are attempted before the caller
	 * decides. Errors are returned in field order (selectielijstklasse first),
	 * because the caller reports `$errors[0]` as the problem detail.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array{selectielijstData: array|null, rtoData: array|null, errors: array} The fetched data and errors
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function fetchResultaattypeReferences(array $body): array {
		$errors = [];

		$selectielijstData = $this->fetchReference(url: ($body['selectielijstklasse'] ?? ''));
		if ($selectielijstData === false) {
			$errors[] = $this->unreachableReference(fieldName: 'selectielijstklasse');
		}

		$rtoData = $this->fetchReference(url: self::firstOf(value: ($body['resultaattypeomschrijving'] ?? '')));
		if ($rtoData === false) {
			$errors[] = $this->unreachableReference(fieldName: 'resultaattypeomschrijving');
		}

		return [
			'selectielijstData' => self::dataOrNull(fetched: $selectielijstData),
			'rtoData' => self::dataOrNull(fetched: $rtoData),
			'errors' => $errors,
		];
	}//end fetchResultaattypeReferences()

	/**
	 * Fetch one optional external reference.
	 *
	 * @param mixed $url The reference, or empty when there is none.
	 *
	 * @return array|false|null The data, false when it could not be read, null when there is none.
	 */
	private function fetchReference(mixed $url): array|false|null {
		if (empty($url) === true) {
			return null;
		}

		return ($this->fetchExternalUrl(url: $url) ?? false);
	}//end fetchReference()

	/**
	 * The first reference of a list, or the value itself.
	 *
	 * @param mixed $value The reference or a list of them.
	 *
	 * @return mixed The reference.
	 */
	private static function firstOf(mixed $value): mixed {
		if (is_array($value) === true) {
			return ($value[0] ?? '');
		}

		return $value;
	}//end firstOf()

	/**
	 * Fetched data, or null for a reference that could not be read or was not given.
	 *
	 * @param array|false|null $fetched What fetchReference answered.
	 *
	 * @return array|null The data.
	 */
	private static function dataOrNull(array|false|null $fetched): ?array {
		if ($fetched === false) {
			return null;
		}

		return $fetched;
	}//end dataOrNull()

	/**
	 * The field error for an external reference that is invalid or cannot be reached.
	 *
	 * @param string $fieldName The field holding the reference.
	 *
	 * @return array The field error.
	 */
	private function unreachableReference(string $fieldName): array {
		return $this->fieldError(
			fieldName: $fieldName,
			code: 'invalid',
			reason: 'De ' . $fieldName . ' URL is ongeldig of niet bereikbaar.'
		);
	}//end unreachableReference()

	/**
	 * Ztc-002b: derive omschrijvingGeneriek from resultaattypeomschrijving.omschrijving,
	 * when the body leaves it empty.
	 *
	 * @param array      $body    The request body
	 * @param array|null $rtoData The fetched resultaattypeomschrijving data
	 *
	 * @return array The enriched body
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private static function withGenericDescription(array $body, ?array $rtoData): array {
		if ($rtoData !== null && empty($body['omschrijvingGeneriek']) === true) {
			$body['omschrijvingGeneriek'] = $rtoData['omschrijving'] ?? '';
		}

		return $body;
	}//end withGenericDescription()

	/**
	 * Ztc-002f/g: derive archiefnominatie from selectielijstklasse.waardering and
	 * archiefactietermijn from selectielijstklasse.bewaartermijn, each when the
	 * body leaves it empty and the selectielijstklasse carries it.
	 *
	 * @param array      $body              The request body
	 * @param array|null $selectielijstData The fetched selectielijstklasse data
	 *
	 * @return array The enriched body
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private static function withArchiveFields(array $body, ?array $selectielijstData): array {
		if ($selectielijstData === null) {
			return $body;
		}

		foreach (self::ARCHIVE_FIELD_SOURCES as $field => $source) {
			if (empty($body[$field]) === true && ($selectielijstData[$source] ?? null) !== null) {
				$body[$field] = $selectielijstData[$source];
			}
		}

		return $body;
	}//end withArchiveFields()

	/**
	 * Validate selectielijstklasse procesType matches zaaktype selectielijstProcestype (ztc-002e).
	 *
	 * @param array $body The request body (with zaaktype URL)
	 * @param array $selectielijstData The fetched selectielijstklasse data
	 *
	 * @return array|null Validation error result, or null if valid
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function validateProcestypeMatch(array $body, array $selectielijstData): ?array {
		$caseTypeProcestype = $this->caseTypeProcestypeOf(body: $body);
		$selectieProcestype = $selectielijstData['procesType'] ?? '';

		if (empty($caseTypeProcestype) === true || empty($selectieProcestype) === true) {
			return null;
		}

		if ($caseTypeProcestype !== $selectieProcestype) {
			return $this->procestypeMismatch();
		}

		return null;
	}//end validateProcestypeMatch()

	/**
	 * The selectielijst procestype of the zaaktype the body names, or '' when it cannot be read.
	 *
	 * @param array $body The request body (with zaaktype URL)
	 *
	 * @return mixed The procestype, or ''.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function caseTypeProcestypeOf(array $body): mixed {
		$caseTypeUrl = $body['caseType'] ?? '';
		if (empty($caseTypeUrl) === true || $this->objectService === null) {
			return '';
		}

		$zaaktypeUuid = $this->extractUuid(url: $caseTypeUrl);
		if ($zaaktypeUuid === null) {
			return '';
		}

		$ztData = $this->findBySchemaKey(uuid: $zaaktypeUuid, schemaKey: 'case_type_schema');
		if ($ztData === null) {
			return '';
		}

		return $ztData['selectionListProcessType'] ?? '';
	}//end caseTypeProcestypeOf()

	/**
	 * The ztc-002e error for a selectielijstklasse whose procestype is not the zaaktype's.
	 *
	 * @return array The validation error result.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function procestypeMismatch(): array {
		$detail = 'Het procestype van de selectielijstklasse komt niet overeen met het procestype van het zaaktype.';
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(fieldName: 'nonFieldErrors', code: 'procestype-mismatch', reason: $detail),
			]
		);
	}//end procestypeMismatch()
}//end class
