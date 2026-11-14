<?php

/**
 * Dossiq ZTC type name resolver
 *
 * The ZGW standard lets a client name an informatieobjecttype by its
 * omschrijving when it links one to a zaaktype (ztc-010m). This turns that
 * name into the type's uuid. Split out of ZtcController (method-decomposition 8i).
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
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Support\NormalisesObjectRows;

/**
 * Turns an informatieobjecttype named by its omschrijving into its uuid, in the request body.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZtcTypeNameResolver {
	use NormalisesObjectRows;

	/**
	 * Constructor.
	 *
	 * @param ZgwServiceInterface $zgwService The shared ZGW service.
	 */
	public function __construct(
		private readonly ZgwServiceInterface $zgwService,
	) {
	}//end __construct()

	/**
	 * Resolve informatieobjecttype by omschrijving when not a UUID/URL (ztc-010m).
	 *
	 * The ZGW standard allows referencing an IOT by omschrijving in ZIOT creation.
	 * This method looks up the IOT by omschrijving and replaces it with its UUID.
	 *
	 * @param array $body The request body (modified in-place via cached body)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function resolveInformatieobjecttype(array $body): void {
		$iotValue = $body['informatieobjecttype'] ?? '';
		if ($iotValue === '') {
			return;
		}

		if ($this->isUuidOrUrl(value: $iotValue) === true) {
			return;
		}

		$lookup = $this->iotLookup();
		if ($lookup === null) {
			return;
		}

		[$objectService, $iotMapping] = $lookup;

		try {
			$iotUuid = $this->findIotUuidByOmschrijving(objectService: $objectService, iotMapping: $iotMapping, omschrijving: $iotValue);
			if ($iotUuid !== '') {
				$this->zgwService->updateCachedBodyField('informatieobjecttype', $iotUuid);
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->debug(
				'ztc-010m: Failed to resolve IOT by omschrijving: ' . $e->getMessage()
			);
		}//end try
	}//end resolveInformatieobjecttype()

	/**
	 * Whether a reference is already a UUID or URL, so needs no resolution.
	 *
	 * @param string $value The informatieobjecttype reference.
	 *
	 * @return bool True for a UUID or URL.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function isUuidOrUrl(string $value): bool {
		// Already a UUID or URL — no resolution needed.
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
			return true;
		}

		return filter_var($value, FILTER_VALIDATE_URL) !== false;
	}//end isUuidOrUrl()

	/**
	 * The object service and informatieobjecttypen mapping to look an omschrijving up in, or null.
	 *
	 * @return array{0: object, 1: array}|null The object service and the mapping.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function iotLookup(): ?array {
		// Try to look up by omschrijving (internal field: name).
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$iotMapping = $this->zgwService->loadMappingConfig('catalogi', 'informatieobjecttypen');
		if ($iotMapping === null) {
			return null;
		}

		return [$objectService, $iotMapping];
	}//end iotLookup()

	/**
	 * The uuid of the informatieobjecttype with this omschrijving, by name and else by full text.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array $iotMapping The informatieobjecttypen mapping.
	 * @param string $omschrijving The omschrijving.
	 *
	 * @return string The uuid, or '' when nothing matches.
	 */
	private function findIotUuidByOmschrijving(object $objectService, array $iotMapping, string $omschrijving): string {
		$result = ['total' => 0];
		foreach ([['name' => $omschrijving, '_limit' => 1], ['_search' => $omschrijving, '_limit' => 1]] as $params) {
			$query = $objectService->buildSearchQuery(
				requestParams: $params,
				register: $iotMapping['sourceRegister'],
				schema: $iotMapping['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(
				query: $query,
				_rbac: false,
				_multitenancy: false
			);
			if (($result['total'] ?? 0) !== 0) {
				break;
			}
		}

		if (($result['total'] ?? 0) <= 0) {
			return '';
		}

		$iotData = $this->objectToArray(row: $result['results'][0]);

		return $iotData['id'] ?? ($iotData['@self']['id'] ?? '');
	}//end findIotUuidByOmschrijving()
}//end class
