<?php

/**
 * The goals of a case: what the requester wants to change, and what counts as met.
 *
 * Every endpoint checks the case itself before it touches the goal store: a
 * read needs read access to the case, a write needs the right to update it.
 * The case comes from the URL, never from the body, so a goal cannot be
 * written onto a case the caller may not change.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-goals/specs/case-goals/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\SociaalDomein\CasePlanGoals;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and add the goals of one case.
 *
 * @spec openspec/changes/case-goals/specs/case-goals/spec.md
 */
class CaseGoalController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request         Inbound request.
	 * @param IUserSession      $userSession     Current user session.
	 * @param CaseAccessGuard   $caseAccessGuard Per-case authorization (fails closed).
	 * @param CasePlanGoals     $goals           The goal store's rules.
	 * @param PauseReasonReader $caseTypes       The one walk from a case to its case type.
	 * @param LoggerInterface   $logger          Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly CasePlanGoals $goals,
		private readonly PauseReasonReader $caseTypes,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The case's goals, and the case type's prompt for a case without any.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`, before the read.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return JSONResponse `{ results, prompt }`, or the refusal.
	 *
	 * @spec openspec/changes/case-goals/specs/case-goals/spec.md#requirement-goals-are-read-and-written-through-the-cases-own-access
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			$caseType = $this->caseTypes->caseTypeForCase(caseId: $caseId);

			return new JSONResponse(
				[
					'results' => $this->goals->ofCase(caseId: $caseId),
					'prompt' => trim((string)($caseType['goalPrompt'] ?? '')),
				]
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-goals', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-goals', e: $e);
		}
	}//end index()

	/**
	 * Add a goal to the case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`, before the write.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return JSONResponse The stored goal, or the refusal.
	 *
	 * @spec openspec/changes/case-goals/specs/case-goals/spec.md#requirement-goals-are-read-and-written-through-the-cases-own-access
	 */
	#[NoAdminRequired]
	public function create(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse(
				$this->goals->save(
					goal: [
						'case' => $caseId,
						'title' => (string)$this->request->getParam('title', ''),
						'metWhen' => (string)$this->request->getParam('metWhen', ''),
						'state' => 'open',
					]
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case-goal-save', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'case-goal-save', e: $e);
		}
	}//end create()

	/**
	 * A failure that is not a refusal, logged and answered as one.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/case-goals/specs/case-goals/spec.md
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error('CaseGoalController: ' . $op . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(
			['message' => 'That did not work.', 'error' => 'case-goals-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
