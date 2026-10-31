<?php

/**
 * Dossiq ZTC publish controller
 *
 * The three ZGW Catalogi publish endpoints: a zaaktype, besluittype or
 * informatieobjecttype is published by saving it back with `isDraft: false`.
 * They left ZtcController, which keeps the read and write routes of every
 * Catalogi resource (method-decomposition slice 8i).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Publishes a ZGW Catalogi type.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZtcPublishController extends ZgwController {
	/**
	 * The ZGW API identifier for the Catalogi register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'catalogi';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwService $zgwService The shared ZGW service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Publish a ZTC resource by setting isDraft to false.
	 *
	 * Requires the catalogi write scope. A zaaktype passes the CT-02b publish
	 * guard first (REQ-ZBR-024). Loads the existing object, sets isDraft=false,
	 * saves it back, and returns the outbound-mapped result.
	 *
	 * @param string $resource The ZGW resource name (zaaktypen, besluittypen, informatieobjecttypen).
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 */
	private function handlePublish(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		if ($this->zgwService->consumerHasScope($this->request, 'ztc', 'catalogi.schrijven') === false) {
			return $this->scopeDeniedResponse(scope: 'catalogi.schrijven');
		}

		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$existing = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$storedData = $this->objectToArray(row: $existing);

			$refusal = $this->zgwService->getBusinessRulesService()->guardPublishEndpoint(
				resource: $resource,
				existingObject: $storedData,
				objectService: $this->zgwService->getObjectService(),
				mappingConfig: $mappingConfig
			);
			if ($refusal !== null) {
				return new JSONResponse(
					data: $this->zgwService->buildValidationError(ruleResult: $refusal),
					statusCode: $refusal['status']
				);
			}

			$existingData = $this->publishedCopy(existingData: $storedData);

			$object = $this->zgwService->getObjectService()->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $existingData,
				uuid: $uuid
			);
			$objectData = $this->objectToArray(row: $object);

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);

			return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'ZTC publish error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(data: ['detail' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end handlePublish()

	/**
	 * The stored type as it is saved on publish: concept off, bookkeeping keys gone.
	 *
	 * @param array $existingData The stored type
	 *
	 * @return array The data to save
	 */
	private function publishedCopy(array $existingData): array {
		unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
		$existingData['isDraft'] = false;

		if (isset($existingData['identifier']) === true && is_int($existingData['identifier']) === true) {
			$existingData['identifier'] = (string)$existingData['identifier'];
		}

		// Re-encode fields that are stored as JSON strings but auto-decoded
		// by jsonSerialize. Only string-typed schema fields need re-encoding.
		// productsOrServices is NOT in this list: it is declared as an array,
		// so re-encoding it here wrote a string into an array property.
		foreach (['referenceProcess', 'relatedCaseTypes'] as $field) {
			if (isset($existingData[$field]) === true && is_array($existingData[$field]) === true) {
				$existingData[$field] = json_encode($existingData[$field]);
			}
		}

		return $existingData;
	}//end publishedCopy()

	/**
	 * Publish a zaaktype (set isDraft to false).
	 *
	 * @param string $uuid The zaaktype UUID.
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
	public function publishZaaktype(string $uuid): JSONResponse {
		return $this->handlePublish(resource: 'zaaktypen', uuid: $uuid);
	}//end publishZaaktype()

	/**
	 * Publish a besluittype (set isDraft to false).
	 *
	 * @param string $uuid The besluittype UUID.
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
	public function publishBesluittype(string $uuid): JSONResponse {
		return $this->handlePublish(resource: 'besluittypen', uuid: $uuid);
	}//end publishBesluittype()

	/**
	 * Publish an informatieobjecttype (set isDraft to false).
	 *
	 * @param string $uuid The informatieobjecttype UUID.
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
	public function publishInformatieobjecttype(string $uuid): JSONResponse {
		return $this->handlePublish(resource: 'informatieobjecttypen', uuid: $uuid);
	}//end publishInformatieobjecttype()
}//end class
