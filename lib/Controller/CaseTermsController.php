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
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\ReadsJsonRequests;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTermsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\OpenWorkloadAgeServiceInterface;
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
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/specs/termijn-pause-extension/spec.md
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class CaseTermsController extends Controller {
	use TranslatesRefusals;
	use ReadsJsonRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param CaseTermsServiceInterface $terms The four clocks on a case.
	 * @param OpenWorkloadAgeServiceInterface $workload The age of what is still standing.
	 * @param CaseAccessGuardInterface $guard The per-case check, asked of every endpoint below.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 * @param AanvullingsverzoekServiceInterface $aanvullingen The request as a record.
	 * @param AanvullingsverzoekResolutionServiceInterface $resolution The answer, item by item.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTermsServiceInterface $terms,
		private readonly OpenWorkloadAgeServiceInterface $workload,
		private readonly CaseAccessGuardInterface $guard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly AanvullingsverzoekServiceInterface $aanvullingen,
		private readonly AanvullingsverzoekResolutionServiceInterface $resolution,
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
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		$denied = $this->refuseUnlessMayRead(caseId: $caseId);
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
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	#[NoAdminRequired]
	public function citizen(string $caseId): JSONResponse {
		$denied = $this->refuseUnlessMayRead(caseId: $caseId);
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse(['case' => $caseId, 'terms' => $this->terms->citizenTermsFor(caseId: $caseId)]);
		} catch (RefusedException $e) {
			return $this->refused(op: 'read the term a citizen may see', e: $e);
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms citizen view failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The term on this case could not be read.', 'error' => 'terms-unreadable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}//end try
	}//end citizen()

	/**
	 * Ask the applicant for what is missing and suspend the term, as one act.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	#[NoAdminRequired]
	public function requestInformation(string $caseId): JSONResponse {
		$denied = $this->refuseUnlessMayChange(caseId: $caseId);
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();

		try {
			// ONE ask, and it now writes the record as well as the letter and
			// the suspension. The record is what `aanvullingsverzoek-as-a-record`
			// adds; going through a second endpoint for it would give the app
			// two paths to a statutory suspension, and the second one is always
			// the one that forgets something.
			$record = $this->askForInformation(caseId: $caseId, body: $body);

		} catch (RefusedException $e) {
			return $this->refused(op: 'ask the applicant for information', e: $e);
		} catch (Throwable $e) {
			return $this->informationRequestFailed(error: $e->getMessage());
		}//end try

		// NO `sent: false` BRANCH ANY MORE, and its absence is the improvement.
		// The old service answered 200 with `sent: false` on a letter that did
		// not go out, and the 502 here existed to stop that being read as a
		// successful pause. `AanvullingsverzoekService::ask()` refuses instead,
		// so a failure arrives as a refusal with its own status and this method
		// only ever returns a request that was really sent and really recorded.
		return new JSONResponse(['sent' => true, 'suspended' => true, 'request' => $record]);
	}//end requestInformation()

	/**
	 * Ask the applicant for information: the letter, the suspension and the record.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $body   The request body.
	 *
	 * @return array<string, mixed> The aanvullingsverzoek record.
	 */
	private function askForInformation(string $caseId, array $body): array {
		return $this->aanvullingen->ask(
			caseId: $caseId,
			items: (array)($body['items'] ?? []),
			recipient: (string)($body['recipient'] ?? ''),
			durationDays: (int)($body['durationDays'] ?? 14),
			userId: $this->currentUserId(),
			pauseReason: (string)($body['pauseReason'] ?? ''),
			rationale: (string)($body['rationale'] ?? ''),
			party: (string)($body['party'] ?? ''),
			question: $this->clarificationOf(body: $body),
		);
	}//end askForInformation()

	/**
	 * Log a failed information request and answer it as a bad request.
	 *
	 * @param string $error What went wrong.
	 *
	 * @return JSONResponse
	 */
	private function informationRequestFailed(string $error): JSONResponse {
		$this->logger->warning('CaseTerms information request failed: ' . $error);

		return new JSONResponse(
			['message' => 'The request could not be made.', 'error' => 'information-request-failed'],
			Http::STATUS_BAD_REQUEST
		);
	}//end informationRequestFailed()

	/**
	 * The question of a clarification, or null when the body asks for documents.
	 *
	 * @param array<string, mixed> $body The request body.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-a-clarification-asks-one-question-in-plain-words-req-wds-004
	 */
	private function clarificationOf(array $body): ?string {
		if ((string)($body['kind'] ?? '') !== AanvullingsverzoekServiceInterface::KIND_CLARIFICATION) {
			return null;
		}

		return (string)($body['question'] ?? '');
	}//end clarificationOf()

	/**
	 * The aanvulling arrived: resume the term and record what came in.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	#[NoAdminRequired]
	public function receiveInformation(string $caseId): JSONResponse {
		$denied = $this->refuseUnlessMayChange(caseId: $caseId);
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();

		try {
			// `complete` defaults TRUE so every caller written against the
			// older endpoint keeps its behaviour: it sent what arrived and
			// meant the request was done. A caller that knows better says so,
			// and a partial answer then leaves the request open with its
			// outstanding items named and the term still suspended.
			return new JSONResponse(
				$this->resolution->recordAnswer(
					caseId: $caseId,
					received: (array)($body['items'] ?? []),
					complete: (($body['complete'] ?? true) === true),
					userId: $this->currentUserId(),
				)
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
	 * Every request ever sent on this case, and where each one stands.
	 *
	 * The read that makes "what are we waiting on, and since when, and for
	 * what" answerable from the case rather than from a suspended timer. It
	 * carries the days each open request has been open, computed here and
	 * stored nowhere, so nothing has to be kept in step with the clock.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	#[NoAdminRequired]
	public function aanvullingsverzoeken(string $caseId): JSONResponse {
		$denied = $this->refuseUnlessSignedIn();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$requests = $this->aanvullingen->forCase(caseId: $caseId);
		} catch (RefusedException $e) {
			return $this->refused(op: 'read the requests on this case', e: $e);
		} catch (Throwable $e) {
			return $this->unreadableRequests(exception: $e);
		}//end try

		return $this->requestsAnswer(caseId: $caseId, rows: $this->withDaysOpen(requests: $requests));
	}//end aanvullingsverzoeken()

	/**
	 * The logged 400 for a list of requests that could not be read.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function unreadableRequests(Throwable $exception): JSONResponse {
		$this->logger->warning('CaseTerms aanvullingsverzoeken failed: ' . $exception->getMessage());

		return new JSONResponse(
			['message' => 'The requests could not be read.', 'error' => 'aanvullingsverzoeken-unreadable'],
			Http::STATUS_BAD_REQUEST
		);
	}//end unreadableRequests()

	/**
	 * The case's requests, how many are open, and whether the case waits on one.
	 *
	 * @param string                           $caseId The case.
	 * @param array<int, array<string, mixed>> $rows   The requests with their waiting days.
	 *
	 * @return JSONResponse
	 */
	private function requestsAnswer(string $caseId, array $rows): JSONResponse {
		$open = count(array_filter($rows, static fn (array $row): bool => ((string)($row['state'] ?? '') === 'open')));

		return new JSONResponse([
			'caseId' => $caseId,
			'waiting' => ($open > 0),
			'open' => $open,
			'requests' => $rows,
		]);
	}//end requestsAnswer()

	/**
	 * Each request with how many days it has waited; a request that is not open has waited none.
	 *
	 * @param array<int, array<string, mixed>> $requests The case's requests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withDaysOpen(array $requests): array {
		$rows = [];
		foreach ($requests as $one) {
			$days = 0;
			if ((string)($one['state'] ?? '') === 'open') {
				$days = $this->aanvullingen->daysOpen(request: $one);
			}

			$rows[] = array_merge($one, ['daysOpen' => $days]);
		}

		return $rows;
	}//end withDaysOpen()

	/**
	 * How old the open workload is right now, per status.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	#[NoAdminRequired]
	public function workloadAge(): JSONResponse {
		$denied = $this->refuseUnlessSignedIn();
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($this->workload->report());
		} catch (RefusedException $e) {
			return $this->refused(op: 'read the age of the open workload', e: $e);
		} catch (Throwable $e) {
			$this->logger->warning('CaseTerms workload age failed: ' . $e->getMessage());

			return new JSONResponse(
				['message' => 'The workload age could not be read.', 'error' => 'workload-age-unreadable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}//end try
	}//end workloadAge()

	/**
	 * Refuse a caller who may not READ this case.
	 *
	 * Asked per case and failing closed. `#[NoAdminRequired]` on its own would
	 * let any signed-in user read the four clocks on any case id they can
	 * guess, and a term end is a fact about a dossier they may have no business
	 * knowing about.
	 *
	 * @param string $caseId The case the caller is asking about.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may read it.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	private function refuseUnlessMayRead(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->anonymous();
		}

		if ($this->guard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['message' => 'You cannot read this case.', 'error' => 'case-read-refused'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refuseUnlessMayRead()

	/**
	 * Refuse a caller who may not CHANGE this case.
	 *
	 * Asking the applicant for something suspends a statutory term, which is a
	 * write and not a read, so the mutation right is what it is asked of.
	 *
	 * @param string $caseId The case the caller is acting on.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may change it.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	private function refuseUnlessMayChange(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->anonymous();
		}

		if ($this->guard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['message' => 'You cannot change this case.', 'error' => 'case-change-refused'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refuseUnlessMayChange()

	/**
	 * Refuse a request carrying no session at all.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function anonymous(): JSONResponse {
		return new JSONResponse(
			['message' => 'Sign in to read the terms on a case.', 'error' => 'not-authenticated'],
			Http::STATUS_UNAUTHORIZED
		);
	}//end anonymous()

	/**
	 * Refuse an anonymous caller on an endpoint that names no case.
	 *
	 * The workload report is scoped by the store rather than by a case id: it
	 * reads through OpenRegister, which answers with the open cases this caller
	 * may see and no others. There is no object to guard here, so the session
	 * is the whole check.
	 *
	 * @return JSONResponse|null The refusal, or null when a user is signed in.
	 */
	private function refuseUnlessSignedIn(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->anonymous();
		}

		return null;
	}//end refuseUnlessSignedIn()

	/**
	 * Who is making this request.
	 *
	 * Read from the SESSION and never from the body. Who asked an applicant for
	 * something and who recorded that it arrived are facts about a statutory
	 * act, and a field a caller can fill in is a byline anybody can sign with
	 * somebody else's name.
	 *
	 * @return string The user id, or `system` for a background write.
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * workloadAge() on its old address.
	 *
	 * Deprecated alias of /api/deadlines/reports/open-workload-age; remove after the next major.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 *
	 * @contract exclude Deprecated path alias for workloadAge(); behaviour covered by the workloadAge() tests.
	 */
	#[NoAdminRequired]
	public function workloadAgeLegacyPath(): JSONResponse {
		return $this->workloadAge();
	}//end workloadAgeLegacyPath()
}//end class
