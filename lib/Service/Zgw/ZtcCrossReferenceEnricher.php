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

use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZtcCrossReferenceEnricherInterface;

/**
 * Builds the cross-reference URL lists of ZTC zaaktypen and besluittypen.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class ZtcCrossReferenceEnricher implements ZtcCrossReferenceEnricherInterface {

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
	 * @param ZgwServiceInterface $zgwService Supplies the ObjectService and the ZGW mappings
	 */
	public function __construct(
		private readonly ZgwServiceInterface $zgwService,
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

		$target = new ZtcEnrichmentTarget(objectService: $objectService, uuid: $uuid, baseUrl: $baseUrl);
		if ($resource === 'besluittypen') {
			return $this->enrichBesluittype(data: $data, target: $target);
		}

		if ($resource !== 'zaaktypen') {
			return $data;
		}

		return $this->withListDefaults(data: $this->enrichCaseType(data: $data, target: $target));
	}//end enrich()

	/**
	 * Default every zaaktype list field to [] instead of null.
	 *
	 * @param array $data The enriched zaaktype
	 *
	 * @return array The zaaktype with every list field set
	 */
	private function withListDefaults(array $data): array {
		foreach (self::CASE_TYPE_LIST_FIELDS as $field) {
			if (isset($data[$field]) === false) {
				$data[$field] = [];
			}
		}

		return $data;
	}//end withListDefaults()

	/**
	 * Expand a besluittype's stored documentTypes and caseTypes uuids to URLs.
	 *
	 * @param array               $data   The outbound-mapped besluittype
	 * @param ZtcEnrichmentTarget $target The besluittype's object service, uuid and base URL
	 *
	 * @return array The enriched besluittype
	 */
	private function enrichBesluittype(array $data, ZtcEnrichmentTarget $target): array {
		$objectService = $target->objectService;
		$uuid = $target->uuid;
		$baseUrl = $target->baseUrl;
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
		$ids = $this->storedIds(stored: $stored);
		if (empty($ids) === true) {
			return null;
		}

		return $this->prefixedUrls(ids: $ids, prefix: $prefix);
	}//end uuidListUrls()

	/**
	 * The uuids a stored list holds: a JSON string decoded, an array as it is, anything else none.
	 *
	 * @param mixed $stored The stored list
	 *
	 * @return mixed The decoded list
	 */
	private function storedIds(mixed $stored): mixed {
		if (is_string($stored) === true && $stored !== '') {
			return json_decode($stored, true);
		}

		if (is_array($stored) === true) {
			return $stored;
		}

		return [];
	}//end storedIds()

	/**
	 * The URL of every non-empty string uuid in the list.
	 *
	 * @param mixed  $ids    The decoded list
	 * @param string $prefix The URL each uuid is appended to
	 *
	 * @return array<string> The URLs
	 */
	private function prefixedUrls(mixed $ids, string $prefix): array {
		$urls = [];
		foreach ($ids as $id) {
			if (is_string($id) === true && $id !== '') {
				$urls[] = $prefix.$id;
			}
		}

		return $urls;
	}//end prefixedUrls()

	/**
	 * Fill a zaaktype's lists: deelzaaktypen, besluittypen, gerelateerdeZaaktypen,
	 * informatieobjecttypen and the four sub-resource lists.
	 *
	 * @param array               $data   The outbound-mapped zaaktype
	 * @param ZtcEnrichmentTarget $target The zaaktype's object service, uuid and base URL
	 *
	 * @return array The enriched zaaktype
	 */
	private function enrichCaseType(array $data, ZtcEnrichmentTarget $target): array {
		$ztMapping  = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktypen');
		$objectData = null;
		if ($ztMapping !== null) {
			$stored = $this->storedCaseTypeLists(target: $target, ztMapping: $ztMapping);
			$objectData = $stored['objectData'];
			$data = array_merge($data, $stored['lists']);
		}

		$data = $this->withRelatedCaseTypes(data: $data, objectData: $objectData, target: $target, ztMapping: $ztMapping);

		$data = $this->withDocumentAndDecisionTypes(data: $data, target: $target);

		return $this->withSubResources(data: $data, target: $target);
	}//end enrichCaseType()

	/**
	 * Fill a zaaktype's informatieobjecttypen and, when its stored decisionTypes gave none, its
	 * besluittypen from the besluittype records that point at it.
	 *
	 * @param array               $data   The zaaktype being enriched
	 * @param ZtcEnrichmentTarget $target The zaaktype's object service, uuid and base URL
	 *
	 * @return array The zaaktype with both lists filled where found
	 */
	private function withDocumentAndDecisionTypes(array $data, ZtcEnrichmentTarget $target): array {
		$iotUrls = $this->lookup->caseTypeDocumentTypeUrls(objectService: $target->objectService, uuid: $target->uuid, baseUrl: $target->baseUrl);
		if (empty($iotUrls) === false) {
			$data['informatieobjecttypen'] = $iotUrls;
		}

		return $this->withDecisionTypeFallback(data: $data, target: $target);
	}//end withDocumentAndDecisionTypes()

	/**
	 * Fill a zaaktype's besluittypen from the besluittype records that point at it, when its
	 * stored decisionTypes gave none.
	 *
	 * @param array               $data   The zaaktype being enriched
	 * @param ZtcEnrichmentTarget $target The zaaktype's object service, uuid and base URL
	 *
	 * @return array The zaaktype with its besluittypen filled where found
	 */
	private function withDecisionTypeFallback(array $data, ZtcEnrichmentTarget $target): array {
		// Fallback: besluittypen from BT records with caseType = this uuid, only when the
		// stored decisionTypes gave none.
		if (empty($data['besluittypen'] ?? null) === true) {
			$btUrls = $this->lookup->urlsOfObjectsFor(
				objectService: $target->objectService,
				resource: 'besluittypen',
				uuid: $target->uuid,
				baseUrl: $target->baseUrl
			);
			if (empty($btUrls) === false) {
				$data['besluittypen'] = $btUrls;
			}
		}

		return $data;
	}//end withDecisionTypeFallback()

	/**
	 * Fill a zaaktype's four sub-resource lists from the records that point at it.
	 *
	 * @param array               $data   The zaaktype being enriched
	 * @param ZtcEnrichmentTarget $target The zaaktype's object service, uuid and base URL
	 *
	 * @return array The zaaktype with its sub-resource lists filled where found
	 */
	private function withSubResources(array $data, ZtcEnrichmentTarget $target): array {
		$objectService = $target->objectService;
		$uuid = $target->uuid;
		$baseUrl = $target->baseUrl;
		foreach (self::CASE_TYPE_SUB_RESOURCES as $resourceName) {
			$urls = $this->lookup->urlsOfObjectsFor(objectService: $objectService, resource: $resourceName, uuid: $uuid, baseUrl: $baseUrl);
			if (empty($urls) === false) {
				$data[$resourceName] = $urls;
			}
		}

		return $data;
	}//end withSubResources()

	/**
	 * Fill gerelateerdeZaaktypen.
	 *
	 * Read from the raw object's relatedCaseTypes (a JSON-encoded string), since Twig outbound
	 * mapping cannot handle an array of objects; fall back to what the mapping produced.
	 *
	 * @param array               $data       The outbound-mapped zaaktype
	 * @param array|null          $objectData The stored zaaktype, null when it could not be read
	 * @param ZtcEnrichmentTarget $target     The zaaktype's object service and base URL
	 * @param array|null          $ztMapping  The zaaktypen mapping
	 *
	 * @return array The zaaktype with its relations expanded
	 */
	private function withRelatedCaseTypes(array $data, ?array $objectData, ZtcEnrichmentTarget $target, ?array $ztMapping): array {
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
			objectService: $target->objectService,
			ztMapping: $ztMapping,
			baseUrl: $target->baseUrl
		);

		return $data;
	}//end withRelatedCaseTypes()

	/**
	 * Read the zaaktype and build the lists it stores itself: deelzaaktypen (each expanded to
	 * every zaaktype with the same identifier) and besluittypen.
	 *
	 * @param ZtcEnrichmentTarget $target    The zaaktype's object service, uuid and base URL
	 * @param array               $ztMapping The zaaktypen mapping
	 *
	 * @return array{objectData: array|null, lists: array} The stored zaaktype (null when it could
	 *                                                     not be read) and the lists it gives
	 */
	private function storedCaseTypeLists(ZtcEnrichmentTarget $target, array $ztMapping): array {
		$objectData = null;
		$lists = [];
		try {
			$objectData = $this->lookup->findIn(objectService: $target->objectService, mapping: $ztMapping, id: $target->uuid);

			$lists = $this->deelzaaktypenOf(objectData: $objectData, target: $target, ztMapping: $ztMapping);
			$lists += $this->besluittypenOf(objectData: $objectData, baseUrl: $target->baseUrl);
		} catch (\Throwable $e) {
			// Proceed without deelzaaktypen enrichment.
			return ['objectData' => $objectData, 'lists' => $lists];
		}

		return ['objectData' => $objectData, 'lists' => $lists];
	}//end storedCaseTypeLists()

	/**
	 * The deelzaaktypen list a stored zaaktype gives, when it stores any subCaseTypes.
	 *
	 * @param array               $objectData The stored zaaktype
	 * @param ZtcEnrichmentTarget $target     The zaaktype's object service and base URL
	 * @param array               $ztMapping  The zaaktypen mapping
	 *
	 * @return array{deelzaaktypen?: array<string>} The list, or nothing
	 */
	private function deelzaaktypenOf(array $objectData, ZtcEnrichmentTarget $target, array $ztMapping): array {
		$subCases = $objectData['subCaseTypes'] ?? [];
		if (is_array($subCases) === true && empty($subCases) === false) {
			return ['deelzaaktypen' => $this->subCaseTypeUrls(subCases: $subCases, target: $target, ztMapping: $ztMapping)];
		}

		return [];
	}//end deelzaaktypenOf()

	/**
	 * The besluittypen list a stored zaaktype gives, when it stores any decisionTypes.
	 *
	 * @param array  $objectData The stored zaaktype
	 * @param string $baseUrl    The Catalogi API base URL
	 *
	 * @return array{besluittypen?: array<string>|null} The list, or nothing
	 */
	private function besluittypenOf(array $objectData, string $baseUrl): array {
		$decTypes = $objectData['decisionTypes'] ?? [];
		if (is_array($decTypes) === true && empty($decTypes) === false) {
			return ['besluittypen' => $this->uuidListUrls(stored: $decTypes, prefix: $baseUrl.'/besluittypen/')];
		}

		return [];
	}//end besluittypenOf()

	/**
	 * Expand each stored deelzaaktype uuid to every zaaktype with the same identifier.
	 *
	 * @param array               $subCases  The stored subCaseTypes uuids
	 * @param ZtcEnrichmentTarget $target    The zaaktype's object service and base URL
	 * @param array               $ztMapping The zaaktypen mapping
	 *
	 * @return array<string> The unique deelzaaktype URLs
	 */
	private function subCaseTypeUrls(array $subCases, ZtcEnrichmentTarget $target, array $ztMapping): array {
		$objectService = $target->objectService;
		$baseUrl = $target->baseUrl;
		$urls = [];
		foreach ($subCases as $ztUuid) {
			if (is_string($ztUuid) === true && $ztUuid !== '') {
				$same = $this->lookup->sameIdentifierUrls(objectService: $objectService, ztMapping: $ztMapping, ztRef: $ztUuid, baseUrl: $baseUrl);
				array_push($urls, ...$same);
			}
		}

		return array_values(array_unique($urls));
	}//end subCaseTypeUrls()

}//end class
