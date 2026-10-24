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
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Lists deleted cases, restores one, and destroys one as a second act.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — the recycle, destruction,
 * retention and access services each own one rule of the gesture; the
 * translator says the list's window note and a refusal in the reader's language.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — constructor DI: every
 * parameter is a distinct collaborator used by this controller.
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
	 * @param IGroupManager $groupManager Reads whether the caller is an administrator
	 * @param IUserSession $userSession The current session
	 * @param LoggerInterface $logger The logger
	 * @param IL10N $l10n The translator, for the sentence a refusal is said in
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseRecycleService $recycle,
		private readonly CaseDestructionService $destruction,
		private readonly RetentionClocks $clocks,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The cases this caller deleted, or handled, and can still recover.
	 *
	 * THE LENS IS SCOPED TO THE CALLER. The trash holds every deleted case on
	 * the instance, so an unscoped lens would hand any signed-in user the
	 * number and title of every bezwaar anybody ever deleted. There is no
	 * per-object guard to run here because there is no object in the request,
	 * so the authority is resolved once and handed to the read: an
	 * administrator sees everything, and everybody else sees the cases they
	 * were assigned or deleted themselves.
	 *
	 * @return JSONResponse The rows, each with the date its window ends.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function deleted(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$limit = (int)$this->request->getParam('limit', CaseRecycleService::PAGE_SIZE);
		$offset = (int)$this->request->getParam('offset', 0);
		$search = (string)$this->request->getParam('search', '');

		try {
			// `=== true` and not a cast: IGroupManager::isAdmin() carries no
			// return type, so a double that answers null would otherwise reach
			// a bool parameter and turn a read into a 500.
			$isAdmin = ($this->groupManager->isAdmin($user->getUID()) === true);

			$page = $this->recycle->deletedCases(
				limit: $limit,
				offset: $offset,
				forUser: $user->getUID(),
				isAdmin: $isAdmin,
				search: $search
			);
			$page['results'] = array_map(
				fn (array $row): array => ($row + ['windowNote' => $this->windowNote(row: $row)]),
				($page['results'] ?? [])
			);

			return new JSONResponse($page);
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

			return new JSONResponse(
				['error' => 'The case does not allow this', 'code' => $code, 'message' => $this->refusalSentence(code: $code)],
				$status
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a recycle gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId]
			);

			return new JSONResponse(['error' => 'Could not change the case'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end answered()

	/**
	 * What is left of a row's recovery window, as the list says it under the date.
	 *
	 * @param array<string, mixed> $row The deleted case row
	 *
	 * @return string The note, or an empty string when the window is unknown
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	private function windowNote(array $row): string {
		if (($row['lapsed'] ?? null) === true) {
			return $this->l10n->t('the window has passed');
		}

		if (is_int($row['daysRemaining'] ?? null) === false) {
			return '';
		}

		return $this->l10n->n('%n day left', '%n days left', $row['daysRemaining']);
	}//end windowNote()

	/**
	 * The sentence a refused gesture is said in, in the reader's language.
	 *
	 * @param string $code The refusal code
	 *
	 * @return string The sentence
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	private function refusalSentence(string $code): string {
		return match ($code) {
			'case_not_deleted' => $this->l10n->t('This case is not in the recovery window.'),
			'destroy_role_undeclared' => $this->l10n->t('This case type names no role that may destroy a case. Declare one on the case type first.'),
			'destroy_role_missing' => $this->l10n->t('You do not hold the role this case type requires to destroy a case.'),
			'retention_clocks_disagree' => $this->l10n->t(
				'The lawful purpose of this case has ended while its archive period has not. Somebody has to decide which rule wins.'
			),
			'recovery_window_open' => $this->l10n->t('This case can still be recovered, so it is not destroyed yet.'),
			default => $this->l10n->t('The case does not allow this.'),
		};
	}//end refusalSentence()
}//end class
