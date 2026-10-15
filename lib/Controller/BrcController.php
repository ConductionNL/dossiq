<?php

/**
 * Dossiq BRC (Besluiten) Controller
 *
 * Controller for serving ZGW Besluiten API endpoints (besluiten,
 * besluitinformatieobjecten). Implements BRC-specific business rules
 * including cross-register OIO sync, cascade delete, and immutability.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-1
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync;
use OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * BRC (Besluiten) API Controller
 *
 * Handles ZGW Besluiten register resources: besluiten and
 * besluitinformatieobjecten. Implements BRC-specific business rules:
 *
 * - brc-004: PUT/PATCH on besluitinformatieobjecten returns 405
 * - brc-005: Cross-register OIO sync on BIO create/delete
 * - brc-006: Zaak-besluit relation (via ZRC zaakbesluiten endpoint)
 * - brc-009: Cascade delete of BIOs and OIOs when deleting a besluit
 *
 * The besluitinformatieobject routes are `Zgw\BrcDocumentLinkHandler`; what is
 * kept in step in the other registers is `Zgw\BrcCrossRegisterSync`.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class BrcController extends ZgwController {
	/**
	 * The ZGW API identifier for the Besluiten register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'besluiten';

	/**
	 * What the Besluiten API keeps in step in the Zaken and Documenten registers.
	 *
	 * @var BrcCrossRegisterSync
	 */
	private readonly BrcCrossRegisterSync $sync;

	/**
	 * The besluitinformatieobject routes.
	 *
	 * @var BrcDocumentLinkHandler
	 */
	private readonly BrcDocumentLinkHandler $documentLinks;

	/**
	 * Constructor.
	 *
	 * Builds the two collaborators from what it is given, so the container and
	 * the routes still see one Besluiten controller.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwService $zgwService The shared ZGW service.
	 * @param SettingsService $settingsService The settings service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
		SettingsService $settingsService,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->sync = new BrcCrossRegisterSync(zgwService: $zgwService, settingsService: $settingsService);
		$this->documentLinks = new BrcDocumentLinkHandler(zgwService: $zgwService, sync: $this->sync);
	}//end __construct()

	/**
	 * List resources of the given type.
	 *
	 * @param string $resource The ZGW resource name (e.g. besluiten).
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function index(string $resource): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// BesluitInformatieObjecten returns a plain array per ZGW spec.
		if ($resource === 'besluitinformatieobjecten') {
			return $this->documentLinks->index(request: $this->request);
		}

		return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
	}//end index()

	/**
	 * Create a new resource of the given type.
	 *
	 * For besluitinformatieobjecten, also creates an ObjectInformatieObject
	 * in the DRC register (brc-005a).
	 *
	 * @param string $resource The ZGW resource name.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function create(string $resource): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// SB2: Gate creates on besluiten.aanmaken scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.aanmaken') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.aanmaken');
		}

		// For besluitinformatieobjecten: use custom create with OIO sync.
		if ($resource === 'besluitinformatieobjecten') {
			return $this->documentLinks->create(request: $this->request);
		}

		// Brc-006: For besluiten with a zaak, sync zaakbesluit to ZRC after creation.
		if ($resource === 'besluiten') {
			return $this->createDecisionWithCaseSync();
		}

		return $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
	}//end create()

	/**
	 * Retrieve a single resource by UUID.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function show(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
	}//end show()

	/**
	 * Full update (PUT) a resource by UUID.
	 *
	 * For besluitinformatieobjecten, returns 405 Method Not Allowed (brc-004a).
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function update(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// SB2: Gate updates on besluiten.bijwerken scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.bijwerken');
		}

		// Brc-004a: BesluitInformatieObject is immutable — PUT returns 405.
		if ($resource === 'besluitinformatieobjecten') {
			return new JSONResponse(
				data: ['detail' => 'Method not allowed'],
				statusCode: Http::STATUS_METHOD_NOT_ALLOWED
			);
		}

		return $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			false
		);
	}//end update()

	/**
	 * Partial update (PATCH) a resource by UUID.
	 *
	 * For besluitinformatieobjecten, returns 405 Method Not Allowed (brc-004b).
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function patch(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// SB2: Gate patches on besluiten.bijwerken scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.bijwerken');
		}

		// Brc-004b: BesluitInformatieObject is immutable — PATCH returns 405.
		if ($resource === 'besluitinformatieobjecten') {
			return new JSONResponse(
				data: ['detail' => 'Method not allowed'],
				statusCode: Http::STATUS_METHOD_NOT_ALLOWED
			);
		}

		return $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			true
		);
	}//end patch()

	/**
	 * Delete a resource by UUID.
	 *
	 * For besluiten: cascade deletes related BesluitInformatieObjecten
	 * and their OIOs in DRC (brc-009).
	 * For besluitinformatieobjecten: also deletes the OIO in DRC (brc-005b).
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function destroy(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// SB2: Gate destroys on besluiten.verwijderen scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.verwijderen') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.verwijderen');
		}

		// Brc-009: Cascade delete for besluiten.
		if ($resource === 'besluiten') {
			return $this->destroyDecision(uuid: $uuid);
		}

		// Brc-005b: Delete OIO when deleting BIO.
		if ($resource === 'besluitinformatieobjecten') {
			return $this->documentLinks->destroy(request: $this->request, uuid: $uuid);
		}

		return $this->zgwService->handleDestroy(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid
		);
	}//end destroy()

	/**
	 * List audit trail entries for a resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function audittrailIndex(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// Brc-009d: Verify the parent resource exists before returning audit trail.
		$objectService = $this->zgwService->getObjectService();
		if ($objectService !== null) {
			$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
			if ($mappingConfig !== null) {
				try {
					$objectService->find(
						$uuid,
						register: $mappingConfig['sourceRegister'],
						schema: $mappingConfig['sourceSchema']
					);
				} catch (\Throwable $e) {
					return new JSONResponse(
						data: ['detail' => 'Not found'],
						statusCode: Http::STATUS_NOT_FOUND
					);
				}
			}
		}

		return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
	}//end audittrailIndex()

	/**
	 * Retrieve a single audit trail entry for a resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 * @param string $auditUuid The audit trail entry UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function audittrailShow(string $resource, string $uuid, string $auditUuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		return $this->zgwService->handleAudittrailShow(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			$auditUuid
		);
	}//end audittrailShow()

	/**
	 * Create a besluit with zaak-besluit sync to ZRC (brc-006).
	 *
	 * After creating the besluit, if it references a zaak, creates a
	 * zaakbesluit relation in the ZRC register.
	 *
	 * @return JSONResponse
	 */
	private function createDecisionWithCaseSync(): JSONResponse {
		$response = $this->zgwService->handleCreate($this->request, self::ZGW_API, 'besluiten');

		// Brc-006: If created successfully and has a zaak, sync to ZRC.
		if ($response->getStatus() === Http::STATUS_CREATED) {
			$data = $response->getData();
			$caseUrl = '';
			if (is_array($data) === true) {
				$caseUrl = $data['case'] ?? '';

				if ($caseUrl !== '') {
					$decisionUrl = $data['url'] ?? '';
					if ($decisionUrl !== '') {
						$this->sync->syncCaseDecisionToZrc(caseUrl: $caseUrl, decisionUrl: $decisionUrl);
					}
				}
			}
		}

		return $response;
	}//end createBesluitWithZaakSync()

	/**
	 * Delete a besluit with cascade to BIOs and OIOs (brc-009).
	 *
	 * @param string $uuid The besluit UUID to delete
	 *
	 * @return JSONResponse
	 */
	private function destroyDecision(string $uuid): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluiten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		$object = $resource . ' ' . $uuid;
		try {
			$existingObj = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
		} catch (\Throwable $e) {
			return $this->zgwService->storeFailureResponse(error: $e, operation: 'BRC delete besluit', object: $object);
		}

		if ($existingObj === null) {
			return $this->zgwService->notFoundResponse();
		}

		try {
			$existingData = $this->objectToArray(row: $existingObj);

			// Run destroy business rules.
			$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
				zgwApi: self::ZGW_API,
				resource: $resource,
				action: 'destroy',
				body: [],
				existingObject: $existingData
			);
			if ($ruleResult['valid'] === false) {
				return new JSONResponse(
					data: $this->zgwService->buildValidationError($ruleResult),
					statusCode: $ruleResult['status']
				);
			}

			// Build the besluit URL for OIO cleanup.
			$decisionUrl = $this->zgwService->buildBaseUrl(
				$this->request,
				self::ZGW_API,
				'besluiten'
			) . '/' . $uuid;

			// Cascade delete of BesluitInformatieObjecten is handled by
			// OpenRegister via onDelete: CASCADE on decisionDocument.decision.
			// Brc-009: Sync-delete OIOs in DRC (cross-component side-effect).
			$this->sync->deleteOiosForDecision(decisionUrl: $decisionUrl);

			// Delete the besluit itself.
			$objectService->deleteObject(uuid: $uuid);
		} catch (\Throwable $e) {
			return $this->zgwService->storeFailureResponse(error: $e, operation: 'BRC delete besluit', object: $object);
		}//end try

		$this->zgwService->notifyDeleted(request: $this->request, zgwApi: self::ZGW_API, resource: $resource, uuid: $uuid);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroyBesluit()
}//end class
