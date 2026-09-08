<?php

/**
 * Dossiq Case Actions Controller.
 *
 * Three things a handler can do TO a case from its own page, beside moving it
 * through its lifecycle:
 *
 *  - POST /api/case/{caseId}/copy            (body {title?, documents?})
 *  - GET  /api/case/{caseId}/startable-flows
 *  - POST /api/case/{caseId}/plan            (body {caseType, date, title})
 *  - GET  /api/case/{caseId}/planned
 *
 * Every method is `#[NoAdminRequired]` and every method guards the case first.
 * Without the per-case guard these would be four ways for any signed-in user
 * to act on any case by its uuid, which is the IDOR shape ADR-005 rule 3 names.
 * The guard runs BEFORE the work, so a refused caller never learns whether the
 * case exists.
 *
 * The two reads guard on READ access and the two writes on MUTATION access,
 * deliberately: listing what you could start is not starting it, but planning
 * a follow-up and copying a case both create records.
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
use OCA\Dossiq\Service\CaseCopyService;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
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
 * Copy a case, start an allowed flow for it, and plan a follow-up.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
class CaseActionsController extends Controller {

	/**
	 * The refusals that map to something other than 400.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'case_not_found' => Http::STATUS_NOT_FOUND,
		'storage_unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
		'flows_unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
		'copy_failed' => Http::STATUS_INTERNAL_SERVER_ERROR,
		'plan_failed' => Http::STATUS_INTERNAL_SERVER_ERROR,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The HTTP request.
	 * @param CaseCopyService $copyService Copies a case.
	 * @param CaseFlowActions $flowActions Starts a flow and plans a follow-up.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession $userSession The current session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseCopyService $copyService,
		private readonly CaseFlowActions $flowActions,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Copy the case into a new case of the same type.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse The new case.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function copy(string $caseId): JSONResponse {
		$title = (string)$this->request->getParam('title', '');
		$documents = $this->flag(value: $this->request->getParam('documents', false));

		return $this->guarded(
			caseId: $caseId,
			write: true,
			run: fn (): array => $this->copyService->copy(
				caseId: $caseId,
				options: ['title' => $title, 'documents' => $documents]
			),
		);
	}//end copy()

	/**
	 * The flows this case's type allows a handler to start.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{results, total}`.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function startableFlows(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			write: false,
			run: fn (): array => $this->flowActions->startableFlows(caseId: $caseId),
		);
	}//end startableFlows()

	/**
	 * Plan a follow-up case for a later date.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse The planned follow-up.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function plan(string $caseId): JSONResponse {
		$caseType = (string)$this->request->getParam('caseType', '');
		$date = (string)$this->request->getParam('date', '');
		$title = (string)$this->request->getParam('title', '');

		return $this->guarded(
			caseId: $caseId,
			write: true,
			run: function () use ($caseId, $caseType, $date, $title): array {
				$user = $this->userSession->getUser();

				return $this->flowActions->plan(
					caseId: $caseId,
					caseTypeId: $caseType,
					date: $date,
					title: $title,
					uid: ($user === null ? '' : $user->getUID())
				);
			},
		);
	}//end plan()

	/**
	 * The follow-ups planned for this case that have not been created yet.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{results, total}`.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	#[NoAdminRequired]
	public function planned(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			write: false,
			run: fn (): array => $this->flowActions->planned(caseId: $caseId),
		);
	}//end planned()

	/**
	 * Read a request flag that may arrive as a JSON boolean or a query string.
	 *
	 * `(bool) 'false'` is TRUE, so a bare cast would turn every "no" into a
	 * yes — which on the copy endpoint means linking documents nobody asked
	 * for.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return boolean What it meant.
	 */
	private function flag(mixed $value): bool {
		return ($value === true || $value === 'true' || $value === '1' || $value === 1);
	}//end flag()

	/**
	 * Run one gesture behind the session and per-case guards.
	 *
	 * @param string $caseId The case UUID.
	 * @param boolean $write Whether the gesture writes, and so needs mutation access.
	 * @param callable(): array<string, mixed> $run The gesture.
	 *
	 * @return JSONResponse The gesture's answer, or a refusal.
	 */
	private function guarded(string $caseId, bool $write, callable $run): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->allowed(caseId: $caseId, user: $user, write: $write) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			$code = $e->getMessage();
			$status = (self::REFUSAL_STATUS[$code] ?? Http::STATUS_BAD_REQUEST);
			$this->logger->info('CaseActionsController: gesture refused', ['code' => $code, 'caseId' => $caseId]);

			return new JSONResponse(['error' => 'The case does not allow this', 'code' => $code], $status);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseActionsController: gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);

			return new JSONResponse(['error' => 'Could not act on the case'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end guarded()

	/**
	 * Whether this user may run this gesture on this case.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser $user The caller.
	 * @param boolean $write Whether the gesture writes.
	 *
	 * @return boolean True when the guard allows it.
	 */
	private function allowed(string $caseId, IUser $user, bool $write): bool {
		if ($write === true) {
			return $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user);
		}

		return $this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user);
	}//end allowed()
}//end class
