<?php

/**
 * Dossiq Mandate Validation Middleware
 *
 * Blocks mandate-requiring requests (edit, status_update, delete, create)
 * when the user's mandate-matrix entry does not authorise the action.
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use OCA\Dossiq\Service\TenantAuditTrailService;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantService;
use OCA\Dossiq\Exception\RefusedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Mandate-matrix middleware. Audit-logs every decision (allow + deny).
 *
 * It also refuses, with 403, a request whose organisation is not `active`.
 * That check was `TenantMiddleware`'s, the only thing refusing a suspended
 * organisation on dossiq's routes; OpenRegister's `TenantQuotaMiddleware`
 * makes it on OpenRegister's routes only. It moved here when the active
 * tenant became OpenRegister's active organisation (Q3, Ruben 2026-10-08).
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */
class MandateValidationMiddleware extends Middleware {
	/**
	 * Mapping of HTTP verb → matrix action key.
	 *
	 * @var array<string, string>
	 */
	private const VERB_ACTION_MAP = [
		'POST' => 'create',
		'PUT' => 'edit',
		'PATCH' => 'edit',
		'DELETE' => 'delete',
	];

	/**
	 * URL substrings that map to a status_update action.
	 *
	 * @var array<int, string>
	 */
	private const STATUS_PATH_HINTS = ['/transition', '/status'];

	/**
	 * Controllers on which an organisation that is not active is not refused.
	 *
	 * The list `TenantMiddleware` had, less the deleted tenant controller:
	 * settings and the dashboard stay
	 * reachable, and health and metrics are served by the OpenRegister AppHost
	 * engine (ADR-040), whose dispatched controller is the generic class.
	 *
	 * @var array<int, string>
	 */
	private const LIFECYCLE_EXEMPT_CONTROLLERS = [
		'OCA\Dossiq\Controller\SettingsController',
		'OCA\Dossiq\Controller\DashboardController',
		'OCA\OpenRegister\AppHost\Controller\GenericHealthController',
		'OCA\OpenRegister\AppHost\Controller\GenericMetricsController',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param IUserSession $userSession User session.
	 * @param TenantContext $context Tenant context.
	 * @param TenantAuthenticationService $authService Auth service.
	 * @param TenantService $tenantService Platform admin check.
	 * @param LoggerInterface $logger Logger.
	 * @param TenantAuditTrailService $auditTrail Writes each decision to the tenant's audit trail.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly TenantContext $context,
		private readonly TenantAuthenticationService $authService,
		private readonly TenantService $tenantService,
		private readonly LoggerInterface $logger,
		private readonly TenantAuditTrailService $auditTrail,
	) {
	}//end __construct()

