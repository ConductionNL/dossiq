<?php

/**
 * Dossiq Case Attention Controller.
 *
 * The attention flag a person raises, on three endpoints:
 *
 *  - GET  /api/case/{caseId}/attention        where the flag stands, and every
 *                                             raising and clearing before it
 *  - POST /api/case/{caseId}/attention/raise  raise the flag, with a reason
 *  - POST /api/case/{caseId}/attention/clear  clear the flag, with a reason
 *
 * WHY THE WRITES ARE NOT AN OBJECT PATCH FROM THE BROWSER. The rule is that
 * neither act is possible without a reason, and that clearing does not delete
 * the raising. A browser doing its own write can satisfy neither: it can skip
 * the reason, and it can send a history array with the earlier rows missing.
 * So the append and the refusal live here, and the browser sends a sentence.
 *
 * THE OTHER TWO FACTS HAVE NO ENDPOINT, DELIBERATELY. The risk assessment and
 * the marker set are properties OF the case: the assessment because the
 * permission that guards it is declared on the property and OpenRegister
 * filters it out before any dossiq code sees it, and the markers because they
 * are derived into the save itself. Both therefore arrive with the case the
 * page already read, and a second endpoint over them would be the wrapper
 * ADR-022 exists to prevent.
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
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAttentionFlagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raise and clear the attention flag, and read what a case is saying.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseAttentionController extends Controller {

	/**
	 * The status each refusal answers with.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'case_not_found' => Http::STATUS_NOT_FOUND,
		'reason_required' => Http::STATUS_BAD_REQUEST,
		'already_raised' => Http::STATUS_CONFLICT,
		'not_raised' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param string                   $appName         The app name.
	 * @param IRequest                 $request         The HTTP request.
	 * @param CaseAttentionFlagService $flags           Raise and clear.
	 * @param CaseAccessGuard          $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession             $userSession     The current session.
	 * @param IL10N                    $l10n            The translations.
	 * @param LoggerInterface          $logger          The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseAttentionFlagService $flags,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What this case is saying, and what it has said before.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function state(string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->notSignedIn();
		}

		return $this->answer(
			caseId: $caseId,
			run: fn (): array => $this->flags->state(caseId: $caseId),
		);
	}//end state()

	/**
	 * Raise the flag on this case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it needs attention.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function raise(string $caseId, string $reason = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->notYours();
		}

		return $this->answer(
			caseId: $caseId,
			run: fn (): array => $this->flags->raise(
				caseId: $caseId,
				userId: $user->getUID(),
				reason: $reason
			),
		);
	}//end raise()

	/**
	 * Clear the flag on this case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it no longer needs attention.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function clear(string $caseId, string $reason = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->notYours();
		}

		return $this->answer(
			caseId: $caseId,
			run: fn (): array => $this->flags->clear(
				caseId: $caseId,
				userId: $user->getUID(),
				reason: $reason
			),
		);
	}//end clear()

	/**
	 * Run a gesture and answer it, turning a refusal into its status.
	 *
	 * @param string                           $caseId The case UUID.
	 * @param callable(): array<string, mixed> $run    The gesture.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $caseId, callable $run): JSONResponse {
		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			return $this->refusal(code: $e->getMessage(), caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseAttentionController: the gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId]
			);

			return new JSONResponse(
				['error' => $this->l10n->t('The attention flag could not be changed.')],
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
	 */
	private function refusal(string $code, string $caseId): JSONResponse {
		$this->logger->info(
			'CaseAttentionController: the gesture was refused',
			['code' => $code, 'caseId' => $caseId]
		);

		$message = match ($code) {
			'case_not_found' => $this->l10n->t('This case could not be found.'),
			'reason_required' => $this->l10n->t('Write down why, and the flag will change. Both raising and clearing keep the reason.'),
			'already_raised' => $this->l10n->t('This case is already flagged as needing attention.'),
			'not_raised' => $this->l10n->t('This case is not flagged as needing attention.'),
			default => $this->l10n->t('The attention flag could not be changed.'),
		};

		return new JSONResponse(
			['error' => $message, 'code' => $code],
			(self::REFUSAL_STATUS[$code] ?? Http::STATUS_BAD_REQUEST)
		);
	}//end refusal()

	/**
	 * The answer for a caller with no session.
	 *
	 * @return JSONResponse
	 */
	private function notSignedIn(): JSONResponse {
		return new JSONResponse(
			['error' => $this->l10n->t('You are not signed in.')],
			Http::STATUS_UNAUTHORIZED
		);
	}//end notSignedIn()

	/**
	 * The answer for a caller who may read this case but not change it.
	 *
	 * @return JSONResponse
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['error' => $this->l10n->t('You cannot change the attention flag on this case.')],
			Http::STATUS_FORBIDDEN
		);
	}//end notYours()
}//end class
