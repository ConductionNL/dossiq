<?php

/**
 * The besluitinformatieobject routes of the Besluiten API
 *
 * Listing, creating and deleting the link between a besluit and a document.
 * The list is a plain array, a create is mirrored in the Documenten register
 * (brc-005a) and a delete takes that mirror along (brc-005b).
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

use OCA\Dossiq\Service\ZgwService;
use OCA\Dossiq\Support\NormalisesObjectRows;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists, creates and deletes besluitinformatieobjecten.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrcDocumentLinkHandler {
	use NormalisesObjectRows;

	/**
	 * The ZGW API identifier for the Besluiten register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'besluiten';

	/**
	 * Constructor.
	 *
	 * @param ZgwService $zgwService The shared ZGW service.
	 * @param BrcCrossRegisterSync $sync Keeps the Documenten register in step.
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
		private readonly BrcCrossRegisterSync $sync,
	) {
	}//end __construct()

	/**
	 * List BesluitInformatieObjecten as a plain array (per ZGW spec).
	 *
	 * Unlike paginated resources, besluitinformatieobjecten returns a flat array.
	 *
	 * @param IRequest $request The incoming request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function index(IRequest $request): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$params = $request->getParams();
			$filters = $this->zgwService->translateQueryParams(
				params: $params,
				mappingConfig: $mappingConfig
			);

			$searchParams = array_merge($filters, ['_limit' => 100]);

			$query = $objectService->buildSearchQuery(
				requestParams: $searchParams,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			$objects = $result['results'] ?? [];
			$baseUrl = $this->zgwService->buildBaseUrl($request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = [];
			foreach ($objects as $object) {
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
				'BRC list BIO error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Internal server error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end indexBesluitInformatieObjecten()

	/**
	 * Create a BesluitInformatieObject with cross-register OIO sync (brc-005a).
	 *
	 * After creating the BIO, also creates an ObjectInformatieObject in the
	 * DRC register with objectType=besluit.
	 *
	 * @param IRequest $request The incoming request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function create(IRequest $request): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$body = $this->zgwService->getRequestBody($request);

			// Run business rules (brc-003a, brc-008a).
			$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
				zgwApi: self::ZGW_API,
				resource: $resource,
				action: 'create',
				body: $body,
				objectService: $objectService,
				mappingConfig: $mappingConfig
			);
			if ($ruleResult['valid'] === false) {
				return new JSONResponse(
					data: $this->zgwService->buildValidationError($ruleResult),
					statusCode: $ruleResult['status']
				);
			}

			$enrichedBody = $ruleResult['enrichedBody'];

			// Create the BIO via standard mapping flow.
			$inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
			$englishData = $this->zgwService->applyInboundMapping(
				body: $enrichedBody,
				mapping: $inboundMapping,
				mappingConfig: $mappingConfig
			);

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
			if (is_array($englishData) === false) {
				return new JSONResponse(
					data: ['detail' => 'Invalid mapping result'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			$object = $objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData
			);
			$objectData = $this->objectToArray(row: $object);

			$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

			$baseUrl = $this->zgwService->buildBaseUrl($request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);

			// Brc-005a: Create OIO in DRC.
			$decisionUrl = $enrichedBody['decision'] ?? '';
			$ioUrl = $enrichedBody['informatieobject'] ?? '';
			if ($decisionUrl !== '' && $ioUrl !== '') {
				$this->sync->createOioInDrc(decisionUrl: $decisionUrl, ioUrl: $ioUrl);
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
				'BRC create BIO error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end createBesluitInformatieObject()

	/**
	 * Delete a BesluitInformatieObject and its OIO in DRC (brc-005b).
	 *
	 * @param IRequest $request The incoming request
	 * @param string $uuid The BIO UUID to delete
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function destroy(IRequest $request, string $uuid): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			// Read the BIO to get besluit URL before deletion.
			$bioObj = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$bioData = $this->objectToArray(row: $bioObj);

			// Build the besluit URL from the stored decision UUID.
			$decisionUuid = $bioData['decision'] ?? '';
			$decisionUrl = '';
			if ($decisionUuid !== '') {
				$decisionUrl = $this->zgwService->buildBaseUrl(
					$request,
					self::ZGW_API,
					'besluiten'
				) . '/' . $decisionUuid;
			}

			$ioUrl = $bioData['document'] ?? '';

			// Delete the BIO.
			$objectService->deleteObject(uuid: $uuid);

			// Brc-005b: Delete matching OIO in DRC.
			if ($decisionUrl !== '' && $ioUrl !== '') {
				$this->sync->deleteOioByDecisionAndIo(decisionUrl: $decisionUrl, ioUrl: $ioUrl);
			}

			$baseUrl = $this->zgwService->buildBaseUrl($request, self::ZGW_API, $resource);
			$this->zgwService->publishNotification(
				self::ZGW_API,
				$resource,
				$baseUrl . '/' . $uuid,
				'destroy'
			);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'BRC delete BIO error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}//end try
	}//end destroyBesluitInformatieObject()
}//end class
