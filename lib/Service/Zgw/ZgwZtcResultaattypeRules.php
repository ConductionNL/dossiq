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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
		$body = $this->enrichResultaattype(body: $body, selectielijstData: $selectielijstData, rtoData: $rtoData);

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

		$selectieUrl = $body['selectielijstklasse'] ?? '';
		$selectielijstData = null;
		if (empty($selectieUrl) === false) {
			$selectielijstData = $this->fetchExternalUrl(url: $selectieUrl);
			if ($selectielijstData === null) {
				$errors[] = $this->fieldError(
					fieldName: 'selectielijstklasse',
					code: 'invalid',
					reason: 'De selectielijstklasse URL is ongeldig of niet bereikbaar.'
				);
			}
		}

		$rtoUrl = $body['resultaattypeomschrijving'] ?? '';
		if (is_array($rtoUrl) === true) {
			$rtoUrl = $rtoUrl[0] ?? '';
		}

		$rtoData = null;
		if (empty($rtoUrl) === false) {
			$rtoData = $this->fetchExternalUrl(url: $rtoUrl);
			if ($rtoData === null) {
				$errors[] = $this->fieldError(
					fieldName: 'resultaattypeomschrijving',
					code: 'invalid',
					reason: 'De resultaattypeomschrijving URL is ongeldig of niet bereikbaar.'
				);
			}
		}

		return [
			'selectielijstData' => $selectielijstData,
			'rtoData' => $rtoData,
			'errors' => $errors,
		];
	}//end fetchResultaattypeReferences()

	/**
	 * Enrich a resultaattype body with derived fields from external APIs (ztc-002b/f/g).
	 *
	 * - ztc-002b: Derive omschrijvingGeneriek from resultaattypeomschrijving.omschrijving
	 * - ztc-002f: Derive archiefnominatie from selectielijstklasse.waardering
	 * - ztc-002g: Derive archiefactietermijn from selectielijstklasse.bewaartermijn
	 *
	 * @param array $body The request body
	 * @param array|null $selectielijstData The fetched selectielijstklasse data
	 * @param array|null $rtoData The fetched resultaattypeomschrijving data
	 *
	 * @return array The enriched body
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function enrichResultaattype(array $body, ?array $selectielijstData, ?array $rtoData): array {
		if ($rtoData !== null && empty($body['omschrijvingGeneriek']) === true) {
			$body['omschrijvingGeneriek'] = $rtoData['omschrijving'] ?? '';
		}

		if ($selectielijstData !== null && empty($body['archiefnominatie']) === true) {
			$waardering = $selectielijstData['waardering'] ?? null;
			if ($waardering !== null) {
				$body['archiefnominatie'] = $waardering;
			}
		}

		if ($selectielijstData !== null && empty($body['archiefactietermijn']) === true) {
			$retentionPeriod = $selectielijstData['bewaartermijn'] ?? null;
			if ($retentionPeriod !== null) {
				$body['archiefactietermijn'] = $retentionPeriod;
			}
		}

		return $body;
	}//end enrichResultaattype()

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
		$caseTypeUrl = $body['caseType'] ?? '';
		if (empty($caseTypeUrl) === true || $this->objectService === null) {
			return null;
		}

		$zaaktypeUuid = $this->extractUuid(url: $caseTypeUrl);
		if ($zaaktypeUuid === null) {
			return null;
		}

		$ztData = $this->findBySchemaKey(uuid: $zaaktypeUuid, schemaKey: 'case_type_schema');
		if ($ztData === null) {
			return null;
		}

		$caseTypeProcestype = $ztData['selectionListProcessType'] ?? '';
		$selectieProcestype = $selectielijstData['procesType'] ?? '';

		if (empty($caseTypeProcestype) === true || empty($selectieProcestype) === true) {
			return null;
		}

		if ($caseTypeProcestype !== $selectieProcestype) {
			$detail = 'Het procestype van de selectielijstklasse komt niet overeen met het procestype van het zaaktype.';
			return $this->error(
				status: 400,
				detail: $detail,
				invalidParams: [
					$this->fieldError(fieldName: 'nonFieldErrors', code: 'procestype-mismatch', reason: $detail),
				]
			);
		}

		return null;
	}//end validateProcestypeMatch()
}//end class
