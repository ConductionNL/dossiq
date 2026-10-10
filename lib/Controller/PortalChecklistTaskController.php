<?php

/**
 * Completes a checklist task from the portal.
 *
 * Change inspection-checklists-onto-task, task 4.3 (design D6). An external
 * party (an inspector without a Nextcloud account) is assigned an OpenRegister
 * task carrying a frozen checklist. portaliq forwards the party's answers here
 * with its signed `X-Portal-Subject` assertion. The assertion names the party;
 * OpenRegister's portal task seam checks the task's stored party reference
 * against it; the checklist's own rule decides whether the run may be
 * submitted and what its outcome is. Public and CSRF-free because the caller
 * is portaliq's backend, not a browser; a rejected assertion counts against
 * brute-force protection.
 *
 * Generic (decision 182): any case type whose flow assigns a checklist task
 * to a portal party uses this endpoint.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-an-external-inspector-completes-a-checklist-task-from-the-portal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The portal's completion of a checklist task.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-an-external-inspector-completes-a-checklist-task-from-the-portal
 */
class PortalChecklistTaskController extends Controller {

	/**
	 * The brute-force action a rejected assertion counts against.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audience a checklist task is offered to.
	 *
	 * @var array<int, string>
	 */
	private const AUDIENCES = ['inspector'];

	/**
	 * OpenRegister's refusals, by class name: denied reads as absent.
	 *
	 * @var array<string, int>
	 */
	private const REFUSALS = [
		'OCA\\OpenRegister\\Exception\\TaskAccessDeniedException' => Http::STATUS_NOT_FOUND,
		'OCA\\OpenRegister\\Exception\\TaskConflictException' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param string                  $appName   The app name.
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifier $verifier  Verifies portaliq's assertion.
	 * @param InspectionRunService    $runs      Checks the run and completes the task.
	 * @param IThrottler              $throttler Counts rejected assertions.
	 * @param LoggerInterface         $logger    Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly InspectionRunService $runs,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Complete one checklist task for the asserted party.
	 *
	 * The task is named in the body (`taskId`), the way portaliq forwards an
	 * endpoint action's fields; a missing one is a 400.
	 *
	 * @return JSONResponse 200 the run; 400, 401, 403, 404, 409, 422 or 500 `{error}`.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-an-external-inspector-completes-a-checklist-task-from-the-portal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function complete(): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$this->registerRejectedAssertion();
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$uuid = trim((string)$this->request->getParam('taskId', ''));
		if ($uuid === '') {
			return new JSONResponse(data: ['error' => 'taskId is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$payload = [
			'items' => $this->request->getParam('items', []),
			'remarks' => $this->request->getParam('remarks'),
		];

		try {
			return new JSONResponse(data: $this->runs->completeForParty(subjectRef: (string)$claims['sub'], taskUuid: $uuid, payload: $payload));
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'No such task'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (RuntimeException $refused) {
			return new JSONResponse(data: ['error' => $refused->getMessage()], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->refusal(error: $e);
		}
	}//end complete()

	/**
	 * Answer OpenRegister's named refusals; anything else is a server fault.
	 *
	 * @param Throwable $error The error.
	 *
	 * @return JSONResponse The answer.
	 */
	private function refusal(Throwable $error): JSONResponse {
		foreach (self::REFUSALS as $class => $status) {
			if (is_a($error, $class) === true) {
				$message = $error->getMessage();
				if ($status === Http::STATUS_NOT_FOUND) {
					$message = 'No such task';
				}

				return new JSONResponse(data: ['error' => $message], statusCode: $status);
			}
		}

		$this->logger->error('Dossiq: a portal checklist task could not be completed', ['exception' => $error->getMessage()]);

		return new JSONResponse(data: ['error' => 'The task could not be completed'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end refusal()

	/**
	 * Count a rejected assertion against brute-force protection.
	 *
	 * @return void
	 */
	private function registerRejectedAssertion(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: registerAttempt failed', ['error' => $e->getMessage()]);
		}
	}//end registerRejectedAssertion()
}//end class
