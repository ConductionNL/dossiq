<?php

/**
 * DRC rules for linking a document to a case or a besluit
 *
 * An objectinformatieobject names a valid document and a valid object of the
 * stated type (drc-002), is not there yet (drc-003) and has its counterpart in
 * the Zaken or Besluiten API (drc-004).
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
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
 * Create rules of an objectinformatieobject.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class DrcObjectLinkRules extends ZgwRulesBase {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 * @param DrcDocumentRelations $relations The stored relations of a document.
	 */
	public function __construct(
		LoggerInterface $logger,
		SettingsService $settingsService,
		FieldValidator $fieldValidator,
		private readonly DrcDocumentRelations $relations,
	) {
		parent::__construct(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
	}//end __construct()

	/**
	 * Set the request context here and on the relations these rules read.
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
		$this->relations->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
	}//end setContext()

	/**
	 * Rules for creating an ObjectInformatieObject.
	 *
	 * Implements drc-002.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function rulesObjectinformatieobjectenCreate(array $body): array {
		$ioUrl      = $body['informatieobject'] ?? '';
		$objectUrl  = $body['object'] ?? '';
		$objectType = $body['objectType'] ?? '';

		$error = $this->checkOioUrls(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType)
			?? $this->checkOioRelations(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
		if ($error !== null) {
			return $error;
		}

		return $this->isValid(body: $body);
	}//end rulesObjectinformatieobjectenCreate()

	/**
	 * Drc-002: the informatieobject URL and the object URL are valid, when given.
	 *
	 * @param mixed $ioUrl The informatieobject URL
	 * @param mixed $objectUrl The object URL
	 * @param mixed $objectType The objectType
	 *
	 * @return array|null The refusal, or null when acceptable
	 */
	private function checkOioUrls(mixed $ioUrl, mixed $objectUrl, mixed $objectType): ?array {
		if ($ioUrl !== '') {
			$error = $this->validateInformatieobjectUrl(ioUrl: $ioUrl);
			if ($error !== null) {
				return $error;
			}
		}

		if ($objectUrl === '') {
			return null;
		}

		return $this->validateObjectUrl(objectUrl: $objectUrl, objectType: $objectType);
	}//end checkOioUrls()

	/**
	 * Drc-003 then drc-004: the relation is not a duplicate, and its ZIO/BIO exists.
	 *
	 * Uniqueness runs first so a duplicate error takes priority over the cross-register check.
	 *
	 * @param mixed $ioUrl The informatieobject URL
	 * @param mixed $objectUrl The object URL
	 * @param mixed $objectType The objectType
	 *
	 * @return array|null The refusal, or null when acceptable or nothing can be looked up
	 */
	private function checkOioRelations(mixed $ioUrl, mixed $objectUrl, mixed $objectType): ?array {
		if ($ioUrl === '' || $objectUrl === '' || $this->objectService === null) {
			return null;
		}

		$error = $this->relations->checkOioUniqueness(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
		if ($error !== null || $objectType === '') {
			return $error;
		}

		return $this->validateOioCrossRegister(ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
	}//end checkOioRelations()

	/**
	 * Validate OIO object URL (drc-002a/b/c/d).
	 *
	 * @param string $objectUrl The object URL
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return array|null Validation error, or null if valid
	 */
	private function validateObjectUrl(string $objectUrl, string $objectType): ?array {
		// Drc-002a/b: Check URL validity.
		if ($this->isValidUrl(url: $objectUrl) === false) {
			return $this->error(
				status: 400,
				detail: 'De object URL is ongeldig.',
				invalidParams: [
					$this->fieldError(fieldName: 'object', code: 'bad-url', reason: 'De object URL is ongeldig.'),
				]
			);
		}

		// Drc-002a/b: Check URL contains a UUID.
		$objectUuid = $this->extractUuid(url: $objectUrl);
		if ($objectUuid === null) {
			return $this->error(
				status: 400,
				detail: 'De object URL bevat geen geldig UUID.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'object',
						code: 'bad-url',
						reason: 'De object URL bevat geen geldig UUID.'
					),
				]
			);
		}

		// Drc-002c/d: Validate objectType matches the URL path.
		if ($objectType === 'case' && strpos($objectUrl, '/zaken/') === false) {
			return $this->error(
				status: 400,
				detail: 'De object URL wijst niet naar een zaak.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'object',
						code: 'invalid-resource',
						reason: 'De object URL wijst niet naar een zaak resource.'
					),
				]
			);
		}

		if ($objectType === 'decision' && strpos($objectUrl, '/besluiten/') === false) {
			return $this->error(
				status: 400,
				detail: 'De object URL wijst niet naar een besluit.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'object',
						code: 'invalid-resource',
						reason: 'De object URL wijst niet naar een besluit resource.'
					),
				]
			);
		}

		return null;
	}//end validateObjectUrl()

	/**
	 * Validate OIO cross-register: ZIO/BIO must exist (drc-004 VNG).
	 *
	 * @param string $ioUrl The informatieobject URL
	 * @param string $objectUrl The object URL (zaak or besluit)
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return array|null Validation error if relation doesn't exist, null if valid
	 */
	private function validateOioCrossRegister(string $ioUrl, string $objectUrl, string $objectType): ?array {
		$ioUuid = $this->extractUuid(url: $ioUrl) ?? '';
		$objectUuid = $this->extractUuid(url: $objectUrl) ?? '';

		if ($ioUuid === '' || $objectUuid === '') {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		if ($register === '') {
			return null;
		}

		$typeMap = [
			'case' => ['case_document_schema', 'case'],
			'decision' => ['decision_document_schema', 'decision'],
		];
		if (isset($typeMap[$objectType]) === false) {
			return null;
		}

		[$schemaKey, $searchField] = $typeMap[$objectType];

		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($schema === '') {
			return null;
		}

		try {
			$query = $this->objectService->buildSearchQuery(
				requestParams: [
					$searchField => $objectUuid,
					'document' => $ioUuid,
					'_limit' => 1,
				],
				register: $register,
				schema: $schema
			);
			$result = $this->objectService->searchObjectsPaginated(query: $query);
			$total = $result['total'] ?? count($result['results'] ?? []);

			if ($total === 0) {
				$detail = 'Er bestaat geen BesluitInformatieObject in de Besluiten API voor deze combinatie.';
				if ($objectType === 'case') {
					$detail = 'Er bestaat geen ZaakInformatieObject in de Zaken API voor deze combinatie.';
				}

				return $this->error(
					status: 400,
					detail: $detail,
					invalidParams: [
						$this->fieldError(
							fieldName: 'nonFieldErrors',
							code: 'inconsistent-relation',
							reason: $detail
						),
					]
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Drc-004: Cross-register validation failed: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end validateOioCrossRegister()
}//end class
