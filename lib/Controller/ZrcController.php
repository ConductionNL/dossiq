<?php

/**
 * Dossiq ZRC (Zaken Register) Controller
 *
 * Handles the ZGW Zaken register API endpoints: zaken, statussen, resultaten,
 * rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten.
 *
 * Delegates shared operations to ZgwService while implementing ZRC-specific
 * behaviour such as zaak-closed resolution, eindstatus side effects,
 * authorization-based filtering (zrc-006), and OIO cross-register sync (zrc-005).
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

use OCA\Dossiq\Exception\CaseHeldException;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\Zgw\ZgwSearchScope;
use OCA\Dossiq\Service\Zgw\ZrcStatusEffects;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * ZRC (Zaken Register) Controller
 *
 * Serves ZGW-compliant Zaken API endpoints on top of English-language
 * OpenRegister data with bidirectional mapping.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZrcController extends ZgwController {
	/**
	 * The ZGW API group for this controller.
	 *
	 * @var string
	 */
	private const ZGW_API = 'zaken';

	/**
	 * Ordered vertrouwelijkheidaanduiding levels for authorization filtering.
	 *
	 * @var array<string, int>
	 */
	private const VERTROUWELIJKHEID_LEVELS = [
		'openbaar' => 1,
		'beperkt_openbaar' => 2,
		'intern' => 3,
		'zaakvertrouwelijk' => 4,
		'vertrouwelijk' => 5,
		'confidentieel' => 6,
		'geheim' => 7,
		'zeer_geheim' => 8,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The application name
	 * @param IRequest $request The incoming request
	 * @param ZgwService $zgwService The shared ZGW service
	 * @param IL10N $l10n The localization service
	 * @param CaseRelationService $caseRelationService Typed peer-relation service
	 * @param ZrcStatusEffects $statusEffects What a new status or resultaat does to its zaak
	 * @param DocumentJoinHoming $joinHoming Refuses a join to a case without a folder, and moves the file into it
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
		private readonly IL10N $l10n,
		private readonly CaseRelationService $caseRelationService,
		private readonly ZrcStatusEffects $statusEffects,
		private readonly DocumentJoinHoming $joinHoming,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List resources.
	 *
	 * ZRC-specific: for zaken, applies authorization-based filtering (zrc-006a).
	 *
	 * @param string $resource The ZGW resource name
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

		$response = $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);

		// Zrc-006a: Filter zaken results based on consumer's vertrouwelijkheidaanduiding.
		if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
			$response = $this->filterCasesByAuthorisation(response: $response);
			// Related-case-linking: populate relevanteAndereZaken per result.
			$response = $this->enrichCasesListRelevanteAndereCases(response: $response);
		}

		return $response;
	}//end index()

	/**
	 * Create a resource.
	 *
	 * ZRC-specific: resolves zaak-closed from the request body before validation,
	 * triggers eindstatus side effects when creating statussen, checks scopes
	 * for zaken creation (zrc-006c), and syncs OIO for zaakinformatieobjecten (zrc-005a).
	 *
	 * @param string $resource The ZGW resource name
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
		$refusal = $this->createRefusal(resource: $resource);
		if ($refusal !== null) {
			return $refusal;
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$originalBody = $this->zgwService->getRequestBody($this->request);
			$ruleResult = $this->validateCreate(resource: $resource, body: $originalBody, mappingConfig: $mappingConfig);
			if ($ruleResult['valid'] === false) {
				return new JSONResponse(
					data: $this->zgwService->buildValidationError($ruleResult),
					statusCode: $ruleResult['status']
				);
			}

			$body = $ruleResult['enrichedBody'];
			$englishData = $this->zgwService->applyInboundMapping(
				body: $body,
				mapping: $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig),
				mappingConfig: $mappingConfig
			);

			$beforeSave = $this->beforeCreateSave(resource: $resource, originalBody: $originalBody, body: $body);
			if ($beforeSave !== null) {
				return $beforeSave;
			}

			$objectData = $this->objectToArray(
				row: $this->zgwService->getObjectService()->saveObject(
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema'],
					object: $englishData
				)
			);
			$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

			$afterSave = $this->afterCreateSave(resource: $resource, objectUuid: (string)$objectUuid, originalBody: $originalBody, objectData: $objectData);
			if ($afterSave !== null) {
				return $afterSave;
			}

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$mapped = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig),
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);
			if ($resource === 'zaakinformatieobjecten') {
				$mapped = $this->completeCreatedJoin(mapped: $mapped, originalBody: $originalBody, body: $body);
			}

			$this->zgwService->publishNotification(
				self::ZGW_API,
				$resource,
				$baseUrl . '/' . $objectUuid,
				'create'
			);

			return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'ZRC create error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end create()

	/**
	 * Why this consumer may not create this resource now, or null.
	 *
	 * Zrc-006c / M3: zaken need zaken.aanmaken, every other resource
	 * zaken.bijwerken; the object store must be reachable.
	 *
	 * @param string $resource The ZGW resource name.
	 *
	 * @return JSONResponse|null
	 */
	private function createRefusal(string $resource): ?JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$requiredScope = 'zaken.bijwerken';
		if ($resource === 'zaken') {
			$requiredScope = 'zaken.aanmaken';
		}

		if ($this->zgwService->consumerHasScope($this->request, 'zrc', $requiredScope) === false) {
			return $this->permissionDeniedResponse();
		}

		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		return null;
	}//end createRefusal()

	/**
	 * Run the ZGW business rules for a create, knowing whether the zaak is closed.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param array $body The body as received.
	 * @param array $mappingConfig The resource mapping.
	 *
	 * @return array The rules result: valid, status, enrichedBody, errors.
	 */
	private function validateCreate(string $resource, array $body, array $mappingConfig): array {
		// ZRC-specific: resolve zaak closed from body before validation.
		$caseClosed = $this->zgwService->resolveZaakClosedFromBody($resource, $body);
		$hasGeforceerd = true;
		if ($caseClosed === true) {
			$hasGeforceerd = $this->zgwService->consumerHasScope(
				$this->request,
				'zrc',
				'zaken.geforceerd-bijwerken'
			);
		}

		return $this->zgwService->getBusinessRulesService()->validate(
			zgwApi: self::ZGW_API,
			resource: $resource,
			action: 'create',
			body: $body,
			objectService: $this->zgwService->getObjectService(),
			mappingConfig: $mappingConfig,
			caseClosed: $caseClosed,
			hasGeforceerd: $hasGeforceerd
		);
	}//end validateCreate()

	/**
	 * The checks that run after the rules and before the save.
	 *
	 * A status that reopens a closed zaak needs zaken.heropenen (zrc-008c); an
	 * eindstatus needs every linked document to carry indicatieGebruiksrecht
	 * (zrc-007q); a join needs a case with a folder.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param array $originalBody The body as received.
	 * @param array $body The body after the rules ran.
	 *
	 * @return JSONResponse|null
	 */
	private function beforeCreateSave(string $resource, array $originalBody, array $body): ?JSONResponse {
		if ($resource === 'statussen') {
			if ($this->statusEffects->isReopenAttempt(body: $originalBody) === true
				&& $this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.heropenen') === false
			) {
				return $this->permissionDeniedResponse();
			}

			$gebruiksrechtError = $this->statusEffects->unsetUsageRightsRefusal(body: $originalBody);
			if ($gebruiksrechtError !== null) {
				return new JSONResponse(data: $gebruiksrechtError, statusCode: Http::STATUS_BAD_REQUEST);
			}
		}

		// Documents live on the case: a join names the case whose folder the
		// document's file moves into, so a case without a folder refuses it.
		if ($resource === 'zaakinformatieobjecten') {
			$refusal = $this->joinHoming->refusal(caseUrl: $this->joinCaseUrl(originalBody: $originalBody, body: $body));
			if ($refusal !== null) {
				return new JSONResponse(data: ['detail' => $refusal], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
			}
		}

		return null;
	}//end beforeCreateSave()

	/**
	 * The side effects of a saved create: related zaken, status and result effects.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $objectUuid The saved object.
	 * @param array $originalBody The body as received.
	 * @param array $objectData The saved object.
	 *
	 * @return JSONResponse|null A refusal of the related zaken, or null.
	 */
	private function afterCreateSave(string $resource, string $objectUuid, array $originalBody, array $objectData): ?JSONResponse {
		// Related-case-linking: a relation URL that does not resolve to a local
		// case is rejected with the standard ZGW validation error shape.
		if ($resource === 'zaken') {
			return $this->applyInboundRelevanteAndereCases(caseUuid: $objectUuid, body: $originalBody);
		}

		if ($resource === 'statussen') {
			$this->statusEffects->applyStatusEffect(body: $originalBody, objectData: $objectData);
		}

		// Zrc-021: a resultaat derives archiefactiedatum and archiefnominatie on its zaak.
		if ($resource === 'resultaten') {
			$this->statusEffects->applyResultEffect(body: $originalBody);
		}

		return null;
	}//end afterCreateSave()

	/**
	 * Finish a created join: enrich the answer, create the DRC OIO, home the file (zrc-004a, zrc-005a).
	 *
	 * @param array $mapped The outbound-mapped join.
	 * @param array $originalBody The body as received.
	 * @param array $body The body after the rules ran.
	 *
	 * @return array The enriched answer.
	 */
	private function completeCreatedJoin(array $mapped, array $originalBody, array $body): array {
		$mapped = $this->enrichZioResponse(mapped: $mapped, body: $body);

		$caseUrl = $this->joinCaseUrl(originalBody: $originalBody, body: $body);
		$ioUrl = $originalBody['informatieobject'] ?? ($body['informatieobject'] ?? '');
		$this->syncCreateObjectInformatieObject(caseUrl: $caseUrl, ioUrl: $ioUrl);

		// Documents live on the case: the first join moves the file into the case.
		$this->joinHoming->home(caseUrl: $caseUrl, informatieobjectUrl: (string)$ioUrl);

		return $mapped;
	}//end completeCreatedJoin()

	/**
	 * The case a zaakinformatieobject body names, as the ZGW client wrote it.
	 *
	 * @param array<string, mixed> $originalBody The body as received.
	 * @param array<string, mixed> $body The body after the rules ran.
	 *
	 * @return string The zaak URL or uuid, '' when absent.
	 */
	private function joinCaseUrl(array $originalBody, array $body): string {
		return (string)($originalBody['zaak'] ?? ($originalBody['case'] ?? ($body['zaak'] ?? ($body['case'] ?? ''))));
	}//end joinCaseUrl()

	/**
	 * Show a specific resource.
	 *
	 * ZRC-specific: for zaken, checks zaken.lezen scope and vertrouwelijkheidaanduiding (zrc-006b).
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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

		// Zrc-006b: Check zaken.lezen scope and vertrouwelijkheidaanduiding.
		if ($resource === 'zaken') {
			$scopeError = $this->checkCaseReadAccess(uuid: $uuid);
			if ($scopeError !== null) {
				return $scopeError;
			}
		}

		$response = $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);

		// Related-case-linking: populate relevanteAndereZaken from relatedCases.
		if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
			$response = $this->enrichCaseRelevanteAndereCases(response: $response);
		}

		return $response;
	}//end show()

	/**
	 * Full update a resource.
	 *
	 * ZRC-specific: resolves zaak-closed from existing data before delegating.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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
		// Resolve UUID from URL path — body "uuid" can override controller args.
		$uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate updates on zrc.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.bijwerken') === false) {
			return $this->permissionDeniedResponse();
		}

		// Zrc-010/zrc-015: Pre-validate body fields that don't require
		// the existing object, so validation errors are returned even
		// when the OpenRegister find() call fails transiently.
		if ($resource === 'zaken') {
			$preValidation = $this->preValidateCaseBody(isPatch: false);
			if ($preValidation !== null) {
				return $preValidation;
			}
		}

		[$caseClosed, $hasGeforceerd] = $this->resolveCaseClosedForExisting(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			false,
			null,
			$caseClosed,
			$hasGeforceerd
		);

		return $this->enrichUpdated(resource: $resource, uuid: $uuid, response: $response);
	}//end update()

	/**
	 * Partial update a resource.
	 *
	 * ZRC-specific: resolves zaak-closed from existing data before delegating.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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
		// Resolve UUID from URL path — body "uuid" can override controller args.
		$uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate patches on zrc.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.bijwerken') === false) {
			return $this->permissionDeniedResponse();
		}

		// Zrc-010/zrc-015: Pre-validate body fields that don't require
		// the existing object, so validation errors are returned even
		// when the OpenRegister find() call fails transiently.
		if ($resource === 'zaken') {
			$preValidation = $this->preValidateCaseBody(isPatch: true);
			if ($preValidation !== null) {
				return $preValidation;
			}
		}

		[$caseClosed, $hasGeforceerd] = $this->resolveCaseClosedForExisting(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			true,
			null,
			$caseClosed,
			$hasGeforceerd
		);

		return $this->enrichUpdated(resource: $resource, uuid: $uuid, response: $response);
	}//end patch()

	/**
	 * The ZRC work after a successful update or patch (zrc-004b/c, related zaken).
	 *
	 * A join answers with its immutable aardRelatieWeergave; a zaak routes its
	 * inbound relevanteAndereZaken through the guarded relation service and
	 * re-emits them.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource.
	 * @param JSONResponse $response The shared handler's answer.
	 *
	 * @return JSONResponse
	 */
	private function enrichUpdated(string $resource, string $uuid, JSONResponse $response): JSONResponse {
		if ($response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		if ($resource === 'zaakinformatieobjecten') {
			return $this->enrichZioJsonResponse(response: $response);
		}

		if ($resource === 'zaken') {
			$relError = $this->applyInboundRelevanteAndereCases(
				caseUuid: $uuid,
				body: $this->zgwService->getRequestBody($this->request)
			);
			if ($relError !== null) {
				return $relError;
			}

			return $this->enrichCaseRelevanteAndereCases(response: $response);
		}

		return $response;
	}//end enrichUpdated()

	/**
	 * Delete a resource.
	 *
	 * ZRC-specific: resolves zaak-closed from existing data before delegating.
	 * For zaakinformatieobjecten, syncs ObjectInformatieObject deletion in DRC (zrc-005b).
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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

		// Zrc-023: Cascade delete for zaken — scope check is inside destroyZaak (C4).
		if ($resource === 'zaken') {
			return $this->destroyCase(uuid: $uuid);
		}

		// C3: Gate sub-resource destroys on zaken.verwijderen scope.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.verwijderen') === false) {
			return $this->permissionDeniedResponse();
		}

		// Zrc-005b: Before deleting, capture ZIO data for OIO cleanup.
		$zioData = null;
		if ($resource === 'zaakinformatieobjecten') {
			$zioData = $this->getZioDataForOioSync(uuid: $uuid);
		}

		[$caseClosed, $hasGeforceerd] = $this->resolveCaseClosedForExisting(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleDestroy(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			null,
			$caseClosed,
			$hasGeforceerd
		);

		// Zrc-005b: If ZIO deletion succeeded, also delete the OIO in DRC.
		if ($resource === 'zaakinformatieobjecten'
			&& $response->getStatus() === Http::STATUS_NO_CONTENT
			&& $zioData !== null
		) {
			$this->syncDeleteObjectInformatieObject(
				caseUrl: $zioData['zaakUrl'],
				ioUrl: $zioData['ioUrl']
			);
		}

		return $response;
	}//end destroy()

	/**
	 * List zaakeigenschappen for a zaak.
	 *
	 * @param string $zaakUuid The zaak UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function zaakeigenschappenIndex(string $zaakUuid): JSONResponse {
		return $this->index(resource: 'zaakeigenschappen');
	}//end zaakeigenschappenIndex()

	/**
	 * Create a zaakeigenschap for a zaak.
	 *
	 * @param string $zaakUuid The zaak UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function zaakeigenschappenCreate(string $zaakUuid): JSONResponse {
		return $this->create(resource: 'zaakeigenschappen');
	}//end zaakeigenschappenCreate()

	/**
	 * Show a specific zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_READ, period: 60)]
	public function zaakeigenschappenShow(string $zaakUuid, string $uuid): JSONResponse {
		return $this->show(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenShow()

	/**
	 * Update a zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function zaakeigenschappenUpdate(string $zaakUuid, string $uuid): JSONResponse {
		return $this->update(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenUpdate()

	/**
	 * Partial update a zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function zaakeigenschappenPatch(string $zaakUuid, string $uuid): JSONResponse {
		return $this->patch(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenPatch()

	/**
	 * Delete a zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: ZgwService::RATE_LIMIT_WRITE, period: 60)]
	public function zaakeigenschappenDestroy(string $zaakUuid, string $uuid): JSONResponse {
		return $this->destroy(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenDestroy()

	/**
	 * List zaakbesluiten for a zaak.
	 *
	 * @param string $zaakUuid The zaak UUID
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
	public function zaakbesluitenIndex(string $zaakUuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig('besluiten', 'besluiten');
		if ($mappingConfig === null) {
			return new JSONResponse(
				data: ['detail' => 'Besluit mapping not configured'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $zaakUuid],
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, 'besluiten', 'besluiten');
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = [];
			foreach (($result['results'] ?? []) as $object) {
				$objectData = $this->objectToArray(row: $object);

				$mapped[] = $this->zgwService->applyOutboundMapping(
					objectData: $objectData,
					mapping: $outboundMapping,
					mappingConfig: $mappingConfig,
					baseUrl: $baseUrl
				);
			}

			return new JSONResponse(data: $mapped);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'ZRC zaakbesluiten error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Internal server error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end zaakbesluitenIndex()

	/**
	 * Search zaken (POST /zaken/v1/zaken/_zoek).
	 *
	 * Delegates to index and returns HTTP 201 per the ZGW specification.
	 *
	 * Rate-limit rationale: lower than a plain read — zoek is the most
	 * expensive query in this controller, and it is reachable anonymously.
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
	public function zoek(): JSONResponse {
		$indexResponse = $this->index(resource: 'zaken');
		// The zoek endpoint reuses the list handler but returns 201 Created.
		// No instanceof guard: index() is declared to return JSONResponse, so the
		// check was always true (PHPStan 2: instanceof.alwaysTrue).
		$responseData = ($indexResponse->getData() ?? []);

		return new JSONResponse(data: $responseData, statusCode: Http::STATUS_CREATED);
	}//end zoek()

	/**
	 * Get audit trail for a resource.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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

		return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
	}//end audittrailIndex()

	/**
	 * Get a specific audit trail entry.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param string $auditUuid The audit trail entry UUID
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

		return $this->zgwService->handleAudittrailShow($this->request, self::ZGW_API, $resource, $uuid, $auditUuid);
	}//end audittrailShow()

	/**
	 * Check zaak read access based on consumer scopes and vertrouwelijkheidaanduiding (zrc-006b).
	 *
	 * @param string $uuid The zaak UUID
	 *
	 * @return JSONResponse|null Permission denied response, or null if access is allowed
	 */
	private function checkCaseReadAccess(string $uuid): ?JSONResponse {
		$autorisaties = $this->zgwService->getConsumerAuthorisaties($this->request, 'zrc');
		if ($autorisaties === null) {
			// Unrestricted (superuser or no consumer found).
			return null;
		}

		$lezenAuths = $this->readAuthorisations(autorisaties: $autorisaties);
		if ($lezenAuths === []) {
			return $this->permissionDeniedResponse();
		}

		// Check vertrouwelijkheidaanduiding of the zaak.
		try {
			$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaken');
			if ($mappingConfig === null) {
				return null;
			}

			$caseData = $this->objectToArray(
				row: $this->zgwService->getObjectService()->find(
					$uuid,
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema']
				)
			);
			$caseVa = $caseData['confidentiality'] ?? ($caseData['vertrouwelijkheidaanduiding'] ?? 'openbaar');
			if ($this->confidentialityAllowed(lezenAuths: $lezenAuths, confidentiality: $caseVa) === true) {
				return null;
			}

			// No matching autorisatie allows this vertrouwelijkheidaanduiding.
			return $this->permissionDeniedResponse();
		} catch (\InvalidArgumentException $e) {
			// Expected: zaak or mapping config not found — deny to be safe.
			$this->zgwService->getLogger()->warning(
				'zrc-006b: Zaak read access check failed (expected), denying: ' . $e->getMessage()
			);
			return $this->permissionDeniedResponse();
		} catch (\Throwable $e) {
			// Unexpected failure (OR down, schema rename, etc.) — deny rather than
			// silently allow access to potentially confidential data (fail-closed).
			$this->zgwService->getLogger()->error(
				'zrc-006b: Zaak read access check threw unexpected exception, denying: ' . $e->getMessage()
			);
			return $this->permissionDeniedResponse();
		}//end try
	}//end checkCaseReadAccess()

	/**
	 * Filter zaken results based on consumer's vertrouwelijkheidaanduiding (zrc-006a).
	 *
	 * @param JSONResponse $response The original index response
	 *
	 * @return JSONResponse The filtered response
	 */
	private function filterCasesByAuthorisation(JSONResponse $response): JSONResponse {
		$autorisaties = $this->zgwService->getConsumerAuthorisaties($this->request, 'zrc');
		if ($autorisaties === null) {
			// Unrestricted — return all.
			return $response;
		}

		$lezenAuths = $this->readAuthorisations(autorisaties: $autorisaties);
		$data = $response->getData();
		if ($lezenAuths === []) {
			// No zaken.lezen scope at all — return empty.
			if (is_array($data) === true) {
				$data['count'] = 0;
				$data['results'] = [];
				$response->setData($data);
			}

			return $response;
		}

		if (is_array($data) === false || isset($data['results']) === false) {
			return $response;
		}

		$filtered = array_values(
			array_filter(
				$data['results'],
				fn ($case): bool => $this->confidentialityAllowed(lezenAuths: $lezenAuths, confidentiality: ($case['vertrouwelijkheidaanduiding'] ?? 'openbaar'))
			)
		);

		$data['count'] = count($filtered);
		$data['results'] = $filtered;
		$response->setData($data);

		return $response;
	}//end filterCasesByAuthorisation()

	/**
	 * The consumer's autorisaties that grant zaken.lezen.
	 *
	 * @param array $autorisaties The consumer's autorisaties for the zrc.
	 *
	 * @return array
	 */
	private function readAuthorisations(array $autorisaties): array {
		return array_values(
			array_filter(
				$autorisaties,
				static fn ($auth): bool => in_array('zaken.lezen', ($auth['scopes'] ?? []), true) === true
			)
		);
	}//end readAuthorisations()

	/**
	 * Whether any zaken.lezen autorisatie reaches this vertrouwelijkheidaanduiding.
	 *
	 * An unknown level on the zaak reads as openbaar (1); an autorisatie with no
	 * or an unknown maximum reaches every level.
	 *
	 * @param array $lezenAuths The zaken.lezen autorisaties.
	 * @param mixed $confidentiality The zaak's vertrouwelijkheidaanduiding.
	 *
	 * @return bool
	 */
	private function confidentialityAllowed(array $lezenAuths, mixed $confidentiality): bool {
		$caseLevel = self::VERTROUWELIJKHEID_LEVELS[$confidentiality] ?? 1;
		foreach ($lezenAuths as $auth) {
			$maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
			$maxLevel = 99;
			if ($maxVa !== null) {
				$maxLevel = self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99;
			}

			if ($caseLevel <= $maxLevel) {
				return true;
			}
		}

		return false;
	}//end confidentialityAllowed()

	/**
	 * Build a permission denied response (zrc-006/zrc-007).
	 *
	 * @return JSONResponse
	 */
	private function permissionDeniedResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'detail' => $this->l10n->t('You do not have the correct permissions for this action.'),
				'code' => 'permission_denied',
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end permissionDeniedResponse()

	/**
	 * Pre-validate zaak body fields before calling handleUpdate (zrc-010/zrc-015).
	 *
	 * Validates communicatiekanaal URL format and productenOfDiensten
	 * without requiring the existing object from OpenRegister.
	 * This ensures validation errors are returned with proper invalidParams
	 * even when OpenRegister's find() call fails transiently.
	 *
	 * @param bool $isPatch Whether this is a PATCH operation
	 *
	 * @return JSONResponse|null A 400 response if validation fails, null if valid
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $isPatch reserved for partial-update validation
	 *
	 * @psalm-suppress UnusedParam — $isPatch reserved for partial-update validation
	 */
	private function preValidateCaseBody(bool $isPatch): ?JSONResponse {
		try {
			$body = $this->zgwService->getRequestBody($this->request);

			// Zrc-010: Validate communicatiekanaal URL.
			$channelError = $this->communicationChannelRefusal(channel: ($body['communicatiekanaal'] ?? null));
			if ($channelError !== null) {
				return $channelError;
			}

			// Zrc-015: Validate productenOfDiensten.
			$producten = $body['productenOfDiensten'] ?? null;
			$caseTypeUrl = $body['caseType'] ?? '';
			if (is_array($producten) === true
				&& empty($producten) === false
				&& $this->zgwService->getObjectService() !== null
				&& empty($caseTypeUrl) === false
			) {
				return $this->preValidateProductenOfDiensten(producten: $producten, caseTypeUrl: $caseTypeUrl);
			}
		} catch (\Throwable $e) {
			// Pre-validation is best-effort; fall through to handleUpdate.
			$this->zgwService->getLogger()->debug(
				'preValidateZaakBody: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end preValidateCaseBody()

	/**
	 * The zrc-010 refusal of a communicatiekanaal that is not a resource URL, or null.
	 *
	 * A malformed URL or a garbled UUID is `bad-url`; a collection endpoint
	 * (no UUID at the end of the path) is `invalid-resource`.
	 *
	 * @param mixed $channel The communicatiekanaal as received.
	 *
	 * @return JSONResponse|null
	 */
	private function communicationChannelRefusal(mixed $channel): ?JSONResponse {
		if ($channel === null || $channel === '') {
			return null;
		}

		$code = 'bad-url';
		if (filter_var($channel, FILTER_VALIDATE_URL) !== false) {
			$code = $this->resourceUrlProblem(path: (string)parse_url($channel, PHP_URL_PATH));
		}

		if ($code === null) {
			return null;
		}

		return new JSONResponse(
			data: [
				'detail' => 'De communicatiekanaal URL is ongeldig.',
				'invalidParams' => [
					[
						'name' => 'communicatiekanaal',
						'code' => $code,
						'reason' => 'De communicatiekanaal URL is ongeldig.',
					],
				],
			],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end communicationChannelRefusal()

	/**
	 * What is wrong with a URL path that should end in a resource UUID, or null.
	 *
	 * @param string $path The URL path.
	 *
	 * @return string|null `bad-url` for a garbled UUID, `invalid-resource` for a collection endpoint.
	 */
	private function resourceUrlProblem(string $path): ?string {
		if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i', $path) === 1) {
			return null;
		}

		$segments = array_filter(explode('/', trim($path, '/')));
		if (preg_match('/[0-9a-f]{4,}-/i', (string)end($segments)) === 1) {
			return 'bad-url';
		}

		return 'invalid-resource';
	}//end resourceUrlProblem()

	/**
	 * Pre-validate productenOfDiensten against zaaktype (zrc-015).
	 *
	 * @param array $producten The productenOfDiensten URLs
	 * @param string $caseTypeUrl The zaaktype URL
	 *
	 * @return JSONResponse|null A 400 response if invalid, null if valid
	 */
	private function preValidateProductenOfDiensten(
		array $producten,
		string $caseTypeUrl,
	): ?JSONResponse {
		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		if (preg_match($uuidPattern, $caseTypeUrl, $matches) !== 1) {
			return null;
		}

		$ztConfig = $this->zgwService->getZgwMappingService()->getMapping('caseType');
		if ($ztConfig === null) {
			return null;
		}

		try {
			$ztObj = $this->zgwService->getObjectService()->find(
				$matches[1],
				register: $ztConfig['sourceRegister'],
				schema: $ztConfig['sourceSchema']
			);
			$ztData = $this->objectToArray(row: $ztObj);
		} catch (\Throwable $e) {
			return null;
		}

		$allowed = $ztData['productsOrServices'] ?? ($ztData['productsAndServices'] ?? ($ztData['productenOfDiensten'] ?? []));
		if (is_string($allowed) === true) {
			$allowed = json_decode($allowed, true) ?? [];
		}

		if (is_array($allowed) === false || empty($allowed) === true) {
			return null;
		}

		foreach ($producten as $product) {
			if (in_array($product, $allowed, true) === false) {
				return new JSONResponse(
					data: [
						'detail' => $this->l10n->t('productenOfDiensten contains a value not present in the zaaktype.'),
						'invalidParams' => [
							[
								'name' => 'productenOfDiensten',
								'code' => 'invalid-products-services',
								'reason' => $this->l10n->t('Product \'%s\' is not allowed for this zaaktype.', [$product]),
							],
						],
					],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}
		}

		return null;
	}//end preValidateProductenOfDiensten()

	/**
	 * Delete a zaak with cascade delete of all sub-resources (zrc-023).
	 *
	 * Deletes: statussen, resultaten, rollen, zaakeigenschappen,
	 * zaakinformatieobjecten (+ OIO sync), zaakobjecten.
	 *
	 * @param string $uuid The zaak UUID to delete
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named constructor on a value object.
	 */
	private function destroyCase(string $uuid): JSONResponse {
		$refusal = $this->caseDeleteRefusal(uuid: $uuid);
		if ($refusal !== null) {
			return $refusal;
		}

		$objectService = $this->zgwService->getObjectService();

		// Zrc-005b: Before deleting the zaak, sync-delete OIOs in DRC
		// for any linked ZaakInformatieObjecten. This cross-component
		// side-effect cannot be handled by OpenRegister's cascade delete.
		$zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
		$zioScope = ZgwSearchScope::fromMapping(mappingConfig: $zioConfig);
		if ($zioConfig !== null && $zioScope === null) {
			return $this->unsearchableZioRefusal(uuid: $uuid);
		}

		if ($zioScope !== null) {
			$this->syncDeleteOiosOfCase(uuid: $uuid, zioScope: $zioScope);
		}

		// Related-case-linking: strip this case's entries from every counterpart
		// case's relatedCases BEFORE deletion so no dangling peer references
		// survive (mirrors the deelzaak orphan cleanup). Run while the case is
		// still readable so its own relation list can be dereferenced.
		try {
			$this->caseRelationService->cleanupForDeletedCase(caseId: $uuid);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'related-case-linking: relation cleanup failed for deleted zaak ' . $uuid . ': ' . $e->getMessage()
			);
		}

		// Cascade delete of sub-resources (rol, status, resultaat, etc.)
		// is handled by OpenRegister via onDelete: CASCADE in schema definitions.
		$deleteError = $this->deleteCaseObject(objectService: $objectService, uuid: $uuid);
		if ($deleteError !== null) {
			return $deleteError;
		}

		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, 'zaken');
		$this->zgwService->publishNotification(
			self::ZGW_API,
			'zaken',
			$baseUrl . '/' . $uuid,
			'destroy'
		);

		$this->zgwService->getLogger()->info(
			'zrc-023: Cascade deleted zaak ' . $uuid . ' with all sub-resources'
		);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroyCase()

	/**
	 * Why this zaak may not be deleted by this consumer, or null (C4).
	 *
	 * Needs zaken.verwijderen, a reachable store and mapping, a zaak that
	 * exists, and zaken.geforceerd-verwijderen when the zaak is archived.
	 *
	 * @param string $uuid The zaak.
	 *
	 * @return JSONResponse|null
	 */
	private function caseDeleteRefusal(string $uuid): ?JSONResponse {
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.verwijderen') === false) {
			return $this->permissionDeniedResponse();
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaken');
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, 'zaken');
		}

		// C4: a find() that throws or answers null is not-found.
		try {
			$caseObj = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
		} catch (\Throwable $e) {
			$caseObj = null;
		}

		if ($caseObj === null) {
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$archiefstatus = ($this->objectToArray(row: $caseObj)['archiefstatus'] ?? '');
		if ($archiefstatus !== '' && $archiefstatus !== 'nog_te_archiveren'
			&& $this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.geforceerd-verwijderen') === false
		) {
			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Archived zaken cannot be deleted without the zaken.geforceerd-verwijderen scope.'),
					'code' => 'permission_denied',
				],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end caseDeleteRefusal()

	/**
	 * Refuse the delete when the OIO cascade cannot even look (zrc-023).
	 *
	 * 🔴 DO NOT DESTROY THE ZAAK WHEN THE CASCADE CANNOT EVEN LOOK. A
	 * zaakinformatieobject mapping whose register or schema OpenRegister
	 * cannot resolve answers the paged search with an empty first page and no
	 * error ({@see ZgwSearchScope}), so the cascade would run zero times, the
	 * zaak would be destroyed and every OIO in DRC that pointed at it would
	 * survive as an orphan. Refusing the delete is recoverable; the orphans
	 * are not.
	 *
	 * @param string $uuid The zaak.
	 *
	 * @return JSONResponse The 409.
	 */
	private function unsearchableZioRefusal(string $uuid): JSONResponse {
		$this->zgwService->getLogger()->error(
			'zrc-023: refusing to delete zaak ' . $uuid
			. ': the zaakinformatieobject mapping has no searchable register/schema, '
			. 'so linked OIOs in DRC cannot be sync-deleted'
		);

		return new JSONResponse(
			data: [
				'detail' => $this->l10n->t(
					'This case cannot be deleted yet. Its zaakinformatieobject mapping has no '
					. 'usable register and schema. Without that, the linked documents cannot be unlinked.'
				),
				'code' => 'zaakinformatieobject-mapping-unsearchable',
			],
			statusCode: Http::STATUS_CONFLICT
		);
	}//end unsearchableZioRefusal()

	/**
	 * Sync-delete the DRC OIO of every ZIO of the zaak, paging through all of them (zrc-005b, L1).
	 *
	 * @param string $uuid The zaak.
	 * @param ZgwSearchScope $zioScope The searchable ZIO scope.
	 *
	 * @return void
	 */
	private function syncDeleteOiosOfCase(string $uuid, ZgwSearchScope $zioScope): void {
		$objectService = $this->zgwService->getObjectService();
		try {
			$page = 1;
			do {
				$query = $objectService->buildSearchQuery(
					requestParams: ['case' => $uuid, '_limit' => 100, '_page' => $page],
					register: $zioScope->register,
					schema: $zioScope->schema
				);
				$objects = ($objectService->searchObjectsPaginated(query: $query)['results'] ?? []);
				foreach ($objects as $obj) {
					$this->syncDeleteOioOfZio(zio: $obj);
				}

				$page++;
				$hasMore = count($objects) === 100;
			} while ($hasMore === true);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-023: Failed to sync-delete OIOs for zaak ' . $uuid . ': ' . $e->getMessage()
			);
		}//end try
	}//end syncDeleteOiosOfCase()

	/**
	 * Sync-delete the DRC OIO of one ZIO.
	 *
	 * @param mixed $zio The ZIO row.
	 *
	 * @return void
	 */
	private function syncDeleteOioOfZio(mixed $zio): void {
		$data = $this->objectToArray(row: $zio);
		$subUuid = $data['id'] ?? ($data['@self']['id'] ?? '');
		if ($subUuid === '') {
			return;
		}

		$zioData = $this->getZioDataForOioSync(uuid: $subUuid);
		if ($zioData !== null) {
			$this->syncDeleteObjectInformatieObject(
				caseUrl: $zioData['zaakUrl'],
				ioUrl: $zioData['ioUrl']
			);
		}
	}//end syncDeleteOioOfZio()

	/**
	 * Delete the zaak object, translating a refusal (REQ-CM-35, ADR-105).
	 *
	 * The case delete guard's refusal is a state conflict (409) rather than a
	 * malformed request. Any OTHER hook that stopped the delete, and any other
	 * failure, keeps the generic 400: this claims only the refusal it can name.
	 *
	 * @param object $objectService The object store.
	 * @param string $uuid The zaak.
	 *
	 * @return JSONResponse|null The error, or null when deleted.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CaseHeldException::fromHookErrors() is that exception's named
	 *  constructor: recognising the refusal body IS deciding whether there is an exception to build.
	 */
	private function deleteCaseObject(object $objectService, string $uuid): ?JSONResponse {
		try {
			$objectService->deleteObject(uuid: $uuid);
			return null;
		} catch (HookStoppedException $stopped) {
			$held = CaseHeldException::fromHookErrors(errors: $stopped->getErrors());
			if ($held !== null) {
				return new JSONResponse(
					data: ($held->toResponseBody() + ['detail' => $held->getMessage()]),
					statusCode: CaseHeldException::STATUS
				);
			}

			return new JSONResponse(
				data: ['detail' => 'Failed to delete case: ' . $stopped->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['detail' => 'Failed to delete case: ' . $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end deleteCaseObject()

	/**
	 * Resolve zaak-closed state and geforceerd scope for an existing resource.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 *
	 * @return array{0: ?bool, 1: bool} [zaakClosed, hasGeforceerd]
	 */
	private function resolveCaseClosedForExisting(string $resource, string $uuid): array {
		$caseClosed = null;
		$hasGeforceerd = true;

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig !== null && $this->zgwService->getObjectService() !== null) {
			try {
				$existingObj = $this->zgwService->getObjectService()->find(
					$uuid,
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema']
				);
				$existingData = $this->objectToArray(row: $existingObj);

				$caseClosed = $this->zgwService->resolveZaakClosed($resource, $existingData);
				$hasGeforceerd = true;
				if ($caseClosed === true) {
					$hasGeforceerd = $this->zgwService->consumerHasScope(
						$this->request,
						'zrc',
						'zaken.geforceerd-bijwerken'
					);
				}
			} catch (\Throwable $e) {
				// WF3c fix: fail-CLOSED on any unexpected error. Returning
				// [true, false] (zaak=closed, no geforceerd scope) causes the
				// upstream caller to emit a 403 rather than silently allowing
				// modification of a legally-finalised zaak (zrc-007, Awb 4:5).
				$this->zgwService->getLogger()->error(
					'Could not resolve zaakClosed for ' . $resource . '/' . $uuid . ' — denying (fail-closed)',
					['exception' => $e->getMessage()]
				);
				return [true, false];
			}//end try
		}//end if

		return [$caseClosed, $hasGeforceerd];
	}//end resolveZaakClosedForExisting()

	/**
	 * Enrich a ZaakInformatieObject outbound-mapped array with aardRelatieWeergave and registratiedatum.
	 *
	 * @param array $mapped The outbound-mapped data
	 * @param array $body The enriched request body (from business rules)
	 *
	 * @return array The enriched mapped data
	 */
	private function enrichZioResponse(array $mapped, array $body): array {
		// Zrc-004a: aardRelatieWeergave is always "Hoort bij, omgekeerd: kent".
		$mapped['natureRelationshipDisplay'] = 'Hoort bij, omgekeerd: kent';

		// Zrc-004a: registratiedatum from the enriched body (set by business rules).
		if (isset($body['registrationDate']) === true
			&& isset($mapped['registrationDate']) === false
		) {
			$mapped['registrationDate'] = $body['registrationDate'];
		}

		return $mapped;
	}//end enrichZioResponse()

	/**
	 * Enrich a ZaakInformatieObject JSONResponse with aardRelatieWeergave (zrc-004b/c).
	 *
	 * Used for update/patch responses where we intercept the JSONResponse from handleUpdate.
	 *
	 * @param JSONResponse $response The response to enrich
	 *
	 * @return JSONResponse The enriched response
	 */
	private function enrichZioJsonResponse(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === true) {
			$data['natureRelationshipDisplay'] = 'Hoort bij, omgekeerd: kent';
			$response->setData($data);
		}

		return $response;
	}//end enrichZioJsonResponse()

	/**
	 * Build the ZRC relevanteAndereZaken array for a single zaak from its
	 * relatedCases field (outbound). Emits absolute zaak URLs and the
	 * aardRelatie; never emits the dossiq-local toelichting. Always an array
	 * (empty when there are no relations), per VNG schema compliance.
	 *
	 * @param array<string, mixed> $caseData The mapped zaak response data.
	 *
	 * @return array<int, array{url: string, aardRelatie: string}>
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function buildRelevanteAndereCases(array $caseData): array {
		$uuid = (string)($caseData['uuid'] ?? ($caseData['identificatie'] ?? ''));
		$pattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

		// Prefer a UUID embedded in the id field, else fall back to the self URL.
		// When neither yields one the original id is kept verbatim.
		foreach ([$uuid, (string)($caseData['url'] ?? '')] as $candidate) {
			if ($candidate !== '' && preg_match($pattern, $candidate, $matches) === 1) {
				$uuid = $matches[1];
				break;
			}
		}

		if ($uuid === '') {
			return [];
		}

		$relations = $this->caseRelationService->listRelations(caseId: $uuid);
		if ($relations === []) {
			return [];
		}

		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, 'zaken');
		$out = [];
		foreach ($relations as $relation) {
			$targetId = (string)($relation['caseId'] ?? '');
			$nature = (string)($relation['aardRelatie'] ?? '');
			if ($targetId === '' || $nature === '') {
				continue;
			}

			$out[] = [
				'url' => $baseUrl . '/' . $targetId,
				'aardRelatie' => $nature,
			];
		}

		return $out;
	}//end buildRelevanteAndereZaken()

	/**
	 * Set relevanteAndereZaken on a single-zaak (show/update/patch) response.
	 *
	 * @param JSONResponse $response The zaak response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function enrichCaseRelevanteAndereCases(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === true) {
			$data['relevanteAndereZaken'] = $this->buildRelevanteAndereCases(caseData: $data);
			$response->setData($data);
		}

		return $response;
	}//end enrichZaakRelevanteAndereZaken()

	/**
	 * Set relevanteAndereZaken on every result of a zaken list response.
	 *
	 * @param JSONResponse $response The zaken list response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function enrichCasesListRelevanteAndereCases(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === false || isset($data['results']) === false || is_array($data['results']) === false) {
			return $response;
		}

		foreach ($data['results'] as $idx => $case) {
			if (is_array($case) === true) {
				$case['relevanteAndereZaken'] = $this->buildRelevanteAndereCases(caseData: $case);
				$data['results'][$idx] = $case;
			}
		}

		$response->setData($data);

		return $response;
	}//end enrichZakenListRelevanteAndereZaken()

	/**
	 * Resolve an inbound relevanteAndereZaken array on a zaak write into local
	 * case UUIDs and route each through the guarded, symmetric
	 * CaseRelationService. A relation URL that does not resolve to a local case
	 * is rejected with the capability's standard ZGW validation error shape.
	 *
	 * @param string $caseUuid The local UUID of the written zaak.
	 * @param array<string, mixed> $body The original (Dutch) request body.
	 *
	 * @return JSONResponse|null A 400 validation error, or null on success.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function applyInboundRelevanteAndereCases(string $caseUuid, array $body): ?JSONResponse {
		$relevanteCases = ($body['relevanteAndereZaken'] ?? null);
		if (is_array($relevanteCases) === false || $relevanteCases === [] || $caseUuid === '') {
			return null;
		}

		foreach ($relevanteCases as $idx => $relCase) {
			if ($this->addInboundRelation(caseUuid: $caseUuid, relCase: $relCase) === false) {
				return $this->unknownRelatedCaseRefusal(idx: $idx);
			}
		}

		return null;
	}//end applyInboundRelevanteAndereCases()

	/**
	 * Add one inbound relation; false when its URL names no usable local zaak.
	 *
	 * An entry that is not an object, or has no URL, is skipped (true). A URL
	 * without a UUID, or one the relation service refuses with access_denied,
	 * is not a usable local zaak (false).
	 *
	 * @param string $caseUuid The zaak being written.
	 * @param mixed $relCase One relevanteAndereZaken entry.
	 *
	 * @return bool
	 */
	private function addInboundRelation(string $caseUuid, mixed $relCase): bool {
		if (is_array($relCase) === false || (string)($relCase['url'] ?? '') === '') {
			return true;
		}

		if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', (string)$relCase['url'], $matches) !== 1) {
			return false;
		}

		$result = $this->caseRelationService->addRelation(
			caseId: $caseUuid,
			targetId: $matches[1],
			natureRelationship: (string)($relCase['aardRelatie'] ?? ''),
		);

		return ($result['ok'] === false && ($result['reason'] ?? '') === 'access_denied') === false;
	}//end addInboundRelation()

	/**
	 * The validation error for a relevanteAndereZaken URL that names no local zaak.
	 *
	 * @param int|string $idx The entry's index.
	 *
	 * @return JSONResponse
	 */
	private function unknownRelatedCaseRefusal(int|string $idx): JSONResponse {
		return new JSONResponse(
			data: [
				'type' => 'ValidationError',
				'code' => 'invalid',
				'title' => 'Ongeldige invoer.',
				'status' => 400,
				'detail' => 'relevanteAndereZaken verwijst naar een onbekende zaak.',
				'invalidParams' => [
					[
						'name' => "relevanteAndereZaken.{$idx}.url",
						'code' => 'unknown-zaak',
						'reason' => 'De zaak-URL verwijst niet naar een bekende lokale zaak.',
					],
				],
			],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end unknownRelatedCaseRefusal()

	/**
	 * Create an ObjectInformatieObject in the DRC when a ZaakInformatieObject is created (zrc-005a).
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 */
	private function syncCreateObjectInformatieObject(string $caseUrl, string $ioUrl): void {
		if ($caseUrl === '' || $ioUrl === '') {
			return;
		}

		try {
			$oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
			if ($oioConfig === null) {
				$this->zgwService->getLogger()->debug(
					'zrc-005a: objectinformatieobject mapping not configured'
				);
				return;
			}

			$oioData = [
				'object' => $caseUrl,
				'objectType' => 'case',
				'informatieobject' => $ioUrl,
			];

			$inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $oioConfig);
			$englishData = $this->zgwService->applyInboundMapping(
				body: $oioData,
				mapping: $inboundMapping,
				mappingConfig: $oioConfig
			);

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
			if (is_array($englishData) === false) {
				$englishData = $oioData;
			}

			$this->zgwService->getObjectService()->saveObject(
				register: $oioConfig['sourceRegister'],
				schema: $oioConfig['sourceSchema'],
				object: $englishData
			);

			$this->zgwService->getLogger()->info(
				'zrc-005a: Created ObjectInformatieObject for zaak/io sync'
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-005a: Failed to create ObjectInformatieObject: ' . $e->getMessage()
			);
		}//end try
	}//end syncCreateObjectInformatieObject()

	/**
	 * Get ZaakInformatieObject data needed for OIO sync before deletion.
	 *
	 * @param string $uuid The ZaakInformatieObject UUID
	 *
	 * @return array|null The zaakUrl and ioUrl, or null if not found
	 */
	private function getZioDataForOioSync(string $uuid): ?array {
		try {
			$zioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaakinformatieobjecten');
			if ($zioConfig === null) {
				return null;
			}

			$zioObj = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $zioConfig['sourceRegister'],
				schema: $zioConfig['sourceSchema']
			);
			$zioData = $this->objectToArray(row: $zioObj);

			// The ZIO stores 'case' as a UUID (format: uuid with $ref) and
			// 'document' as a full URL (format: uri). Build the zaak URL from
			// the case UUID, and use the document URL directly.
			$zaakUuid = $zioData['case'] ?? ($zioData['zaak'] ?? '');
			$ioUrl = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

			if ($zaakUuid === '' || $ioUrl === '') {
				return null;
			}

			// Build zaak URL from the UUID (case field stores UUID).
			$caseBaseUrl = $this->zgwService->buildBaseUrl($this->request, 'zaken', 'zaken');

			return [
				'zaakUrl' => $caseBaseUrl . '/' . $zaakUuid,
				'ioUrl' => $ioUrl,
			];
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->debug(
				'zrc-005b: Could not get ZIO data for OIO sync: ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end getZioDataForOioSync()

	/**
	 * Delete the ObjectInformatieObject in DRC when a ZaakInformatieObject is deleted (zrc-005b).
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 */
	private function syncDeleteObjectInformatieObject(string $caseUrl, string $ioUrl): void {
		try {
			$oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
			if ($oioConfig === null) {
				return;
			}

			// The OIO schema (documentLink) stores 'object' and 'document' as
			// full URLs (format: uri). Search by the full URL values directly.
			if ($caseUrl === '' || $ioUrl === '') {
				return;
			}

			// An unsearchable scope answers with an empty page and no error
			// ({@see ZgwSearchScope}), which reads as "there is no OIO to
			// delete" and leaves the DRC link behind. Name it instead.
			$oioScope = ZgwSearchScope::fromMapping(mappingConfig: $oioConfig);
			if ($oioScope === null) {
				$this->zgwService->getLogger()->error(
					'zrc-005b: objectinformatieobject mapping has no searchable register/schema, '
					. 'so the OIO for ' . $ioUrl . ' is being left behind as an orphan'
				);
				return;
			}

			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['object' => $caseUrl, 'document' => $ioUrl],
				register: $oioScope->register,
				schema: $oioScope->schema
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $oioObj) {
				$oioData = $this->objectToArray(row: $oioObj);

				$oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
				if ($oioUuid !== '') {
					$this->zgwService->getObjectService()->deleteObject(uuid: $oioUuid);
					$this->zgwService->getLogger()->info(
						'zrc-005b: Deleted ObjectInformatieObject ' . $oioUuid
					);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-005b: Failed to delete ObjectInformatieObject: ' . $e->getMessage()
			);
		}//end try
	}//end syncDeleteObjectInformatieObject()
}//end class
