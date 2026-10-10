<?php

/**
 * Dossiq ZTC cross-reference enricher.
 *
 * A zaaktype or besluittype read from the Catalogi API carries lists of URLs to the types it
 * relates to (informatieobjecttypen, besluittypen, deelzaaktypen, gerelateerdeZaaktypen and the
 * sub-resources). OpenRegister stores some of those as uuids on the object and some only on the
 * other side of the relation, so the read path builds the lists; ZtcUrlValidityFilter then drops
 * the URLs that do not point at a published, currently valid type. Split out of ZtcController
 * (method-decomposition).
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

/**
 * Builds the cross-reference URL lists of ZTC zaaktypen and besluittypen.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcCrossReferenceEnricher {

	/**
	 * The Catalogi API.
	 */
	private const ZGW_API = 'catalogi';

	/**
	 * The zaaktype list fields that default to [] instead of null.
	 */
	private const CASE_TYPE_LIST_FIELDS = [
		'deelzaaktypen',
		'gerelateerdeZaaktypen',
		'besluittypen',
		'informatieobjecttypen',
		'eigenschappen',
		'statustypen',
		'resultaattypen',
		'roltypen',
	];

	/**
	 * The zaaktype sub-resources found by their caseType.
	 */
	private const CASE_TYPE_SUB_RESOURCES = ['eigenschappen', 'statustypen', 'resultaattypen', 'roltypen'];

	/**
	 * The lookups behind the lists.
	 *
	 * @var ZtcRelatedTypeLookup
	 */
	private readonly ZtcRelatedTypeLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService Supplies the ObjectService and the ZGW mappings
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
	) {
		$this->lookup = new ZtcRelatedTypeLookup(zgwService: $zgwService);
	}//end __construct()

	/**
	 * Fill a zaaktype's or besluittype's cross-reference lists.
	 *
	 * @param string $resource The ZGW resource (`zaaktypen`, `besluittypen`; others pass through)
	 * @param array $data The outbound-mapped object
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array The enriched object
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function enrich(string $resource, array $data, string $baseUrl): array {
		$objectService = $this->zgwService->getObjectService();
		$uuid = $data['uuid'] ?? '';
		if ($objectService === null || $uuid === '') {
			return $data;
		}

		if ($resource === 'besluittypen') {
			return $this->enrichBesluittype(data: $data, baseUrl: $baseUrl, objectService: $objectService, uuid: $uuid);
		}

		if ($resource !== 'zaaktypen') {
			return $data;
		}

		$data = $this->enrichCaseType(data: $data, baseUrl: $baseUrl, objectService: $objectService, uuid: $uuid);

		// Ensure array fields default to [] instead of null.
		foreach (self::CASE_TYPE_LIST_FIELDS as $field) {
			if (isset($data[$field]) === false) {
				$data[$field] = [];
			}
		}

		return $data;
	}//end enrich()

	/**
	 * Expand a besluittype's stored documentTypes and caseTypes uuids to URLs.
	 *
	 * @param array $data The outbound-mapped besluittype
	 * @param string $baseUrl The Catalogi API base URL
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The besluittype uuid
	 *
	 * @return array The enriched besluittype
	 */
	private function enrichBesluittype(array $data, string $baseUrl, object $objectService, string $uuid): array {
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'besluittypen');
		if ($mappingConfig === null) {
			return $data;
		}

		try {
			$objectData = $this->lookup->findIn(objectService: $objectService, mapping: $mappingConfig, id: $uuid);

			$iotUrls = $this->uuidListUrls(stored: $objectData['documentTypes'] ?? '', prefix: $baseUrl.'/informatieobjecttypen/');
			if ($iotUrls !== null) {
				$data['informatieobjecttypen'] = $iotUrls;
			}

			$caseTypeUrls = $this->uuidListUrls(stored: $objectData['caseTypes'] ?? '', prefix: $baseUrl.'/zaaktypen/');
			if ($caseTypeUrls !== null) {
				$data['zaaktypen'] = $caseTypeUrls;
			}
		} catch (\Throwable $e) {
			// Proceed without enrichment.
			return $data;
		}

		return $data;
	}//end enrichBesluittype()

	/**
	 * Turn a stored uuid list (array or JSON string) into URLs.
	 *
	 * @param mixed $stored The stored list
	 * @param string $prefix The URL each uuid is appended to
	 *
	 * @return array<string>|null The URLs, or null when the stored list is empty
	 */
	private function uuidListUrls(mixed $stored, string $prefix): ?array {
		$ids = [];
		if (is_string($stored) === true && $stored !== '') {
			$ids = json_decode($stored, true);
		} elseif (is_array($stored) === true) {
			$ids = $stored;
		}

		if (empty($ids) === true) {
			return null;
		}

		$urls = [];
		foreach ($ids as $id) {
			if (is_string($id) === true && $id !== '') {
				$urls[] = $prefix.$id;
			}
		}

		return $urls;
	}//end uuidListUrls()

	/**
	 * Fill a zaaktype's lists: deelzaaktypen, besluittypen, gerelateerdeZaaktypen,
	 * informatieobjecttypen and the four sub-resource lists.
	 *
	 * @param array $data The outbound-mapped zaaktype
	 * @param string $baseUrl The Catalogi API base URL
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The zaaktype uuid
	 *
	 * @return array The enriched zaaktype
	 */
	private function enrichCaseType(array $data, string $baseUrl, object $objectService, string $uuid): array {
		$ztMapping  = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktypen');
		$objectData = null;
		if ($ztMapping !== null) {
			$stored = $this->storedCaseTypeLists(objectService: $objectService, ztMapping: $ztMapping, uuid: $uuid, baseUrl: $baseUrl);
			$objectData = $stored['objectData'];
			$data = array_merge($data, $stored['lists']);
		}

		$data = $this->withRelatedCaseTypes(data: $data, objectData: $objectData, objectService: $objectService, ztMapping: $ztMapping, baseUrl: $baseUrl);

		$iotUrls = $this->lookup->caseTypeDocumentTypeUrls(objectService: $objectService, uuid: $uuid, baseUrl: $baseUrl);
		if (empty($iotUrls) === false) {
			$data['informatieobjecttypen'] = $iotUrls;
		}

		// Fallback: besluittypen from BT records with caseType = this uuid, only when the
		// stored decisionTypes gave none.
		if (empty($data['besluittypen'] ?? null) === true) {
			$btUrls = $this->lookup->urlsOfObjectsFor(objectService: $objectService, resource: 'besluittypen', uuid: $uuid, baseUrl: $baseUrl);
			if (empty($btUrls) === false) {
				$data['besluittypen'] = $btUrls;
			}
		}

		foreach (self::CASE_TYPE_SUB_RESOURCES as $resourceName) {
			$urls = $this->lookup->urlsOfObjectsFor(objectService: $objectService, resource: $resourceName, uuid: $uuid, baseUrl: $baseUrl);
			if (empty($urls) === false) {
				$data[$resourceName] = $urls;
			}
		}

		return $data;
	}//end enrichCaseType()

	/**
	 * Fill gerelateerdeZaaktypen.
	 *
	 * Read from the raw object's relatedCaseTypes (a JSON-encoded string), since Twig outbound
	 * mapping cannot handle an array of objects; fall back to what the mapping produced.
	 *
	 * @param array $data The outbound-mapped zaaktype
	 * @param array|null $objectData The stored zaaktype, null when it could not be read
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array|null $ztMapping The zaaktypen mapping
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array The zaaktype with its relations expanded
	 */
	private function withRelatedCaseTypes(array $data, ?array $objectData, object $objectService, ?array $ztMapping, string $baseUrl): array {
		$relatedRaw = null;
		if ($objectData !== null) {
			$relatedRaw = $objectData['relatedCaseTypes'] ?? null;
		}

		$relatedRaw ??= $data['gerelateerdeZaaktypen'] ?? null;
		if (is_string($relatedRaw) === true) {
			$relatedRaw = json_decode($relatedRaw, true);
		}

		if (is_array($relatedRaw) === false || empty($relatedRaw) === true || $ztMapping === null) {
			return $data;
		}

		$data['gerelateerdeZaaktypen'] = $this->lookup->expandRelatedCaseTypes(
			related: $relatedRaw,
			objectService: $objectService,
			ztMapping: $ztMapping,
			baseUrl: $baseUrl
		);

		return $data;
	}//end withRelatedCaseTypes()

	/**
	 * Read the zaaktype and build the lists it stores itself: deelzaaktypen (each expanded to
	 * every zaaktype with the same identifier) and besluittypen.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $ztMapping The zaaktypen mapping
	 * @param string $uuid The zaaktype uuid
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array{objectData: array|null, lists: array} The stored zaaktype (null when it could
	 *                                                     not be read) and the lists it gives
	 */
	private function storedCaseTypeLists(object $objectService, array $ztMapping, string $uuid, string $baseUrl): array {
		$objectData = null;
		$lists = [];
		try {
			$objectData = $this->lookup->findIn(objectService: $objectService, mapping: $ztMapping, id: $uuid);

			$subCases = $objectData['subCaseTypes'] ?? [];
			if (is_array($subCases) === true && empty($subCases) === false) {
				$urls = [];
				foreach ($subCases as $ztUuid) {
					if (is_string($ztUuid) === true && $ztUuid !== '') {
						$same = $this->lookup->sameIdentifierUrls(objectService: $objectService, ztMapping: $ztMapping, ztRef: $ztUuid, baseUrl: $baseUrl);
						array_push($urls, ...$same);
					}
				}

				$lists['deelzaaktypen'] = array_values(array_unique($urls));
			}

			$decTypes = $objectData['decisionTypes'] ?? [];
			if (is_array($decTypes) === true && empty($decTypes) === false) {
				$lists['besluittypen'] = $this->uuidListUrls(stored: $decTypes, prefix: $baseUrl.'/besluittypen/');
			}
		} catch (\Throwable $e) {
			// Proceed without deelzaaktypen enrichment.
			return ['objectData' => $objectData, 'lists' => $lists];
		}

		return ['objectData' => $objectData, 'lists' => $lists];
	}//end storedCaseTypeLists()

}//end class