	/**
	 * Enforce the mandate matrix for the bound tenant before the controller runs.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 *
	 * @return void
	 *
	 * @throws MandateDeniedException When the action is denied, or the organisation is not active.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $methodName is fixed by
	 * OCP\AppFramework\Middleware::beforeController(); this middleware
	 * dispatches on the controller class and the request URI instead.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function beforeController($controller, $methodName): void {
		$user = $this->userSession->getUser();
		if ($user === null || $this->context->isBound() === false) {
			return;
		}

		$userId = $user->getUID();
		$this->refuseAnOrganisationThatIsNotActive(controller: $controller, userId: $userId);

		$verb = strtoupper($this->request->getMethod());
		$action = $this->resolveAction(verb: $verb, path: $this->request->getRequestUri());
		if ($action === null) {
			return;
		}
		$tenantId = $this->context->getTenantId();

		$decision = $this->authService->validateMandateMatrix(
			tenantId: $tenantId,
			userId: $userId,
			action: $action
		);

		$this->logDecision(tenantId: $tenantId, userId: $userId, action: $action, decision: $decision);

		if ($decision['allowed'] === false) {
			throw new MandateDeniedException(
				(string)$decision['reason'],
				403
			);
		}
	}//end beforeController()

	/**
	 * Translate a mandate denial to 403, and a mandate check that could not run
	 * to the status its refusal carries.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 * @param \Exception $exception Exception.
	 *
	 * @return \OCP\AppFramework\Http\Response
	 *
	 * @throws \Exception When not owned by this middleware.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::afterException(); only $exception is
	 * inspected.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
	 */
	public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response {
		if ($exception instanceof MandateDeniedException) {
			$answer = ['success' => false, 'error' => $exception->getMessage()];
			if ($exception->getLifecycleStatus() !== '') {
				$answer['status'] = $exception->getLifecycleStatus();
			}

			return new JSONResponse($answer, 403);
		}

		// A mandate check that could not run is not a denial. It used to leave
		// here as 403 "No active mandate matrix for tenant", which reads as a
		// decision about the caller rather than a failure of the store.
		if ($exception instanceof RefusedException) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => $exception->getSentence(),
					'error' => $exception->getRule(),
					'code' => $exception->getMessage(),
				],
				$exception->getStatus()
			);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Refuse a request whose organisation is not `active` (REQ-TAO-003).
	 *
	 * For a signed-in user who is not a platform admin, on a controller outside
	 * the exempt list. The status is the stored Organisation's. An empty status
	 * reads as `active`, as OpenRegister's own serialisation reads it and as
	 * `TenantMiddleware` read it.
	 *
	 * @param object $controller The dispatched controller.
	 * @param string $userId     The signed-in uid.
	 *
	 * @return void
	 *
	 * @throws MandateDeniedException When the organisation is not active.
	 */
	private function refuseAnOrganisationThatIsNotActive(object $controller, string $userId): void {
		if (in_array(get_class($controller), self::LIFECYCLE_EXEMPT_CONTROLLERS, true) === true) {
			return;
		}

		if ($this->tenantService->isPlatformAdmin($userId) === true) {
			return;
		}

		$status = $this->context->getStatus();
		if ($status === '' || $status === 'active') {
			return;
		}

		$this->logger->info(
			'Dossiq: request refused because the organisation is not active',
			['userId' => $userId, 'tenantId' => $this->context->getTenantId(), 'status' => $status]
		);

		throw (new MandateDeniedException(message: 'Organisation is '.$status, code: 403))->withLifecycleStatus(status: $status);
	}//end refuseAnOrganisationThatIsNotActive()

	/**
	 * Resolve the matrix action key for the request.
	 *
	 * @param string $verb HTTP verb.
	 * @param string $path Request URI.
	 *
	 * @return string|null Action or null when no mandate gate applies.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
	 */
	public function resolveAction(string $verb, string $path): ?string {
		foreach (self::STATUS_PATH_HINTS as $hint) {
			if (str_contains($path, $hint) === true) {
				return 'status_update';
			}
		}

		return (self::VERB_ACTION_MAP[$verb] ?? null);
	}//end resolveAction()

	/**
	 * Audit-log a mandate decision (allow + deny).
	 *
	 * The decision becomes a row on OpenRegister's audit trail of the tenant's
	 * anchor (REQ-TOO-006), written by `TenantAuditTrailService`, which writes
	 * no row and logs an error when the tenant has no anchor. The log line
	 * stays for the SIEM stream.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $userId NC user ID.
	 * @param string $action Action.
	 * @param array{allowed:bool,reason:string} $decision Decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	private function logDecision(string $tenantId, string $userId, string $action, array $decision): void {
		$this->auditTrail->emit(
			[
				'action' => 'mandate.'.$action.'.'.($decision['allowed'] === true ? 'allowed' : 'denied'),
				'actor' => $userId,
				'resource' => $this->request->getRequestUri(),
				'tenantId' => $tenantId,
			]
		);

		$this->logger->info(
			'Dossiq mandate decision',
			[
				'tenantId' => $tenantId,
				'userId' => $userId,
				'action' => $action,
				'allowed' => (bool)$decision['allowed'],
				'reason' => (string)$decision['reason'],
			]
		);
	}//end logDecision()
}//end class
