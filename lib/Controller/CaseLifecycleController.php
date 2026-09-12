<?php

/**
 * Dossiq Case Lifecycle Controller.
 *
 * The Actions menu on the case page: suspend, resume, extend and reopen, plus
 * the read the page uses to decide which of them are honest to offer.
 *
 *  - GET  /api/case/{caseId}/lifecycle
 *  - POST /api/case/{caseId}/suspend   (body {reason, days?})
 *  - POST /api/case/{caseId}/resume    (body {reason})
 *  - POST /api/case/{caseId}/extend    (body {reason})
 *  - POST /api/case/{caseId}/reopen    (body {reason})
 *
 * Every method is `#[NoAdminRequired]` and every method guards the case
 * itself: without the per-case guard these would be four ways for any signed-in
 * user to move any case by its uuid. Reopen guards twice — the case, and then
 * the reopen authority, which in this app is the same admin group the
 * engine's free-form transition uses. A closed case is a legal fact; putting
 * it back into handling is not an ordinary handler's gesture.
 *
 * Refusals carry a short static `code` the page turns into a sentence, never
 * the exception's own message.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Suspend, resume, extend and reopen a case.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseLifecycleController extends Controller {

	/**
	 * The refusals that map to something other than 400.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'case_not_found' => Http::STATUS_NOT_FOUND,
		'suspension_not_allowed' => Http::STATUS_CONFLICT,
		'extension_not_allowed' => Http::STATUS_CONFLICT,
		'already_suspended' => Http::STATUS_CONFLICT,
		'not_suspended' => Http::STATUS_CONFLICT,
		'case_not_closed' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param CaseLifecycleService $lifecycle The lifecycle gestures
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 * @param StatusTransitionService $transitionEngine Consulted for the reopen authority
	 * @param IUserSession $userSession The current session
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseLifecycleService $lifecycle,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly StatusTransitionService $transitionEngine,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What the case allows right now.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function state(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->lifecycle->state(caseId: $caseId),
		);
	}//end state()

	/**
	 * Suspend the case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function suspend(string $caseId): JSONResponse {
		$reason = (string)$this->request->getParam('reason', '');
		$days = (int)$this->request->getParam('days', 0);

		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->lifecycle->suspend(caseId: $caseId, reason: $reason, days: $days),
		);
	}//end suspend()

	/**
	 * Resume a suspended case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function resume(string $caseId): JSONResponse {
		$reason = (string)$this->request->getParam('reason', '');

		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->lifecycle->resume(caseId: $caseId, reason: $reason),
		);
	}//end resume()

	/**
	 * Extend the case's term.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function extend(string $caseId): JSONResponse {
		$reason = (string)$this->request->getParam('reason', '');

		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->lifecycle->extend(caseId: $caseId, reason: $reason),
		);
	}//end extend()

	/**
	 * Reopen a closed case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function reopen(string $caseId): JSONResponse {
		$reason = (string)$this->request->getParam('reason', '');

		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->lifecycle->reopen(caseId: $caseId, reason: $reason),
			gate: fn (IUser $user): bool => $this->transitionEngine->isAdmin(userId: $user->getUID()),
		);
	}//end reopen()

	/**
	 * Run one gesture behind the session and per-case guards.
	 *
	 * The guard runs BEFORE the gesture, so a refused caller never reaches the
	 * service and never learns whether the case exists.
	 *
	 * @param string $caseId The case UUID
	 * @param callable(): array<string, mixed> $run The gesture
	 * @param callable(IUser): bool|null $gate An extra authority the gesture needs
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function guarded(string $caseId, callable $run, ?callable $gate = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		if ($gate !== null && $gate($user) === false) {
			return new JSONResponse(['error' => 'Not authorized to reopen this case'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			$code = $e->getMessage();
			$status = (self::REFUSAL_STATUS[$code] ?? Http::STATUS_BAD_REQUEST);
			$this->logger->info('CaseLifecycleController: gesture refused', ['code' => $code, 'caseId' => $caseId]);

			return new JSONResponse(['error' => 'The case does not allow this', 'code' => $code], $status);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseLifecycleController: gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);

			return new JSONResponse(['error' => 'Could not change the case'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end guarded()
}//end class
