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

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
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
	 * @param ZgwServiceInterface $zgwService The shared ZGW service.
	 * @param SettingsServiceInterface $settingsService The settings service.
	 */
	public function __construct(
		private readonly ZgwServiceInterface $zgwService,
		private readonly SettingsServiceInterface $settingsService,
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

		$this->syncWithSchema(objectService: $objectService, zbSchema: $zbSchema, caseUrl: $caseUrl, decisionUrl: $decisionUrl);
	}//end syncZaakBesluitToZrc()

	/**
	 * Save the zaakbesluit in the cases register once the case and register are known.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $zbSchema      The case decision schema.
	 * @param string $caseUrl       The zaak URL.
	 * @param string $decisionUrl   The besluit URL.
	 *
	 * @return void
	 */
	private function syncWithSchema(object $objectService, string $zbSchema, string $caseUrl, string $decisionUrl): void {
		$zaakUuid = $this->caseUuidOf(caseUrl: $caseUrl);

		if ($zaakUuid === '') {
			return;
		}

		// Use the zaken mapping config for register.
		$casesConfig = $this->zgwService->loadMappingConfig('zaken', 'zaken');
		$register = $casesConfig['sourceRegister'] ?? '';
		if ($register === '') {
			return;
		}

		$this->saveCaseDecision(
			write: new ZaakBesluitWrite(
				objectService: $objectService,
				register: $register,
				zbSchema: $zbSchema,
				zaakUuid: $zaakUuid,
				decisionUrl: $decisionUrl
			)
		);
	}//end syncWithSchema()

	/**
	 * The zaak UUID inside a zaak URL, or '' when it carries none.
	 *
	 * @param string $caseUrl The zaak URL
	 *
	 * @return string The UUID
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function caseUuidOf(string $caseUrl): string {
		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		$zaakUuid = '';
		if (preg_match($uuidPattern, $caseUrl, $match) === 1) {
			$zaakUuid = $match[1];
		}

		return $zaakUuid;
	}//end caseUuidOf()

	/**
	 * Write the zaakbesluit record; a failure is logged, not thrown (brc-006).
	 *
	 * @param ZaakBesluitWrite $write The object service, register, schema, zaak and besluit
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function saveCaseDecision(ZaakBesluitWrite $write): void {
		$zaakUuid = $write->zaakUuid;
		$decisionUrl = $write->decisionUrl;
		try {
			$zbData = [
				'case' => $zaakUuid,
				'decision' => $decisionUrl,
			];

			$write->objectService->saveObject(
				register: $write->register,
				schema: $write->zbSchema,
				object: $zbData
			);

			$this->zgwService->getLogger()->info(
				'brc-006: Created zaakbesluit for zaak=' . $zaakUuid . ' besluit=' . $decisionUrl
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'brc-006: the besluit was stored, but its zaakbesluit was not: ' . $e->getMessage(),
				['rule' => 'brc-006', 'resource' => 'zaakbesluiten', 'case' => $zaakUuid, 'decision' => $decisionUrl, 'exception' => $e]
			);
		}
	}//end saveCaseDecision()

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
			$this->zgwService->getLogger()->error(
				'brc-005a: the besluitinformatieobject was stored, but its objectinformatieobject in the DRC was not: ' . $e->getMessage(),
				['rule' => 'brc-005a', 'resource' => 'objectinformatieobjecten', 'decision' => $decisionUrl, 'document' => $ioUrl, 'exception' => $e]
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
		$target = $this->oioTarget(orphans: 'the OIOs of ' . $decisionUrl . ' are being left behind as orphans');
		if ($target === null) {
			return;
		}

		[$objectService, $oioScope] = $target;
		try {
			$this->deleteOiosMatching(objectService: $objectService, oioScope: $oioScope, requestParams: ['object' => $decisionUrl]);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'brc-009: the objectinformatieobjecten of a besluit were not all deleted from the DRC: ' . $e->getMessage(),
				['rule' => 'brc-009', 'resource' => 'objectinformatieobjecten', 'decision' => $decisionUrl, 'exception' => $e]
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
		$target = $this->oioTarget(orphans: 'the OIO for ' . $ioUrl . ' is being left behind as an orphan');
		if ($target === null) {
			return;
		}

		[$objectService, $oioScope] = $target;
		try {
			$this->deleteOiosMatching(objectService: $objectService, oioScope: $oioScope, requestParams: ['object' => $decisionUrl, 'document' => $ioUrl]);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'brc-005b: the besluitinformatieobject was deleted, but its objectinformatieobject in the DRC was not: ' . $e->getMessage(),
				['rule' => 'brc-005b', 'resource' => 'objectinformatieobjecten', 'decision' => $decisionUrl, 'document' => $ioUrl, 'exception' => $e]
			);
		}//end try
	}//end deleteOioByBesluitAndIo()

	/**
	 * The object service and OIO scope a DRC delete runs against, or null when there is none.
	 *
	 * @param string $orphans What is left behind when the OIO scope is unsearchable, for the log.
	 *
	 * @return array{0: object, 1: ZgwSearchScope}|null The object service and OIO scope.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function oioTarget(string $orphans): ?array {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$oioScope = $this->oioScope(orphans: $orphans);
		if ($oioScope === null) {
			return null;
		}

		return [$objectService, $oioScope];
	}//end oioTarget()

	/**
	 * The searchable OIO scope, or null (logged when the mapping has no searchable scope).
	 *
	 * @param string $orphans What is left behind when the OIO scope is unsearchable, for the log.
	 *
	 * @return ZgwSearchScope|null The OIO register and schema.
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function oioScope(string $orphans): ?ZgwSearchScope {
		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return null;
		}

		// An unsearchable scope answers with an empty page and no error
		// ({@see ZgwSearchScope}), so the besluit goes and every OIO that
		// pointed at it survives as an orphan. Name it instead.
		$oioScope = ZgwSearchScope::fromMapping(mappingConfig: $oioMappingConfig);
		if ($oioScope === null) {
			$this->zgwService->getLogger()->error(
				'brc-005b: objectinformatieobject mapping has no searchable register/schema, '
				. 'so ' . $orphans
			);
		}

		return $oioScope;
	}//end oioScope()

	/**
	 * Delete every OIO in the DRC that matches the search parameters.
	 *
	 * @param object $objectService OpenRegister's object service
	 * @param ZgwSearchScope $oioScope The OIO register and schema
	 * @param array<string, string> $requestParams The search parameters
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function deleteOiosMatching(object $objectService, ZgwSearchScope $oioScope, array $requestParams): void {
		$query = $objectService->buildSearchQuery(
			requestParams: $requestParams,
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
	}//end deleteOiosMatching()
}//end class
