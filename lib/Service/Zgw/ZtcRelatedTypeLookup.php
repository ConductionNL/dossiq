<?php

/**
 * Dossiq ZTC related type lookup.
 *
 * The OpenRegister lookups behind a ZTC type's cross-reference lists: zaaktypen sharing an
 * identifier, informatieobjecttypen sharing a name, and the objects that point at a zaaktype.
 * Split out of ZtcController with ZtcCrossReferenceEnricher (method-decomposition).
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

use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Support\NormalisesObjectRows;

/**
 * Looks up the types a ZTC zaaktype relates to.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcRelatedTypeLookup {
	use NormalisesObjectRows;

	/**
	 * The Catalogi API.
	 */
	private const ZGW_API = 'catalogi';

	/**
	 * Constructor.
	 *
	 * @param ZgwServiceInterface $zgwService Supplies the ZGW mappings
	 * @param LanguageMapText $text Reads a translatable document type name as text
	 */
	public function __construct(
		private readonly ZgwServiceInterface $zgwService,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * The URLs of every zaaktype that shares the identifier of the referenced one.
	 *
	 * When the referenced zaaktype cannot be read, its own URL stands in.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $ztMapping The zaaktypen mapping
	 * @param string $ztRef The referenced zaaktype uuid
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array<string> The zaaktype URLs (empty when it has no identifier)
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function sameIdentifierUrls(object $objectService, array $ztMapping, string $ztRef, string $baseUrl): array {
		$caseTypes = new MappedObjects(objectService: $objectService, mapping: $ztMapping);

		return array_map(
			static fn (mixed $id): string => $baseUrl.'/zaaktypen/'.$id,
			$this->sameIdentifierIds(caseTypes: $caseTypes, ztRef: $ztRef)
		);
	}//end sameIdentifierUrls()

	/**
	 * The ids of every zaaktype that shares the identifier of the referenced one.
	 *
	 * When the referenced zaaktype cannot be read, its own id stands in.
	 *
	 * @param MappedObjects $caseTypes The object service with the zaaktypen mapping
	 * @param string        $ztRef     The referenced zaaktype uuid
	 *
	 * @return array<string> The zaaktype ids (empty when it has no identifier)
	 */
	private function sameIdentifierIds(MappedObjects $caseTypes, string $ztRef): array {
		try {
			$ident = $this->findIn(objectService: $caseTypes->objectService, mapping: $caseTypes->mapping, id: $ztRef)['identifier'] ?? '';
			if ($ident === '') {
				return [];
			}

			return $this->searchIds(objects: $caseTypes, params: ['identifier' => $ident]);
		} catch (\Throwable $e) {
			return [$ztRef];
		}
	}//end sameIdentifierIds()

	/**
	 * Expand gerelateerdeZaaktypen: a URL is kept, a uuid becomes one relation per zaaktype
	 * with the same identifier, and the result is deduplicated by zaaktype URL.
	 *
	 * @param array $related The stored relations
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $ztMapping The zaaktypen mapping
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array The expanded relations
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function expandRelatedCaseTypes(array $related, object $objectService, array $ztMapping, string $baseUrl): array {
		$seen = [];
		$unique = [];
		foreach ($related as $rel) {
			$ztRef = $rel['caseType'] ?? '';
			if (is_string($ztRef) === false || $ztRef === '') {
				continue;
			}

			$urls = [$ztRef];
			if (str_starts_with($ztRef, 'http') === false) {
				$urls = $this->sameIdentifierUrls(objectService: $objectService, ztMapping: $ztMapping, ztRef: $ztRef, baseUrl: $baseUrl);
			}

			foreach ($urls as $url) {
				if (isset($seen[$url]) === false) {
					$seen[$url] = true;
					$unique[] = array_merge($rel, ['caseType' => $url]);
				}
			}
		}

		return $unique;
	}//end expandRelatedCaseTypes()

	/**
	 * The informatieobjecttype URLs of a zaaktype, through its ZIOT records.
	 *
	 * Each ZIOT's informatieobjecttype is expanded to every type with the same name, so the
	 * validity filter can pick the one valid today. A type that cannot be read stands in with
	 * its own URL.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The zaaktype uuid
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array<string> The deduplicated URLs
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function caseTypeDocumentTypeUrls(object $objectService, string $uuid, string $baseUrl): array {
		$ziotMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktype-informatieobjecttypen');
		$iotMapping  = $this->zgwService->loadMappingConfig(self::ZGW_API, 'informatieobjecttypen');
		if ($ziotMapping === null || $iotMapping === null) {
			return [];
		}

		$iotUrls = array_map(
			static fn (mixed $id): string => $baseUrl.'/informatieobjecttypen/'.$id,
			$this->documentTypeIds(
				caseTypeLinks: new MappedObjects(objectService: $objectService, mapping: $ziotMapping),
				lookup: ['uuid' => $uuid, 'documentTypes' => new MappedObjects(objectService: $objectService, mapping: $iotMapping)]
			)
		);

		return array_values(array_unique($iotUrls));
	}//end caseTypeDocumentTypeUrls()

	/**
	 * The informatieobjecttype ids of a zaaktype's ZIOT records, each expanded to every type with the same name.
	 *
	 * @param MappedObjects                                     $caseTypeLinks The object service with the ZIOT mapping
	 * @param array{uuid: string, documentTypes: MappedObjects} $lookup        The zaaktype uuid, and the object service with
	 *                                                                         the informatieobjecttypen mapping
	 *
	 * @return array<mixed> The ids, in order, possibly repeated
	 */
	private function documentTypeIds(MappedObjects $caseTypeLinks, array $lookup): array {
		$ids = [];
		foreach ($this->searchRowsOrNone(objects: $caseTypeLinks, params: ['caseType' => $lookup['uuid']]) as $ziotData) {
			$iotRef = $ziotData['informatieobjecttype'] ?? '';
			if ($iotRef !== '') {
				array_push($ids, ...$this->sameNameDocumentTypeIds(documentTypes: $lookup['documentTypes'], iotRef: $iotRef));
			}
		}

		return $ids;
	}//end documentTypeIds()

	/**
	 * The ids of every informatieobjecttype named like the referenced one.
	 *
	 * @param MappedObjects $documentTypes The object service with the informatieobjecttypen mapping
	 * @param mixed         $iotRef        The referenced informatieobjecttype uuid
	 *
	 * @return array<mixed> The ids (empty when the type has no name); the reference itself when it cannot be read
	 */
	private function sameNameDocumentTypeIds(MappedObjects $documentTypes, mixed $iotRef): array {
		try {
			$found = $this->findIn(objectService: $documentTypes->objectService, mapping: $documentTypes->mapping, id: $iotRef);
			$iotName = $this->text->textOf(value: ($found['name'] ?? ''));
			if ($iotName === '') {
				return [];
			}

			return $this->searchIds(objects: $documentTypes, params: ['name' => $iotName]);
		} catch (\Throwable $e) {
			// If IOT lookup fails, fall back to direct UUID.
			return [$iotRef];
		}
	}//end sameNameDocumentTypeIds()

	/**
	 * The URLs of every object of a resource whose caseType is the zaaktype.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $resource The ZGW resource
	 * @param string $uuid The zaaktype uuid
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array<string> The URLs (empty when unmapped or on error)
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function urlsOfObjectsFor(object $objectService, string $resource, string $uuid, string $baseUrl): array {
		$mapping = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mapping === null) {
			return [];
		}

		$objects = new MappedObjects(objectService: $objectService, mapping: $mapping);

		return array_map(
			static fn (mixed $id): string => $baseUrl.'/'.$resource.'/'.$id,
			$this->idsOfObjectsFor(objects: $objects, uuid: $uuid)
		);
	}//end urlsOfObjectsFor()

	/**
	 * The ids of every object of a mapping whose caseType is the zaaktype, skipping rows without one.
	 *
	 * @param MappedObjects $objects The object service with the resource's mapping
	 * @param string        $uuid    The zaaktype uuid
	 *
	 * @return array<mixed> The ids (empty on error)
	 */
	private function idsOfObjectsFor(MappedObjects $objects, string $uuid): array {
		$ids = [];
		foreach ($this->searchRowsOrNone(objects: $objects, params: ['caseType' => $uuid]) as $row) {
			$id = $row['id'] ?? ($row['@self']['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsOfObjectsFor()

	/**
	 * Read one object of a mapping as an array.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array $mapping The ZGW mapping (`sourceRegister`, `sourceSchema`)
	 * @param mixed $id The object id
	 *
	 * @return array The object
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findIn(?object $objectService, array $mapping, mixed $id): array {
		return $this->objectToArray(
			row: $objectService->find(id: $id, register: $mapping['sourceRegister'], schema: $mapping['sourceSchema'])
		);
	}//end findIn()

	/**
	 * Search a mapping's objects (at most 100) and return them as arrays.
	 *
	 * @param MappedObjects $objects The object service with the ZGW mapping
	 * @param array $params The search filters
	 *
	 * @return array<array> The matching objects
	 */
	private function searchRows(MappedObjects $objects, array $params): array {
		$objectService = $objects->objectService;
		$query = $objectService->buildSearchQuery(
			requestParams: array_merge($params, ['_limit' => 100]),
			register: $objects->mapping['sourceRegister'],
			schema: $objects->mapping['sourceSchema']
		);

		return array_map(
			fn (mixed $row): array => $this->objectToArray(row: $row),
			$objectService->searchObjectsPaginated(query: $query)['results'] ?? []
		);
	}//end searchRows()

	/**
	 * Search a mapping's objects, answering none when the search fails.
	 *
	 * A failed search only means a list stays unenriched on a read, so it degrades to an empty
	 * answer and is logged rather than failing the read.
	 *
	 * @param MappedObjects $objects The object service with the ZGW mapping
	 * @param array $params The search filters
	 *
	 * @return array<array> The matching objects, or none
	 */
	private function searchRowsOrNone(MappedObjects $objects, array $params): array {
		try {
			return $this->searchRows(objects: $objects, params: $params);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'ZTC enrichment: the search of '.$objects->mapping['sourceSchema'].' failed, so its list stays unenriched: '.$e->getMessage()
			);
			return [];
		}
	}//end searchRowsOrNone()

	/**
	 * Search a mapping's objects and return their ids, skipping rows without one.
	 *
	 * @param MappedObjects $objects The object service with the ZGW mapping
	 * @param array $params The search filters
	 *
	 * @return array<string> The ids
	 */
	private function searchIds(MappedObjects $objects, array $params): array {
		$ids = [];
		foreach ($this->searchRows(objects: $objects, params: $params) as $row) {
			$id = $row['id'] ?? ($row['@self']['id'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end searchIds()
}//end class
