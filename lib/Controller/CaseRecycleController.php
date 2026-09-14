<?php

/**
 * The deleted side of a case: the lens, the restore and the destruction.
 *
 *  - GET  /api/cases/deleted                        the deleted lens
 *  - POST /api/case/{caseId}/restore                get a case back
 *  - GET  /api/case/{caseId}/destruction-preview    what a destruction takes
 *  - POST /api/case/{caseId}/destroy                the second act
 *  - GET  /api/case/{caseId}/retention-clocks       the two clocks, apart
 *
 * These live beside {@see CaseLifecycleController} rather than on it, because
 * every gesture here acts on a case that is already in OpenRegister's recycle
 * state. `CaseAccessGuard` reads the live case to decide, and a deleted case is
 * gone from that read, so it would deny every one of them. The authority here
 * is the destroying role the case type declares, which is the control
 * C-documents-20 asks for and the one an auditor can check afterwards.
 *
 * Refusals carry a short static `code` the page turns into a sentence, the
 * same contract the lifecycle gestures use.
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
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Recycle\CaseDestructionService;
use OCA\Dossiq\Service\Recycle\CaseRecycleService;
use OCA\Dossiq\Service\Recycle\RetentionClocks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Lists deleted cases, restores one, and destroys one as a second act.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
class CaseRecycleController extends Controller {

	/**
	 * The refusals that map to something other than 403.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'case_not_deleted' => Http::STATUS_NOT_FOUND,
		'retention_clocks_disagree' => Http::STATUS_CONFLICT,
		'recovery_window_open' => Http::STATUS_CONFLICT,
		'openregister_unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param CaseRecycleService $recycle The window, the lens and the restore
	 * @param CaseDestructionService $destruction The destroying role and the act
	 * @param RetentionClocks $clocks The two clocks, read apart
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization for the clocks read
	 * @param IUserSession $userSession The current session
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseRecycleService $recycle,
		private readonly CaseDestructionService $destruction,
		private readonly RetentionClocks $clocks,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The cases that were deleted and can still be recovered.
	 *
	 * @return JSONResponse The rows, each with the date its window ends.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function deleted(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$limit = (int)$this->request->getParam('limit', CaseRecycleService::PAGE_SIZE);
		$offset = (int)$this->request->getParam('offset', 0);

		try {
			return new JSONResponse($this->recycle->deletedCases(limit: $limit, offset: $offset));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: the deleted lens failed: ' . $e->getMessage());

			return new JSONResponse(
				['error' => 'Could not read the deleted cases'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end deleted()

	/**
	 * Get a deleted case back, inside its window.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function restore(string $caseId): JSONResponse {
		return $this->answered(
			run: fn (): array => $this->recycle->restore(caseId: $caseId),
			caseId: $caseId
		);
	}//end restore()

	/**
	 * What a destruction of this case would take with it.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function destructionPreview(string $caseId): JSONResponse {
		return $this->answered(
			run: fn (): array => $this->destruction->preview(caseId: $caseId),
			caseId: $caseId
		);
	}//end destructionPreview()

	/**
	 * Destroy a deleted case. A second act, recorded, with a role behind it.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function destroy(string $caseId): JSONResponse {
		$waive = filter_var($this->request->getParam('waiveWindow', false), FILTER_VALIDATE_BOOLEAN);

		return $this->answered(
			run: fn (): array => $this->destruction->destroy(caseId: $caseId, waiveWindow: $waive),
			caseId: $caseId
		);
	}//end destroy()

	/**
	 * The lawful-purpose clock and the archive clock of a live case.
	 *
	 * Guarded per case, because this is a read of a case that still exists and
	 * the ordinary case authority applies to it.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function clocks(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$case = $this->recycle->liveCase(caseId: $caseId);
			if ($case === null) {
				return new JSONResponse(['error' => 'The case does not allow this', 'code' => 'case_not_found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->clocks->clocksFor(case: $case));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not read the retention clocks: ' . $e->getMessage());

			return new JSONResponse(
				['error' => 'Could not read the retention clocks'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end clocks()

	/**
	 * Run one gesture behind the session, and turn a refusal into a code.
	 *
	 * @param callable(): array<string, mixed> $run The gesture
	 * @param string $caseId The case UUID, for the log line
	 *
	 * @return JSONResponse
	 */
	private function answered(callable $run, string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			$code = $e->getMessage();
			$status = (self::REFUSAL_STATUS[$code] ?? Http::STATUS_FORBIDDEN);
			$this->logger->info('Dossiq: a recycle gesture was refused', ['code' => $code, 'caseId' => $caseId]);

			return new JSONResponse(['error' => 'The case does not allow this', 'code' => $code], $status);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a recycle gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId]
			);

			return new JSONResponse(['error' => 'Could not change the case'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end answered()
}//end class
