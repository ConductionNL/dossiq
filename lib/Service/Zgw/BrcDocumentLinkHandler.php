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

use OCA\Dossiq\Kernel\Contracts\BrcCrossRegisterSyncInterface;
use OCA\Dossiq\Kernel\Contracts\BrcDocumentLinkHandlerInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Support\MapsZgwRows;
use OCA\Dossiq\Support\NormalisesObjectRows;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists, creates and deletes besluitinformatieobjecten.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrcDocumentLinkHandler implements BrcDocumentLinkHandlerInterface {
	use MapsZgwRows;
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
	 * @param ZgwServiceInterface $zgwService The shared ZGW service.
	 * @param BrcCrossRegisterSyncInterface $sync Keeps the Documenten register in step.
	 */
	public function __construct(
		private readonly ZgwServiceInterface $zgwService,
		private readonly BrcCrossRegisterSyncInterface $sync,
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
		$bio = $this->bioRequest(request: $request);
		if ($bio instanceof JSONResponse) {
			return $bio;
		}

		try {
			$objects = $this->searchLinks(bio: $bio);
			$baseUrl = $this->zgwService->buildBaseUrl($request, self::ZGW_API, 'besluitinformatieobjecten');
			$mapped = $this->mapOutbound(objects: $objects, mappingConfig: $bio->mappingConfig, baseUrl: $baseUrl);

			return new JSONResponse(data: $mapped);
		} catch (\Throwable $e) {
			return $this->loggedServerError(logPrefix: 'BRC list BIO error: ', e: $e);
		}//end try
	}//end indexBesluitInformatieObjecten()

	/**
	 * Search the stored links the request's query parameters select, at most 100.
	 *
	 * @param BioRequest $bio The request, the object service and the mapping configuration.
	 *
	 * @return array<int, mixed> The stored objects.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function searchLinks(BioRequest $bio): array {
		return $this->filteredRows(objectService: $bio->objectService, mappingConfig: $bio->mappingConfig, params: $bio->request->getParams());
	}//end searchLinks()

	/**
	 * Map stored objects to their ZGW representation.
	 *
	 * @param array<int, mixed>    $objects       The stored objects.
	 * @param array<string, mixed> $mappingConfig The resource's mapping configuration.
	 * @param string               $baseUrl       The resource's base URL.
	 *
	 * @return array<int, mixed> The mapped objects.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function mapOutbound(array $objects, array $mappingConfig, string $baseUrl): array {
		return $this->outboundRows(rows: $objects, mappingConfig: $mappingConfig, baseUrl: $baseUrl);
	}//end mapOutbound()

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
		$bio = $this->bioRequest(request: $request);
		if ($bio instanceof JSONResponse) {
			return $bio;
		}

		try {
			return $this->createChecked(bio: $bio);
		} catch (\Throwable $e) {
			return $this->loggedFailure(logPrefix: 'BRC create BIO error: ', e: $e);
		}//end try
	}//end createBesluitInformatieObject()

	/**
	 * Validate the request body against the business rules and create the BIO.
	 *
	 * @param BioRequest $bio The request, the object service and the mapping configuration.
	 *
	 * @return JSONResponse
	 */
	private function createChecked(BioRequest $bio): JSONResponse {
		$mappingConfig = $bio->mappingConfig;
		$body = $this->zgwService->getRequestBody($bio->request);

		$ruleResult = $this->validateCreate(body: $body, bio: $bio);
		if ($ruleResult['valid'] === false) {
			return new JSONResponse(data: $this->zgwService->buildValidationError($ruleResult), statusCode: $ruleResult['status']);
		}

		$enrichedBody = $ruleResult['enrichedBody'];

		// Create the BIO via standard mapping flow.
		$inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
		$englishData = $this->zgwService->applyInboundMapping(body: $enrichedBody, mapping: $inboundMapping, mappingConfig: $mappingConfig);

		// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
		if (is_array($englishData) === false) {
			return new JSONResponse(data: ['detail' => 'Invalid mapping result'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$objectData = $this->saveCreated(bio: $bio, englishData: $englishData);

		return $this->createdResponse(bio: $bio, objectData: $objectData, enrichedBody: $enrichedBody);
	}//end createChecked()

	/**
	 * Run the business rules on the create body (brc-003a, brc-008a).
	 *
	 * @param array<string, mixed> $body The request body.
	 * @param BioRequest           $bio  The object service and the mapping configuration.
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 */
	private function validateCreate(array $body, BioRequest $bio): array {
		$resource = 'besluitinformatieobjecten';

		return $this->zgwService->getBusinessRulesService()->validate(
			zgwApi: self::ZGW_API,
			resource: $resource,
			action: 'create',
			body: $body,
			objectService: $bio->objectService,
			mappingConfig: $bio->mappingConfig
		);
	}//end validateCreate()

	/**
	 * Save the mapped BIO.
	 *
	 * @param BioRequest           $bio         The object service and the mapping configuration.
	 * @param array<string, mixed> $englishData The mapped object.
	 *
	 * @return array<string, mixed> The stored BIO.
	 */
	private function saveCreated(BioRequest $bio, array $englishData): array {
		$object = $bio->objectService->saveObject(
			object: $englishData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(
					register: $bio->mappingConfig['sourceRegister'],
					schema: $bio->mappingConfig['sourceSchema']
				))
		);

		return $this->objectToArray(row: $object);
	}//end saveCreated()

	/**
	 * Sync the OIO into DRC, notify, and answer with the created BIO.
	 *
	 * @param BioRequest           $bio          The request and the mapping configuration.
	 * @param array<string, mixed> $objectData   The stored BIO.
	 * @param array<string, mixed> $enrichedBody The validated request body.
	 *
	 * @return JSONResponse
	 */
	private function createdResponse(BioRequest $bio, array $objectData, array $enrichedBody): JSONResponse {
		$mappingConfig = $bio->mappingConfig;
		$resource = 'besluitinformatieobjecten';
		$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

		$baseUrl = $this->zgwService->buildBaseUrl($bio->request, self::ZGW_API, $resource);
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
	}//end createdResponse()

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
		$bio = $this->bioRequest(request: $request);
		if ($bio instanceof JSONResponse) {
			return $bio;
		}

		$resource = 'besluitinformatieobjecten';
		$object = $resource . ' ' . $uuid;
		$bioObj = $this->readBio(bio: $bio, uuid: $uuid, object: $object);
		if ($bioObj instanceof JSONResponse) {
			return $bioObj;
		}

		try {
			[$decisionUrl, $ioUrl] = $this->deleteBio(bio: $bio, bioObj: $bioObj, uuid: $uuid);
		} catch (\Throwable $e) {
			return $this->zgwService->storeFailureResponse(error: $e, operation: 'BRC delete BIO', object: $object);
		}

		$this->deleteMatchingOio(decisionUrl: $decisionUrl, ioUrl: $ioUrl);

		$this->zgwService->notifyDeleted(request: $request, zgwApi: self::ZGW_API, resource: $resource, uuid: $uuid);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroy()

	/**
	 * Read the BIO to delete, or the answer when it cannot be read or is missing.
	 *
	 * @param BioRequest $bio    The object service and the BIO mapping configuration
	 * @param string     $uuid   The BIO UUID
	 * @param string     $object The object label for failure logging
	 *
	 * @return mixed The stored BIO, or the failure or 404 answer
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function readBio(BioRequest $bio, string $uuid, string $object): mixed {
		try {
			// Read the BIO to get besluit URL before deletion.
			$bioObj = $bio->objectService->find(
				$uuid,
				new ObjectFindOptions(
					register: $bio->mappingConfig['sourceRegister'],
					schema: $bio->mappingConfig['sourceSchema']
				)
			);
		} catch (\Throwable $e) {
			return $this->zgwService->storeFailureResponse(error: $e, operation: 'BRC delete BIO', object: $object);
		}

		if ($bioObj === null) {
			return $this->zgwService->notFoundResponse();
		}

		return $bioObj;
	}//end readBio()

	/**
	 * Delete the BIO and return the besluit and informatieobject URLs it linked.
	 *
	 * @param BioRequest $bio    The request and the object service
	 * @param mixed      $bioObj The stored BIO
	 * @param string     $uuid   The BIO UUID
	 *
	 * @return array{0: string, 1: mixed} The besluit URL and the informatieobject URL
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function deleteBio(BioRequest $bio, mixed $bioObj, string $uuid): array {
		$bioData = $this->objectToArray(row: $bioObj);

		// Build the besluit URL from the stored decision UUID.
		$decisionUuid = $bioData['decision'] ?? '';
		$decisionUrl = '';
		if ($decisionUuid !== '') {
			$decisionUrl = $this->zgwService->buildBaseUrl(
				$bio->request,
				self::ZGW_API,
				'besluiten'
			) . '/' . $decisionUuid;
		}

		$ioUrl = $bioData['document'] ?? '';

		// Delete the BIO.
		$bio->objectService->deleteObject(new ObjectDeletion(uuid: $uuid));

		return [$decisionUrl, $ioUrl];
	}//end deleteBio()

	/**
	 * The object service and BIO mapping for a request, or the response that says why they are missing.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return BioRequest|JSONResponse
	 */
	private function bioRequest(IRequest $request): BioRequest|JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		return new BioRequest(request: $request, objectService: $objectService, mappingConfig: $mappingConfig);
	}//end bioRequest()

	/**
	 * Delete the OIO in the DRC that matched the deleted BIO.
	 *
	 * Brc-005b: a failure there is logged at error by the sync, and the delete
	 * still answers 204.
	 *
	 * @param string $decisionUrl The besluit URL
	 * @param mixed $ioUrl The informatieobject URL
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function deleteMatchingOio(string $decisionUrl, mixed $ioUrl): void {
		if ($decisionUrl !== '' && $ioUrl !== '') {
			$this->sync->deleteOioByDecisionAndIo(decisionUrl: $decisionUrl, ioUrl: $ioUrl);
		}
	}//end deleteMatchingOio()
}//end class
