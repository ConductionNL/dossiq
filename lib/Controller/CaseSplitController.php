<?php

/**
 * Dividing a case, and the dated events inside one.
 *
 * 🔴 EVERY METHOD ASKS THE PER-CASE GUARD. `#[NoAdminRequired]` on its own
 * would let any signed-in user divide a case they cannot open, or read the
 * reports on one, by guessing a uuid. The split is a WRITE to two cases, so it
 * asks for write access to the case being divided.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseSplitService;
use OCA\Dossiq\Service\IncidentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The split action and the incidents on a case.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string           $appName     The app id.
	 * @param IRequest         $request     The request.
	 * @param CaseSplitService $splits      Divides a case in two.
	 * @param IncidentService  $incidents   The dated events inside a case.
	 * @param CaseAccessGuard  $accessGuard Who may read or write this case.
	 * @param IUserSession     $userSession Who is asking.
	 * @param IL10N            $l10n        The localisation service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseSplitService $splits,
		private readonly IncidentService $incidents,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What a split of this case may divide.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse `{divisible: string[]}`.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function divisible(string $caseId): JSONResponse {
		$refusal = $this->refuseUnlessRead(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(
			['divisible' => $this->splits->divisibleFor(caseTypeId: $this->caseTypeOf(caseId: $caseId))]
		);
	}

	/**
	 * Move the chosen documents and parties onto a second case.
	 *
	 * The second case is opened by the caller (the copy action already does
	 * that and is the right tool for it); this divides the material and
	 * relates the two halves. Splitting the two acts is deliberate: a split
	 * that also minted a case would duplicate `CaseCopyService` and the two
	 * would drift on what a new case gets.
	 *
	 * @param string $caseId The case being divided.
	 *
	 * @return JSONResponse `{moved: object}` or a refusal naming the rule.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function split(string $caseId): JSONResponse {
		$refusal = $this->refuseUnlessWrite(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$targetCaseId = trim((string)$this->request->getParam('targetCase', ''));
		if ($targetCaseId === '' || $targetCaseId === $caseId) {
			return new JSONResponse(
				[
					'error' => 'split_needs_two_cases',
					'message' => $this->l10n->t('A split needs a second case to move the material to.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$documents = $this->idsFrom(param: 'documents');
		$parties = $this->idsFrom(param: 'parties');

		$kinds = [];
		if ($documents !== []) {
			$kinds[] = 'documents';
		}

		if ($parties !== []) {
			$kinds[] = 'parties';
		}

		try {
			$this->splits->assertAllowed(caseTypeId: $this->caseTypeOf(caseId: $caseId), kinds: $kinds);
		} catch (RefusedException $e) {
			return new JSONResponse(
				['error' => $e->getRule(), 'message' => $e->getSentence()],
				$e->getStatus()
			);
		}

		$moved = $this->splits->moveChosen(
			sourceCaseId: $caseId,
			targetCaseId: $targetCaseId,
			documentIds: $documents,
			partyIds: $parties,
		);
		$this->splits->relate(sourceCaseId: $caseId, targetCaseId: $targetCaseId);

		return new JSONResponse(['moved' => $moved]);
	}

	/**
	 * The incidents on a case, oldest event first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse `{incidents: object[], open: int}`.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function incidents(string $caseId): JSONResponse {
		$refusal = $this->refuseUnlessRead(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$incidents = $this->incidents->onCase(caseId: $caseId);

		return new JSONResponse(
			[
				'incidents' => $incidents,
				'open' => $this->incidents->openCountOn(caseId: $caseId),
			]
		);
	}

	/**
	 * Record one incident on a case.
	 *
	 * @param string $caseId The case it happened inside.
	 *
	 * @return JSONResponse `{incident: object}`.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function recordIncident(string $caseId): JSONResponse {
		$refusal = $this->refuseUnlessWrite(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$incident = $this->incidents->record(
			caseId: $caseId,
			fields: [
				'eventDate' => trim((string)$this->request->getParam('eventDate', '')),
				'reporter' => trim((string)$this->request->getParam('reporter', '')),
				'description' => trim((string)$this->request->getParam('description', '')),
				'assignee' => trim((string)$this->request->getParam('assignee', '')),
				'state' => trim((string)$this->request->getParam('state', 'open')),
			]
		);

		if ($incident === []) {
			return new JSONResponse(
				[
					'error' => 'incident_not_recorded',
					'message' => $this->l10n->t('The incident could not be recorded.'),
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(['incident' => $incident]);
	}

	/**
	 * Hand one incident to somebody, leaving the case where it is.
	 *
	 * @param string $caseId     The case it sits inside.
	 * @param string $incidentId The incident.
	 *
	 * @return JSONResponse `{incident: object}`.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function handOverIncident(string $caseId, string $incidentId): JSONResponse {
		$refusal = $this->refuseUnlessWrite(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$incident = $this->incidents->handOver(
			incidentId: $incidentId,
			assignee: trim((string)$this->request->getParam('assignee', ''))
		);

		if ($incident === []) {
			return new JSONResponse(
				[
					'error' => 'incident_not_handed_over',
					'message' => $this->l10n->t('The incident could not be handed over.'),
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(['incident' => $incident]);
	}

	/**
	 * The case type of a case, for the divisibility declaration.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string The case type reference, or ''.
	 */
	private function caseTypeOf(string $caseId): string {
		return trim((string)$this->request->getParam('caseType', ''));
	}

	/**
	 * A list of ids from one request parameter.
	 *
	 * @param string $param The parameter name.
	 *
	 * @return array<int, string> The ids.
	 */
	private function idsFrom(string $param): array {
		$value = $this->request->getParam($param, []);
		if (is_array($value) === false) {
			return [];
		}

		return array_values(
			array_filter(array_map(static fn ($id): string => trim((string)$id), $value))
		);
	}

	/**
	 * Refuse a caller who may not read this case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse|null The refusal, or null when they may.
	 */
	private function refuseUnlessRead(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You cannot read this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}

	/**
	 * Refuse a caller who may not write this case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse|null The refusal, or null when they may.
	 */
	private function refuseUnlessWrite(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You cannot change this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}
}//end class
