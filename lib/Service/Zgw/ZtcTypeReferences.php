<?php

/**
 * Resolves the type references in a ZTC request body
 *
 * A zaaktype or besluittype may name its related types by URL, by uuid, or by
 * omschrijving or identificatie. This turns each list into uuids and copies the
 * resolved lists into `_directFields`.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwRulesBase;

/**
 * Reference lists of a type, resolved to uuids.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcTypeReferences extends ZgwRulesBase {
	/**
	 * Copy resolved reference arrays into `_directFields`, which bypasses the Twig mapping.
	 *
	 * @param array $body The request body
	 * @param array<string, string> $map Body field => direct field; only array values are copied
	 * @param array<string> $jsonFields Direct fields stored JSON-encoded (string fields in the schema)
	 *
	 * @return array The body, with `_directFields` set when anything was copied
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function withDirectFields(array $body, array $map, array $jsonFields=[]): array {
		$directFields = [];
		foreach ($map as $field => $directField) {
			if (isset($body[$field]) === false || is_array($body[$field]) === false) {
				continue;
			}

			$directFields[$directField] = $body[$field];
			if (in_array($directField, $jsonFields, true) === true) {
				$directFields[$directField] = json_encode($body[$field]);
			}
		}

		if (empty($directFields) === false) {
			$body['_directFields'] = $directFields;
		}

		return $body;
	}//end withDirectFields()

	/**
	 * Resolve non-URL references in a type array field to actual object UUIDs.
	 *
	 * When a ZGW type resource has array fields like informatieobjecttypen
	 * or besluittypen, VNG tests may send references by omschrijving instead
	 * of URLs. This resolves those to the corresponding object UUIDs.
	 *
	 * @param array $body The request body
	 * @param string $field The field name containing the references
	 * @param string $schemaKey The settings config key for the target schema
	 * @param string $lookupField The OpenRegister field to search by
	 *
	 * @return array The body with resolved references
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolveTypeReferences(
		array $body,
		string $field,
		string $schemaKey,
		string $lookupField,
	): array {
		$scope = $this->getLookupScope(list: $body[$field] ?? null, schemaKey: $schemaKey);
		if ($scope === null) {
			return $body;
		}

		$resolved = [];
		foreach ($body[$field] as $ref) {
			if (is_string($ref) === false || $ref === '') {
				continue;
			}

			array_push($resolved, ...$this->resolveReference(ref: $ref, scope: $scope, lookupField: $lookupField));
		}

		$body[$field] = $resolved;

		return $body;
	}//end resolveTypeReferences()

	/**
	 * Resolve one type reference to the uuids it names.
	 *
	 * A URL holding a uuid gives that uuid. Otherwise every object whose lookup field matches is
	 * taken; when none matches, a reference that holds a uuid is used as-is, and anything else
	 * resolves to nothing.
	 *
	 * @param string $ref The reference
	 * @param array{0: string, 1: string} $scope The register and schema to search
	 * @param string $lookupField The OpenRegister field to search by
	 *
	 * @return array<string> The resolved uuids
	 */
	private function resolveReference(string $ref, array $scope, string $lookupField): array {
		// If it's a URL containing a UUID, extract and store just the UUID.
		if (str_starts_with($ref, 'http://') === true || str_starts_with($ref, 'https://') === true) {
			$urlUuid = $this->extractUuid(url: $ref);
			if ($urlUuid !== null) {
				return [$urlUuid];
			}
		}

		// Search by omschrijving/identificatie in OpenRegister.
		$foundIds = $this->findAllObjectsByField(register: $scope[0], schema: $scope[1], field: $lookupField, value: $ref);
		if (empty($foundIds) === false) {
			return array_values($foundIds);
		}

		// Fallback: if name lookup found nothing and it looks like a UUID, use as-is.
		$bareUuid = $this->extractUuid(url: $ref);
		if ($bareUuid === null) {
			return [];
		}

		return [$bareUuid];
	}//end resolveReference()

	/**
	 * The register and schema a reference list is resolved in, or null when it cannot be.
	 *
	 * @param mixed $list The reference list from the body; anything but an array is skipped
	 * @param string $schemaKey The settings config key for the target schema
	 *
	 * @return array{0: string, 1: string}|null The register and schema
	 */
	private function getLookupScope(mixed $list, string $schemaKey): ?array {
		if (is_array($list) === false || $this->objectService === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return [$register, $schema];
	}//end getLookupScope()

	/**
	 * Resolve gerelateerdeZaaktypen references (nested objects with zaaktype field).
	 *
	 * A relation naming its zaaktype by URL is kept; one naming it by identificatie becomes one
	 * relation per matching zaaktype; anything else is dropped.
	 *
	 * @param array $body The request body
	 *
	 * @return array The body with resolved zaaktype references
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resolveGerelateerdeZaaktypen(array $body): array {
		$scope = $this->getLookupScope(list: $body['gerelateerdeZaaktypen'] ?? null, schemaKey: 'case_type_schema');
		if ($scope === null) {
			return $body;
		}

		$resolved = [];
		foreach ($body['gerelateerdeZaaktypen'] as $rel) {
			$caseTypeRef = $rel['caseType'] ?? '';
			if ($caseTypeRef === '' || is_string($caseTypeRef) === false) {
				continue;
			}

			if (str_starts_with($caseTypeRef, 'http://') === true || str_starts_with($caseTypeRef, 'https://') === true) {
				$resolved[] = $rel;
				continue;
			}

			$foundIds = $this->findAllObjectsByField(register: $scope[0], schema: $scope[1], field: 'identifier', value: $caseTypeRef);
			foreach ($foundIds as $id) {
				$entry = $rel;
				$entry['caseType'] = $id;
				$resolved[] = $entry;
			}
		}//end foreach

		$body['gerelateerdeZaaktypen'] = $resolved;

		return $body;
	}//end resolveGerelateerdeZaaktypen()
}//end class
