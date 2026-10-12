<?php

/**
 * The relations a document already has, as the DRC rules read them
 *
 * Answers two questions from the stored relations: which objectinformatieobjecten
 * point at a document (drc-007), and whether a document and an object are linked
 * already, in the Documenten API or through the Zaken or Besluiten API (drc-003).
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
use Psr\Log\LoggerInterface;

/**
 * Reads the stored relations of a document.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class DrcDocumentRelations {
	/**
	 * Constructor.
	 *
	 * @param ZgwRuleLookups $lookups Store lookups in the request context.
	 * @param ZgwRuleResults $results Builds the answers of a rule.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ZgwRuleLookups $lookups,
		private readonly ZgwRuleResults $results,
		private readonly SettingsService $settingsService,
		private readonly FieldValidator $fieldValidator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find OIO relations for a document UUID (drc-007/drc-008a).
	 *
	 * OIO objects store the full informatieobject URL in the 'document' field.
	 * This method searches by UUID first, then by partial URL match if needed.
	 *
	 * @param string $register The register ID
	 * @param string $schema The OIO schema ID
	 * @param string $docUuid The document UUID
	 *
	 * @return array<string> Array of matching OIO UUIDs
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findOioRelationsForDocument(
		string $register,
		string $schema,
		string $docUuid,
	): array {
		// First try by exact UUID (in case stored as UUID).
		$results = $this->lookups->findAllObjectsByField(
			register: $register,
			schema: $schema,
			field: 'document',
			value: $docUuid
		);
		if (empty($results) === false) {
			return $results;
		}

		// Also search by partial URL match (OIO may store full URL).
		try {
			$query = $this->lookups->objectService()->buildSearchQuery(
				requestParams: ['document' => '%' . $docUuid . '%', '_limit' => 100],
				register: $register,
				schema: $schema
			);
			$result = $this->lookups->objectService()->searchObjectsPaginated(query: $query);

			$ids = [];
			foreach (($result['results'] ?? []) as $obj) {
				$data = $obj;
				if (is_array($obj) === false) {
					$data = $obj->jsonSerialize();
				}

				$id = $data['id'] ?? ($data['@self']['id'] ?? null);
				if ($id !== null) {
					$ids[] = $id;
				}
			}

			return $ids;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Drc-007: OIO relation search with partial match failed: ' . $e->getMessage()
			);
			return [];
		}//end try
	}//end findOioRelationsForDocument()

	/**
	 * Validate OIO uniqueness: object + informatieobject + objectType (drc-003 VNG).
	 *
	 * @param string $ioUrl The informatieobject URL
	 * @param string $objectUrl The object URL (zaak or besluit)
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return array|null Validation error if duplicate found, null if unique
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function checkOioUniqueness(string $ioUrl, string $objectUrl, string $objectType): ?array {
		$register = $this->lookups->register();
		$oioSchema = $this->settingsService->getConfigValue(key: 'document_link_schema');

		if ($register === '') {
			return null;
		}

		$duplicateError = $this->results->error(
			status: 400,
			detail: 'De combinatie informatieobject + object + objectType bestaat al.',
			invalidParams: [
				$this->results->fieldError(
					fieldName: 'nonFieldErrors',
					code: 'unique',
					reason: 'De combinatie informatieobject + object + objectType bestaat al.'
				),
			]
		);

		$ioUuid = $this->fieldValidator->extractUuid(url: $ioUrl);
		$objectUuid = $this->fieldValidator->extractUuid(url: $objectUrl);

		// Check OIO schema for existing duplicates.
		if ($oioSchema !== '') {
			$found = $this->searchDuplicateRelation(
				register: $register,
				schema: $oioSchema,
				ioUrl: $ioUrl,
				objectUrl: $objectUrl,
				ioUuid: $ioUuid,
				objectUuid: $objectUuid,
				objectField: 'object'
			);
			if ($found === true) {
				return $duplicateError;
			}
		}

		// Drc-003a/b: Also check ZIO/BIO schemas — a relationship created via
		// ZRC (zaakinformatieobjecten) or BRC (besluitinformatieobjecten) counts
		// as a duplicate for OIO creation.
		$crossSchemaKey = '';
		$crossField = '';
		if ($objectType === 'case') {
			$crossSchemaKey = 'case_document_schema';
			$crossField = 'case';
		} elseif ($objectType === 'decision') {
			$crossSchemaKey = 'decision_document_schema';
			$crossField = 'decision';
		}

		if ($crossSchemaKey !== '') {
			$crossSchema = $this->settingsService->getConfigValue(key: $crossSchemaKey);
			if ($crossSchema !== '') {
				$found = $this->searchDuplicateRelation(
					register: $register,
					schema: $crossSchema,
					ioUrl: $ioUrl,
					objectUrl: $objectUrl,
					ioUuid: $ioUuid,
					objectUuid: $objectUuid,
					objectField: $crossField
				);
				if ($found === true) {
					return $duplicateError;
				}
			}
		}

		return null;
	}//end checkOioUniqueness()

	/**
	 * Search for an existing relation in a schema by document+object combination.
	 *
	 * @param string $register The register ID
	 * @param string $schema The schema ID to search
	 * @param string $ioUrl The informatieobject URL
	 * @param string $objectUrl The object URL
	 * @param string|null $ioUuid The extracted informatieobject UUID
	 * @param string|null $objectUuid The extracted object UUID
	 * @param string $objectField The field name for the object reference
	 *
	 * @return bool True if a duplicate was found
	 */
	private function searchDuplicateRelation(
		string $register,
		string $schema,
		string $ioUrl,
		string $objectUrl,
		?string $ioUuid,
		?string $objectUuid,
		string $objectField,
	): bool {
		try {
			// Search by full URL.
			$query = $this->lookups->objectService()->buildSearchQuery(
				requestParams: ['document' => $ioUrl, $objectField => $objectUrl, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $this->lookups->objectService()->searchObjectsPaginated(query: $query);
			if (($result['total'] ?? count($result['results'] ?? [])) > 0) {
				return true;
			}

			// Fallback: search by UUID (stored data may use UUID instead of URL).
			if ($ioUuid !== null && $objectUuid !== null) {
				$query = $this->lookups->objectService()->buildSearchQuery(
					requestParams: ['document' => $ioUuid, $objectField => $objectUuid, '_limit' => 1],
					register: $register,
					schema: $schema
				);
				$result = $this->lookups->objectService()->searchObjectsPaginated(query: $query);
				if (($result['total'] ?? count($result['results'] ?? [])) > 0) {
					return true;
				}

				// Full-text search using both UUIDs (field-specific LIKE
				// with % is not supported by MagicMapper).
				$query = $this->lookups->objectService()->buildSearchQuery(
					requestParams: ['_search' => $ioUuid . ' ' . $objectUuid, '_limit' => 1],
					register: $register,
					schema: $schema
				);
				$result = $this->lookups->objectService()->searchObjectsPaginated(query: $query);
				if (($result['total'] ?? count($result['results'] ?? [])) > 0) {
					return true;
				}
			}//end if
		} catch (\Throwable $e) {
			// Fail-closed: treat an indeterminate check as a duplicate to prevent
			// inadvertent duplicates when the storage backend is unavailable.
			$this->logger->warning(
				'Drc-003: Uniqueness check failed for schema {schema}: {message}',
				['schema' => $schema, 'message' => $e->getMessage()]
			);
			return true;
		}//end try

		return false;
	}//end searchDuplicateRelation()
}//end class
