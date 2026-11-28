<?php

/**
 * Dossiq BRC decision deletion.
 *
 * Deleting a besluit (brc-009): find it, run its destroy business rules,
 * sync-delete its OIOs in DRC, then delete it. A store failure answers the
 * store failure response, a missing besluit 404, and a rule that refuses its
 * validation error, before anything is deleted. Split out of
 * {@see \OCA\Dossiq\Controller\BrcController}, which keeps the route, the
 * mapping lookup and the delete notification.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Kernel\Contracts\BrcCrossRegisterSyncInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Support\NormalisesObjectRows;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Deletes a besluit after its destroy rules, with its OIOs in DRC.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class BrcDecisionDeletion {
	use NormalisesObjectRows;

	/**
	 * The ZGW API the besluit belongs to.
	 *
	 * @var string
	 */
	private const ZGW_API = 'besluiten';

	/**
	 * The besluit resource, as the business rules name it.
	 *
	 * @var string
	 */
	private const DECISION_RESOURCE = 'besluiten';

	/**
	 * Constructor.
	 *
	 * @param ZgwServiceInterface           $zgwService The shared ZGW service.
	 * @param BrcCrossRegisterSyncInterface $sync       Sync-deletes the besluit's OIOs in DRC.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly ZgwServiceInterface $zgwService,
		private readonly BrcCrossRegisterSyncInterface $sync,
	) {
	}//end __construct()

	/**
	 * Find a besluit and delete it, answering why not when it cannot be.
	 *
	 * @param object $objectService The object service.
	 * @param array $mappingConfig The besluiten mapping.
	 * @param string $uuid The besluit UUID.
	 * @param IRequest $request The request, for the besluit's URL.
	 *
	 * @return JSONResponse|null The failure, not-found or validation response, or null once deleted.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function delete(object $objectService, array $mappingConfig, string $uuid, IRequest $request): ?JSONResponse {
		$object = self::DECISION_RESOURCE . ' ' . $uuid;
		try {
			$existingObj = $objectService->find(
				$uuid,
				new ObjectFindOptions(
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema']
				)
			);
		} catch (\Throwable $e) {
			return $this->zgwService->storeFailureResponse(error: $e, operation: 'BRC delete besluit', object: $object);
		}

		if ($existingObj === null) {
			return $this->zgwService->notFoundResponse();
		}

		try {
			return $this->validateAndDeleteDecision(
				objectService: $objectService,
				existingObj: $existingObj,
				decision: ['uuid' => $uuid, 'request' => $request]
			);
		} catch (\Throwable $e) {
			return $this->zgwService->storeFailureResponse(error: $e, operation: 'BRC delete besluit', object: $object);
		}//end try
	}//end delete()

	/**
	 * Run the destroy business rules, then sync-delete the besluit's OIOs and delete it.
	 *
	 * @param object $objectService The object service.
	 * @param mixed $existingObj The stored besluit.
	 * @param array{uuid: string, request: IRequest} $decision The besluit UUID, and the request for its URL.
	 *
	 * @return JSONResponse|null The validation error, or null once deleted.
	 *
	 * @throws \Throwable When the sync or the delete fails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function validateAndDeleteDecision(object $objectService, mixed $existingObj, array $decision): ?JSONResponse {
		['uuid' => $uuid, 'request' => $request] = $decision;
		$existingData = $this->objectToArray(row: $existingObj);

		// Run destroy business rules.
		$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
			zgwApi: self::ZGW_API,
			resource: self::DECISION_RESOURCE,
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
			$request,
			self::ZGW_API,
			'besluiten'
		) . '/' . $uuid;

		// Cascade delete of BesluitInformatieObjecten is handled by
		// OpenRegister via onDelete: CASCADE on decisionDocument.decision.
		// Brc-009: Sync-delete OIOs in DRC (cross-component side-effect).
		$this->sync->deleteOiosForDecision(decisionUrl: $decisionUrl);

		// Delete the besluit itself.
		$objectService->deleteObject(new ObjectDeletion(uuid: $uuid));

		return null;
	}//end validateAndDeleteDecision()
}//end class
