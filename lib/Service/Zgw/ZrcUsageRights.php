<?php

/**
 * ZRC usage rights on a closing zaak
 *
 * The indicatieGebruiksrecht of the documents linked to a zaak: settled when
 * the zaak closes (zrc-007b) and checked before an eindstatus is accepted
 * (zrc-007q). Moved out of ZrcController with ZrcStatusEffects
 * (method-decomposition slice 6c).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Settles and checks indicatieGebruiksrecht on a zaak's documents.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZrcUsageRights {
	use NormalisesObjectRows;

	/**
	 * A UUID anywhere in a URL or bare value.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService The shared ZGW service (object store, mappings, logger).
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
	) {
	}//end __construct()

	/**
	 * The zrc-007q refusal for the first linked document without indicatieGebruiksrecht.
	 *
	 * @param string $caseUuid The zaak.
	 *
	 * @return array|null The 400 body, or null when every document has one.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function firstUnsetRefusal(string $caseUuid): ?array {
		$docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
		if ($docConfig === null) {
			return null;
		}

		$zios = $this->ziosOf(caseUuid: $caseUuid);
		if ($zios === null) {
			return null;
		}

		foreach ($zios as $zioObj) {
			$docUuid = $this->documentUuidOf(zio: $zioObj);
			if ($docUuid === null) {
				continue;
			}

			$docData = $this->objectToArray(row: $this->find(uuid: $docUuid, config: $docConfig));
			if ($this->usageRightsOf(docData: $docData) === null) {
				$detail = 'Zaak kan niet afgesloten worden: niet alle informatieobjecten hebben indicatieGebruiksrecht gezet.';
				return [
					'detail' => $detail,
					'code' => 'indicatiegebruiksrecht-unset',
					'invalidParams' => [
						[
							'name' => 'nonFieldErrors',
							'code' => 'indicatiegebruiksrecht-unset',
							'reason' => $detail,
						],
					],
				];
			}
		}//end foreach

		return null;
	}//end firstUnsetRefusal()

	/**
	 * Set indicatieGebruiksrecht on every document linked to the zaak that has none (zrc-007b).
	 *
	 * @param string $zaakUuid The zaak.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function settleOnClose(string $zaakUuid): void {
		try {
			$docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
			if ($docConfig === null) {
				return;
			}

			$zios = $this->ziosOf(caseUuid: $zaakUuid);
			if ($zios === null) {
				return;
			}

			foreach ($zios as $zioObj) {
				$docUuid = $this->documentUuidOf(zio: $zioObj);
				if ($docUuid !== null) {
					$this->settleUsageRights(docUuid: $docUuid, docConfig: $docConfig);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-007b: Failed to set indicatieGebruiksrecht: ' . $e->getMessage()
			);
		}
	}//end settleOnClose()

	/**
	 * Give one document an indicatieGebruiksrecht when it has none.
	 *
	 * 🔴 "NO GEBRUIKSRECHTEN FOUND" AND "COULD NOT LOOK" ARE NOT THE SAME
	 * ANSWER, and the write below states a usage-rights position either way.
	 * A gebruiksrechten mapping whose scope OpenRegister cannot resolve answers
	 * `total: 0` with no error ({@see ZgwSearchScope}), so a `false` default
	 * would record "no usage restrictions" on a document that may carry
	 * several, and zrc-007q would then read that `false` as set and let the
	 * zaak close. When the lookup cannot run the indication stays UNSET, which
	 * is what zrc-007q refuses on.
	 *
	 * @param string $docUuid The document.
	 * @param array $docConfig The document mapping.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named constructor on a value object.
	 */
	private function settleUsageRights(string $docUuid, array $docConfig): void {
		try {
			$docData = $this->objectToArray(row: $this->find(uuid: $docUuid, config: $docConfig));
			if ($this->usageRightsOf(docData: $docData) !== null) {
				return;
			}

			$grScope = ZgwSearchScope::fromMapping(mappingConfig: $this->zgwService->getZgwMappingService()->getMapping('gebruiksrechten'));
			if ($grScope === null) {
				$this->zgwService->getLogger()->warning(
					'zrc-007b: gebruiksrechten mapping has no searchable register/schema, '
					. 'leaving indicatieGebruiksrecht unset for doc ' . $docUuid
				);
				return;
			}

			$hasGr = $this->hasUsageRights(docUuid: $docUuid, grScope: $grScope);
			if ($hasGr === null) {
				return;
			}

			unset($docData['@self'], $docData['organisation']);
			$docData['usageRightsIndication'] = $hasGr;
			$docData['id'] = $docUuid;
			$this->zgwService->getObjectService()->saveObject(
				register: $docConfig['sourceRegister'],
				schema: $docConfig['sourceSchema'],
				object: $docData,
				uuid: $docUuid
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->debug(
				'zrc-007b: Could not update indicatieGebruiksrecht for doc ' . $docUuid . ': ' . $e->getMessage()
			);
		}//end try
	}//end settleUsageRights()

	/**
	 * Whether gebruiksrechten exist for a document; null when the lookup failed.
	 *
	 * @param string $docUuid The document.
	 * @param ZgwSearchScope $grScope The gebruiksrechten scope.
	 *
	 * @return bool|null
	 */
	private function hasUsageRights(string $docUuid, ZgwSearchScope $grScope): ?bool {
		// Unknown (null) unless the lookup answers: "could not look" is not "none found".
		$hasGr = null;
		try {
			$objects = $this->zgwService->getObjectService();
			$grQuery = $objects->buildSearchQuery(
				requestParams: ['document' => $docUuid, '_limit' => 1],
				register: $grScope->register,
				schema: $grScope->schema
			);
			$grResult = $objects->searchObjectsPaginated(query: $grQuery);
			$hasGr = empty($grResult['results'] ?? []) === false;
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-007b: gebruiksrechten lookup failed, leaving indicatieGebruiksrecht '
				. 'unset for doc ' . $docUuid . ': ' . $e->getMessage()
			);
		}

		return $hasGr;
	}//end hasUsageRights()

	/**
	 * The zaak's ZaakInformatieObjecten (first 100), or null when ZIOs are not mapped.
	 *
	 * @param string $caseUuid The zaak.
	 *
	 * @return array|null
	 */
	private function ziosOf(string $caseUuid): ?array {
		$zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
		if ($zioConfig === null) {
			return null;
		}

		$objects = $this->zgwService->getObjectService();
		$query = $objects->buildSearchQuery(
			requestParams: ['case' => $caseUuid, '_limit' => 100],
			register: $zioConfig['sourceRegister'],
			schema: $zioConfig['sourceSchema']
		);

		return ($objects->searchObjectsPaginated(query: $query)['results'] ?? []);
	}//end ziosOf()

	/**
	 * The document uuid a ZIO points at.
	 *
	 * @param mixed $zio The ZIO row.
	 *
	 * @return string|null
	 */
	private function documentUuidOf(mixed $zio): ?string {
		$zioData = $this->objectToArray(row: $zio);
		return $this->uuidOf(value: (string)($zioData['document'] ?? ($zioData['informatieobject'] ?? '')));
	}//end documentUuidOf()

	/**
	 * A document's indicatieGebruiksrecht, null when unset or empty.
	 *
	 * @param array $docData The document.
	 *
	 * @return mixed
	 */
	private function usageRightsOf(array $docData): mixed {
		$indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));
		if ($indGr === '') {
			return null;
		}

		return $indGr;
	}//end usageRightsOf()

	/**
	 * Find one object of a mapped schema.
	 *
	 * @param string $uuid The object.
	 * @param array $config The mapping.
	 *
	 * @return mixed The row, or null.
	 */
	private function find(string $uuid, array $config): mixed {
		return $this->zgwService->getObjectService()->find(
			$uuid,
			register: $config['sourceRegister'],
			schema: $config['sourceSchema']
		);
	}//end find()

	/**
	 * The first UUID in a value, or null.
	 *
	 * @param string $value A URL or bare uuid.
	 *
	 * @return string|null
	 */
	private function uuidOf(string $value): ?string {
		if ($value === '' || preg_match(self::UUID_PATTERN, $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end uuidOf()
}//end class
