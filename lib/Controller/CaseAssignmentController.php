<?php

/**
 * Dossiq Case Assignment Controller.
 *
 * Claim and release on the case page and in the queue:
 *
 *  - GET  /api/case/{caseId}/assignment
 *  - POST /api/case/{caseId}/claim
 *  - POST /api/case/{caseId}/release
 *
 * Every method is `#[NoAdminRequired]`, and every method decides from what is
 * STORED on the case rather than from anything the caller sent. Claim is
 * refused when the case already has a handler, release when the caller is not
 * that handler. The refusals carry a short static `code` beside the sentence,
 * so a surface can act on the rule without parsing prose.
 *
 * The endpoint is not a wrapper around an object write. The write itself is
 * one field, and OpenRegister would happily take it from the browser; what
 * cannot be done there is the read-compare-write that makes a claim refuse
 * when someone else got there first.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAssignmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Take a case, and give it back.
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */
class CaseAssignmentController extends Controller {

	/**
	 * The status each refusal answers with.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'case_not_found' => Http::STATUS_NOT_FOUND,
		'already_assigned' => Http::STATUS_CONFLICT,
		'already_yours' => Http::STATUS_CONFLICT,
		'not_assigned' => Http::STATUS_CONFLICT,
		'not_yours' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param string                $appName         The app name.
	 * @param IRequest              $request         The HTTP request.
	 * @param CaseAssignmentService $assignment      Claim and release.
	 * @param CaseAccessGuard       $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession          $userSession     The current session.
	 * @param IL10N                 $l10n            The translations.
	 * @param LoggerInterface       $logger          The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseAssignmentService $assignment,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Who holds this case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function state(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $this->answer(
			caseId: $caseId,
			run: fn (): array => $this->assignment->state(caseId: $caseId, userId: $user->getUID()),
		);
	}//end state()

	/**
	 * Take this case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function claim(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$refusal = $this->requireClaimableCase(caseId: $caseId, user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->answer(
			caseId: $caseId,
			run: fn (): array => $this->assignment->claim(caseId: $caseId, userId: $user->getUID()),
		);
	}//end claim()

	/**
	 * Give this case back to the queue.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function release(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You can only release a case you handle yourself.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return $this->answer(
			caseId: $caseId,
			run: fn (): array => $this->assignment->release(caseId: $caseId, userId: $user->getUID()),
		);
	}//end release()

	/**
	 * The per-case guard for a claim, decided from STORED state.
	 *
	 * A claim is the one gesture the case's own `assignee` cannot authorize by
	 * itself: the person claiming is by definition not the handler yet. So the
	 * question this asks is not "do you handle this case" but "is this case
	 * free", and the answer comes from the stored record read as the signed-in
	 * user, which is where OpenRegister's RBAC applies (ADR-022, ADR-023). A
	 * case this person may not see does not resolve, and answers the same
	 * `case_not_found` a missing case does.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser  $user   The signed-in user.
	 *
	 * @return JSONResponse|null The refusal, or null when the case is free to take.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function requireClaimableCase(string $caseId, IUser $user): ?JSONResponse {
		try {
			$state = $this->assignment->state(caseId: $caseId, userId: $user->getUID());
		} catch (RuntimeException $e) {
			return $this->refusal(code: $e->getMessage(), caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseAssignmentController: could not read the assignment',
				['exception' => $e->getMessage(), 'caseId' => $caseId]
			);

			return new JSONResponse(
				['error' => $this->l10n->t('The case could not be read.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($state['claimable'] === true) {
			return null;
		}

		$code = 'already_assigned';
		if ($state['mine'] === true) {
			$code = 'already_yours';
		}

		return $this->refusal(code: $code, caseId: $caseId);
	}//end requireClaimableCase()

	/**
	 * Run a gesture and answer it, turning a refusal into its status.
	 *
	 * @param string                          $caseId The case UUID.
	 * @param callable(): array<string, mixed> $run    The gesture.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function answer(string $caseId, callable $run): JSONResponse {
		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			return $this->refusal(code: $e->getMessage(), caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseAssignmentController: the gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId]
			);

			return new JSONResponse(
				['error' => $this->l10n->t('The assignment could not be changed.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end answer()

	/**
	 * One refusal, as a sentence a handler reads and a code a surface acts on.
	 *
	 * @param string $code   The rule that refused.
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function refusal(string $code, string $caseId): JSONResponse {
		$this->logger->info(
			'CaseAssignmentController: the gesture was refused',
			['code' => $code, 'caseId' => $caseId]
		);

		$message = match ($code) {
			'case_not_found' => $this->l10n->t('This case could not be found.'),
			'already_assigned' => $this->l10n->t('Someone else is already handling this case.'),
			'already_yours' => $this->l10n->t('You are already handling this case.'),
			'not_assigned' => $this->l10n->t('Nobody is handling this case.'),
			'not_yours' => $this->l10n->t('You can only release a case you handle yourself.'),
			default => $this->l10n->t('The assignment could not be changed.'),
		};

		return new JSONResponse(
			['error' => $message, 'code' => $code],
			(self::REFUSAL_STATUS[$code] ?? Http::STATUS_BAD_REQUEST)
		);
	}//end refusal()
}//end class
