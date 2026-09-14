<?php

/**
 * Dossiq acknowledgement-of-receipt controller.
 *
 * Two gestures on one case:
 *
 *  - GET  /api/case/{caseId}/acknowledgement      — is the Awb 4:3a duty met
 *  - POST /api/case/{caseId}/acknowledgement/met  — a person says it was, another way
 *
 * WHY THE SECOND ONE EXISTS. An acknowledgement that failed every retry is a
 * statutory duty somebody has to perform by hand, and once they have — by post,
 * by telephone, at the counter — the case has to be able to say so. Without
 * that, the only way to clear an unmet duty is to leave it unmet forever or to
 * edit the field directly, and neither records who decided.
 *
 * Both methods decide from what is STORED on the case, and both are guarded per
 * case: `#[NoAdminRequired]` on its own would let any authenticated user read
 * and clear the duty on any case id they can guess.
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Did we confirm receipt, and did somebody do it another way.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */
class AcknowledgementController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName         The app name.
	 * @param IRequest               $request         The HTTP request.
	 * @param AcknowledgementService $acknowledgement The acknowledgement of receipt.
	 * @param CaseAccessGuard        $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession           $userSession     The current session.
	 * @param IL10N                  $l10n            The translations.
	 * @param LoggerInterface        $logger          The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AcknowledgementService $acknowledgement,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether this case confirmed receipt, when, to whom and by which channel.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{duty: object}`.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	#[NoAdminRequired]
	public function duty(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You cannot read this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(['duty' => $this->acknowledgement->dutyFor(caseId: $caseId)]);
	}//end duty()

	/**
	 * Record that receipt was confirmed another way.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{duty: object}`, or the refusal.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	#[NoAdminRequired]
	public function recordMet(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You cannot change this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$duty = $this->acknowledgement->recordMetAnotherWay(
				caseId: $caseId,
				how: (string)$this->request->getParam('how', ''),
				by: $user->getUID(),
			);
		} catch (RefusedException $e) {
			// The rule slug and the sentence, never the exception message:
			// a surface acts on the slug and a person reads the sentence.
			return new JSONResponse(
				['error' => $e->getRule(), 'message' => $e->getSentence()],
				$e->getStatus()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq acknowledgement: recording a manual confirmation on case {case} failed',
				['case' => $caseId, 'reason' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => $this->l10n->t('The confirmation could not be recorded.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse(['duty' => $duty]);
	}//end recordMet()
}//end class
