<?php

/**
 * What the Besluiten API keeps in step in the other registers
 *
 * A besluit that names a zaak gets a zaakbesluit in the Zaken register
 * (brc-006). A besluitinformatieobject gets an objectinformatieobject in the
 * Documenten register, which goes again with the link or with the besluit
 * (brc-005, brc-009). Each is best effort: a failure is logged, never thrown.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Support\NormalisesObjectRows;

/**
 * The cross-register side effects of the Besluiten API.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrcCrossRegisterSync {
	use NormalisesObjectRows;

	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService The shared ZGW service.
	 * @param SettingsService $settingsService The settings service.
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Sync a zaak-besluit relation to ZRC (brc-006).
	 *
	 * Creates a "zaakbesluit" record linking the zaak to the besluit
	 * in the ZRC register.
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $decisionUrl The besluit URL
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function syncCaseDecisionToZrc(string $caseUrl, string $decisionUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		// Look for a zaakbesluit mapping/schema.
		$zbSchema = $this->settingsService->getConfigValue(key: 'case_decision_schema');
		if ($zbSchema === '') {
			$this->zgwService->getLogger()->debug(
				'brc-006: case_decision_schema not configured, skipping zaakbesluit sync'
			);
			return;
		}

		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		$zaakUuid = '';
		if (preg_match($uuidPattern, $caseUrl, $match) === 1) {
			$zaakUuid = $match[1];
		}

		if ($zaakUuid === '') {
			return;
		}

		// Use the zaken mapping config for register.
		$casesConfig = $this->zgwService->loadMappingConfig('zaken', 'zaken');
		$register = $casesConfig['sourceRegister'] ?? '';
		if ($register === '') {
			return;
		}

		try {
			$zbData = [
				'case' => $zaakUuid,
				'decision' => $decisionUrl,
			];

			$objectService->saveObject(
				register: $register,
				schema: $zbSchema,
				object: $zbData
			);

			$this->zgwService->getLogger()->info(
				'brc-006: Created zaakbesluit for zaak=' . $zaakUuid . ' besluit=' . $decisionUrl
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'brc-006: Failed to create zaakbesluit: ' . $e->getMessage()
			);
		}
	}//end syncZaakBesluitToZrc()

	/**
	 * Create an ObjectInformatieObject in the DRC register (brc-005a).
	 *
	 * @param string $decisionUrl The besluit URL (full ZGW URL)
	 * @param string $ioUrl The informatieobject URL (full ZGW URL)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function createOioInDrc(string $decisionUrl, string $ioUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return;
		}

		try {
			$oioData = [
				'document' => $ioUrl,
				'object' => $decisionUrl,
				'objectType' => 'decision',
			];

			$objectService->saveObject(
				register: $oioMappingConfig['sourceRegister'],
				schema: $oioMappingConfig['sourceSchema'],
				object: $oioData
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'brc-005a: Failed to create OIO in DRC: ' . $e->getMessage()
			);
		}
	}//end createOioInDrc()

	/**
	 * Delete ObjectInformatieObjecten from DRC for a given besluit (brc-005b/009).
	 *
	 * @param string $decisionUrl The besluit URL to match OIOs against
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function deleteOiosForDecision(string $decisionUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return;
		}

		// An unsearchable scope answers with an empty page and no error
		// ({@see ZgwSearchScope}), so the besluit goes and every OIO that
		// pointed at it survives as an orphan. Name it instead.
		$oioScope = ZgwSearchScope::fromMapping(mappingConfig: $oioMappingConfig);
		if ($oioScope === null) {
			$this->zgwService->getLogger()->error(
				'brc-005b: objectinformatieobject mapping has no searchable register/schema, '
				. 'so the OIOs of ' . $decisionUrl . ' are being left behind as orphans'
			);
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: ['object' => $decisionUrl],
				register: $oioScope->register,
				schema: $oioScope->schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $oio) {
				$oioData = $this->objectToArray(row: $oio);

				$oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
				if ($oioUuid !== '') {
					$objectService->deleteObject(
						uuid: $oioUuid,
						_rbac: false,
						_multitenancy: false
					);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'BRC: Failed to delete OIOs for besluit: ' . $e->getMessage()
			);
		}//end try
	}//end deleteOiosForBesluit()

	/**
	 * Delete an OIO from DRC matching a specific besluit and informatieobject (brc-005b).
	 *
	 * @param string $decisionUrl The besluit URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function deleteOioByDecisionAndIo(string $decisionUrl, string $ioUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return;
		}

		$oioScope = ZgwSearchScope::fromMapping(mappingConfig: $oioMappingConfig);
		if ($oioScope === null) {
			$this->zgwService->getLogger()->error(
				'brc-005b: objectinformatieobject mapping has no searchable register/schema, '
				. 'so the OIO for ' . $ioUrl . ' is being left behind as an orphan'
			);
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: [
					'object' => $decisionUrl,
					'document' => $ioUrl,
				],
				register: $oioScope->register,
				schema: $oioScope->schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $oio) {
				$oioData = $this->objectToArray(row: $oio);

				$oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
				if ($oioUuid !== '') {
					$objectService->deleteObject(
						uuid: $oioUuid,
						_rbac: false,
						_multitenancy: false
					);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'brc-005b: Failed to delete OIO: ' . $e->getMessage()
			);
		}//end try
	}//end deleteOioByBesluitAndIo()
}//end class
