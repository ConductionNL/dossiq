<?php

/**
 * Dossiq ZTC URL validity filter.
 *
 * A zaaktype or besluittype read from the Catalogi API lists URLs to related types. Only URLs
 * that point at a published type, valid today, may stay in those lists. Split out of
 * ZtcController (method-decomposition).
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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Support\NormalisesObjectRows;

/**
 * Drops the cross-reference URLs of ZTC types that do not point at a published, current type.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcUrlValidityFilter {
	use NormalisesObjectRows;

	/**
	 * The Catalogi API.
	 */
	private const ZGW_API = 'catalogi';

	/**
	 * The ZGW resource behind each settings schema key, for the validity lookup.
	 */
	private const SCHEMA_RESOURCES = [
		'document_type_schema' => 'informatieobjecttypen',
		'decision_type_schema' => 'besluittypen',
		'case_type_schema'     => 'zaaktypen',
	];


	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService Supplies the ObjectService and the ZGW mappings
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
	) {
	}//end __construct()

	/**
	 * Drop every URL in the configured list fields that does not point at a published,
	 * currently valid object.
	 *
	 * @param array<string, array{schemaKey: string, nested: bool}> $fieldConfigs List field => target
	 * @param array $data The outbound-mapped object
	 * @param string $today Today's date in Y-m-d format
	 *
	 * @return array The object with only valid URLs left
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function filter(array $fieldConfigs, array $data, string $today): array {
		if (empty($fieldConfigs) === true || $this->zgwService->getObjectService() === null) {
			return $data;
		}

		foreach ($fieldConfigs as $field => $config) {
			if (isset($data[$field]) === false || is_array($data[$field]) === false) {
				continue;
			}

			$filtered = [];
			foreach ($data[$field] as $item) {
				if ($this->isValidListItem(item: $item, config: $config, today: $today) === true) {
					$filtered[] = $item;
				}
			}

			$data[$field] = $filtered;
		}

		return $data;
	}//end filter()

	/**
	 * Whether one list item may stay: a URL string, or (nested) a relation whose caseType URL is valid.
	 *
	 * @param mixed $item The list item
	 * @param array{schemaKey: string, nested: bool} $config The list's target
	 * @param string $today Today's date in Y-m-d format
	 *
	 * @return bool True when the item may stay
	 */
	private function isValidListItem(mixed $item, array $config, string $today): bool {
		// GerelateerdeZaaktypen: array of objects with 'caseType' URL field.
		if ($config['nested'] === true) {
			return $this->isUrlValid(url: $item['caseType'] ?? '', schemaKey: $config['schemaKey'], today: $today);
		}

		return is_string($item) === true && $this->isUrlValid(url: $item, schemaKey: $config['schemaKey'], today: $today);
	}//end isValidListItem()

	/**
	 * Whether a URL points at a published object that is valid today.
	 *
	 * A schema key without a known resource, or a resource without a mapping, counts as valid;
	 * a URL without a uuid, or an object that cannot be read, does not.
	 *
	 * @param string $url The URL
	 * @param string $schemaKey The settings key of the target schema
	 * @param string $today Today's date in Y-m-d format
	 *
	 * @return bool True when the URL may stay
	 */
	private function isUrlValid(string $url, string $schemaKey, string $today): bool {
		if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $url, $matches) !== 1) {
			return false;
		}

		$targetResource = self::SCHEMA_RESOURCES[$schemaKey] ?? null;
		if ($targetResource === null) {
			return true;
		}

		try {
			$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $targetResource);
			if ($mappingConfig === null) {
				return true;
			}

			$objectData = $this->findIn(objectService: $this->zgwService->getObjectService(), mapping: $mappingConfig, id: $matches[1]);

			return $this->isPublishedAndCurrent(objectData: $objectData, today: $today);
		} catch (\Throwable $e) {
			// If we can't look up the object, exclude the URL.
			return false;
		}
	}//end isUrlValid()

	/**
	 * Whether an object is published (not a concept) and valid today.
	 *
	 * @param array $objectData The object
	 * @param string $today Today's date in Y-m-d format
	 *
	 * @return bool True when published, begun and not ended
	 */
	private function isPublishedAndCurrent(array $objectData, string $today): bool {
		$isDraft = $objectData['isDraft'] ?? ($objectData['draft'] ?? true);
		if (in_array($isDraft, [true, 'true', '1', 1], true) === true) {
			return false;
		}

		// BeginGeldigheid <= today.
		$start = $objectData['validFrom'] ?? ($objectData['startValidity'] ?? null);
		if ($start !== null && $start !== '' && $start > $today) {
			return false;
		}

		// EindeGeldigheid >= today, or no end date.
		$end = $objectData['validUntil'] ?? ($objectData['endValidity'] ?? null);

		return $end === null || $end === '' || $end >= $today;
	}//end isPublishedAndCurrent()

	/**
	 * Read one object of a mapping as an array.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array $mapping The ZGW mapping (`sourceRegister`, `sourceSchema`)
	 * @param string $id The object id
	 *
	 * @return array The object
	 */
	private function findIn(?object $objectService, array $mapping, string $id): array {
		return $this->objectToArray(
			row: $objectService->find(id: $id, register: $mapping['sourceRegister'], schema: $mapping['sourceSchema'])
		);
	}//end findIn()
}//end class
