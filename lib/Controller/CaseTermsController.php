<?php

/**
 * Dossiq CaseTermsController.
 *
 * The four clocks on a case, the act that asks and suspends, and the age of the
 * open workload.
 *
 * A separate controller from {@see TermijnController} on purpose. That one is
 * addressed by TERM INSTANCE and is the published `/api/termijn/instances/*`
 * contract; this one is addressed by CASE, which is what a case page holds. A
 * page that had to find the instance first would have to know there is exactly
 * one, and there are four.
 *
 * Every refusal comes back as `{message, error}` with the status the rule chose,
 * through {@see Support\TranslatesRefusals}, per ADR-050.
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\OpenWorkloadAgeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reading and moving the clocks on one case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
class CaseTermsController extends Controller {
	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param CaseTermsService $terms The four clocks on a case.
	 * @param InformationRequestService $requests Asking and suspending as one act.
	 * @param OpenWorkloadAgeService $workload The age of what is still standing.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTermsService $terms,
		private readonly InformationRequestService $requests,
		private readonly OpenWorkloadAgeService $workload,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every clock on one case, each naming its kind, with the progress and the
	 * days left computed here and stored nowhere.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($this->terms->overviewFor(caseId: $caseId));
		} catch (RefusedException $e) {
			return $this->refused(op: 'read the terms on a case', e: $e);
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms index failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The terms on this case could not be read.', 'error' => 'terms-unreadable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}//end try
	}//end index()

	/**
	 * The clocks a citizen may be shown, which is the statutory term and
	 * nothing else (REQ-TERM-063).
	 *
	 * A separate endpoint rather than a flag on the one above, because a filter
	 * a caller has to remember to apply is a filter somebody forgets, and the
	 * thing forgotten here is a team target the applicant was never promised.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	#[NoAdminRequired]
	public function citizen(string $caseId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse(['case' => $caseId, 'terms' => $this->terms->citizenTermsFor(caseId: $caseId)]);
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms citizen view failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The term on this case could not be read.', 'error' => 'terms-unreadable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end citizen()

	/**
	 * Ask the applicant for what is missing and suspend the term, as one act.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	#[NoAdminRequired]
	public function requestInformation(string $caseId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();

		try {
			$result = $this->requests->ask(
				caseId: $caseId,
				items: (array)($body['items'] ?? []),
				recipient: (string)($body['recipient'] ?? ''),
				durationDays: (int)($body['durationDays'] ?? 14),
				rationale: (string)($body['rationale'] ?? ''),
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'ask the applicant for information', e: $e);
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms information request failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The request could not be made.', 'error' => 'information-request-failed'],
				Http::STATUS_BAD_REQUEST
			);
		}//end try

		if ($result['sent'] === false) {
			// 502 and not 200: the handler asked for one act and got none of
			// it. Answering 200 with `sent: false` is how a failed letter is
			// read as a successful pause by whatever renders the response next.
			return new JSONResponse($result, Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($result);
	}//end requestInformation()

	/**
	 * The aanvulling arrived: resume the term and record what came in.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	#[NoAdminRequired]
	public function receiveInformation(string $caseId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();

		try {
			return new JSONResponse(
				$this->requests->receive(caseId: $caseId, items: (array)($body['items'] ?? []))
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'record the aanvulling', e: $e);
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms aanvulling failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The aanvulling could not be recorded.', 'error' => 'aanvulling-failed'],
				Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end receiveInformation()

	/**
	 * How old the open workload is right now, per status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
	 */
	#[NoAdminRequired]
	public function workloadAge(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($this->workload->report());
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms workload age failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The workload age could not be read.', 'error' => 'workload-age-unreadable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end workloadAge()

	/**
	 * Refuse an anonymous caller.
	 *
	 * The per-object check is the store's: every read below goes through
	 * OpenRegister, which answers only with the cases the caller may see. This
	 * guard is the one thing the store cannot do, which is to refuse a request
	 * carrying no session at all even when a route attribute is misconfigured.
	 *
	 * @return JSONResponse|null The refusal, or null when a user is signed in.
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['message' => 'Sign in to read the terms on a case.', 'error' => 'not-authenticated'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end ensureAuthenticated()

	/**
	 * Decode the JSON request body into an array.
	 *
	 * @return array<string, mixed> The body, empty when there is none.
	 */
	private function jsonBody(): array {
		$raw = (string)file_get_contents('php://input');
		$body = json_decode($raw, true);

		return ((is_array($body) === true) ? $body : []);
	}//end jsonBody()
}//end class
