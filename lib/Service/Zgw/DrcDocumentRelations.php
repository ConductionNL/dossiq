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
	 */
	public function __construct(
		private readonly ZgwRuleLookups $lookups,
		private readonly ZgwRuleResults $results,
		private readonly SettingsService $settingsService,
		private readonly FieldValidator $fieldValidator,
	) {
	}//end __construct()

	/**
	 * Find OIO relations for a document UUID (drc-007/drc-008a).
	 *
	 * OIO objects store the full informatieobject URL in the 'document' field.
	 * This method searches by UUID first, then by partial URL match if needed.
	 *
	 * An empty list means both searches ran and found none. A search that cannot
	 * run throws, so the caller refuses the delete instead of reading "no relations".
	 *
	 * @param string $register The register ID
	 * @param string $schema The OIO schema ID
	 * @param string $docUuid The document UUID
	 *
	 * @return array<string> Array of matching OIO UUIDs
	 *
	 * @throws \Throwable When the store cannot be searched.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findOioRelationsForDocument(
		string $register,
		string $schema,
		string $docUuid,
	): array {
		// First try by exact UUID (in case stored as UUID).
		$results = $this->lookups->idsByField(
			register: $register,
			schema: $schema,
			field: 'document',
			value: $docUuid
		);
		if (empty($results) === false) {
			return $results;
		}

		// Also search by partial URL match (OIO may store full URL).
		return $this->lookups->idsByField(
			register: $register,
			schema: $schema,
			field: 'document',
			value: '%' . $docUuid . '%'
		);
	}//end findOioRelationsForDocument()

	/**
	 * Validate OIO uniqueness: object + informatieobject + objectType (drc-003 VNG).
	 *
	 * A search that cannot run refuses with the "could not be checked" answer.
	 * It does not answer "duplicate": that would tell the caller a relation
	 * exists that nobody found.
	 *
	 * @param string $ioUrl The informatieobject URL
	 * @param string $objectUrl The object URL (zaak or besluit)
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return array|null The refusal, or null when the relation is new
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function checkOioUniqueness(string $ioUrl, string $objectUrl, string $objectType): ?array {
		$register = $this->lookups->register();
		if ($register === '') {
			return null;
		}

		try {
			$duplicate = $this->relationExists(register: $register, ioUrl: $ioUrl, objectUrl: $objectUrl, objectType: $objectType);
		} catch (\Throwable $e) {
			return $this->results->notVerified(
				rule: 'drc-003',
				object: 'document ' . $ioUrl . ' on ' . $objectUrl,
				cause: $e->getMessage()
			);
		}

		if ($duplicate === false) {
			return null;
		}

		return $this->results->error(
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
	}//end checkOioUniqueness()

	/**
	 * Whether the relation is stored already, as an OIO or as its ZIO or BIO.
	 *
	 * Drc-003a/b: a relationship created via ZRC (zaakinformatieobjecten) or BRC
	 * (besluitinformatieobjecten) counts as a duplicate for OIO creation.
	 *
	 * @param string $register The register ID
	 * @param string $ioUrl The informatieobject URL
	 * @param string $objectUrl The object URL (zaak or besluit)
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return bool True when a stored relation was found
	 *
	 * @throws \Throwable When the store cannot be searched.
	 */
	private function relationExists(string $register, string $ioUrl, string $objectUrl, string $objectType): bool {
		$pair = $this->pairOf(ioUrl: $ioUrl, objectUrl: $objectUrl);
		foreach ($this->placesFor(objectType: $objectType) as [$schemaKey, $objectField]) {
			$schema = $this->settingsService->getConfigValue(key: $schemaKey);
			if ($schema === '') {
				continue;
			}

			foreach ($this->duplicateQueries(pair: $pair, objectField: $objectField) as $requestParams) {
				if ($this->anyFound(scope: [$register, $schema], requestParams: $requestParams) === true) {
					return true;
				}
			}
		}

		return false;
	}//end relationExists()

	/**
	 * The URL and the UUID of the document and of the object.
	 *
	 * @param string $ioUrl     The informatieobject URL
	 * @param string $objectUrl The object URL
	 *
	 * @return array{document: array{0: string, 1: ?string}, object: array{0: string, 1: ?string}}
	 */
	private function pairOf(string $ioUrl, string $objectUrl): array {
		return [
			'document' => [$ioUrl, $this->fieldValidator->extractUuid(url: $ioUrl)],
			'object' => [$objectUrl, $this->fieldValidator->extractUuid(url: $objectUrl)],
		];
	}//end pairOf()

	/**
	 * The schemas a relation of this object type can be stored in, each with the field naming the object.
	 *
	 * @param string $objectType The object type (zaak or besluit)
	 *
	 * @return list<array{0: string, 1: string}> Settings key of the schema, and the object field
	 */
	private function placesFor(string $objectType): array {
		$crossRegister = ['case' => ['case_document_schema', 'case'], 'decision' => ['decision_document_schema', 'decision']];
		$places = [['document_link_schema', 'object']];
		if (isset($crossRegister[$objectType]) === true) {
			$places[] = $crossRegister[$objectType];
		}

		return $places;
	}//end placesFor()

	/**
	 * The searches for one stored relation, in order: by full URL, then by UUID
	 * (stored data may use UUID instead of URL), then full text on both UUIDs
	 * (field-specific LIKE with % is not supported by MagicMapper).
	 *
	 * @param array<string, array{0: string, 1: ?string}> $pair        URL and UUID of the `document` and of the `object`
	 * @param string                                      $objectField The field name for the object reference
	 *
	 * @return list<array<string, mixed>> The request parameters of each search
	 */
	private function duplicateQueries(array $pair, string $objectField): array {
		[$ioUrl, $ioUuid] = $pair['document'];
		[$objectUrl, $objectUuid] = $pair['object'];

		$queries = [['document' => $ioUrl, $objectField => $objectUrl, '_limit' => 1]];
		if ($ioUuid !== null && $objectUuid !== null) {
			$queries[] = ['document' => $ioUuid, $objectField => $objectUuid, '_limit' => 1];
			$queries[] = ['_search' => $ioUuid . ' ' . $objectUuid, '_limit' => 1];
		}

		return $queries;
	}//end duplicateQueries()

	/**
	 * Whether one search finds a stored relation.
	 *
	 * @param array{0: string, 1: string} $scope         The register ID and the schema ID
	 * @param array<string, mixed>        $requestParams The search parameters
	 *
	 * @return bool True if a duplicate was found
	 *
	 * @throws \Throwable When the store cannot be searched.
	 */
	private function anyFound(array $scope, array $requestParams): bool {
		$query = $this->lookups->objectService()->buildSearchQuery(
			requestParams: $requestParams,
			register: $scope[0],
			schema: $scope[1]
		);
		$result = $this->lookups->objectService()->searchObjectsPaginated(query: $query);

		return ($result['total'] ?? count($result['results'] ?? [])) > 0;
	}//end anyFound()
}//end class
