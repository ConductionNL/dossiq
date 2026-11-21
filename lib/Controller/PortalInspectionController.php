<?php

/**
 * Dossiq Portal Inspection Controller
 *
 * The receiving end of the portal action `completeChecklistTask` (change
 * inspection-checklists-onto-task, task 4.3, design D6). An external
 * inspector opens their inspection task in portaliq's task list; because the
 * task names this action in `metadata.completeVia`, portaliq shows the
 * action's form with the task uuid preset under `taskId` and forwards the
 * answers here server-to-server with a signed `X-Portal-Subject` assertion.
 * The route is public and CSRF-free because the caller is portaliq's backend:
 * the assertion is the authentication, and there is no session fallback.
 *
 * Order, fail-closed: verify (401), audience (403), then
 * ExternalInspectionRunService, which checks the task's stored party
 * reference against the assertion's subject (404), the answers against the
 * frozen template (422), and only then completes the task.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\VerifiesPortalAssertions;
use OCA\Dossiq\Kernel\Contracts\ExternalInspectionRunInterface;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Completes the inspection task the external inspector portaliq vouches for.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */
class PortalInspectionController extends Controller {
	use VerifiesPortalAssertions;

	/**
	 * The brute-force action a rejected assertion counts against.
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audiences this action is offered to.
	 */
	private const AUDIENCES = ['inspector'];

	/**
	 * The body fields the completion reads; everything else is dropped.
	 */
	private const FIELDS = ['items', 'responses', 'remarks'];

	/**
	 * Constructor.
	 *
	 * @param string                           $appName   The app name.
	 * @param IRequest                         $request   The request.
	 * @param PortalAssertionVerifierInterface $verifier  Verifies portaliq's assertion.
	 * @param ExternalInspectionRunInterface   $runs      Completes the external inspection task.
	 * @param IThrottler                       $throttler Counts rejected assertions.
	 * @param LoggerInterface                  $logger    Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly ExternalInspectionRunInterface $runs,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Complete the inspector's own inspection task with their answers.
	 *
	 * @return JSONResponse 200 the run; 401, 403, 400, 404, 409, 422 or 503 `{error}`.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/design.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function complete(): JSONResponse {
		$claims = $this->portalClaims();
		if ($claims instanceof JSONResponse) {
			return $claims;
		}

		$taskId = $this->request->getParam('taskId', '');
		if (is_string($taskId) === false) {
			$taskId = '';
		}

		$payload = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$payload[$field] = $value;
			}
		}

		$result = $this->runs->complete(claims: $claims, taskId: $taskId, payload: $payload);

		return new JSONResponse($result['body'], $result['status']);
	}//end complete()
}//end class
