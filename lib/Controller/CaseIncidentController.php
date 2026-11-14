<?php

/**
 * The dated reports inside a case.
 *
 * 🔴 THE TWO GUARDS DIFFER BY VERB. Reading the incidents needs read access to
 * the case, because they are part of what the case is about. Recording,
 * assigning and settling need mutation access, because each writes on the case
 * file even though none of them touches the case row
 * ({@see CaseAccessGuard}, ADR-005 Rule 3).
 *
 * 🔴 ASSIGNING AN INCIDENT DOES NOT MOVE THE CASE, and nothing here offers a
 * verb that would. The case's own hand-off lives in `CaseHandoverController`
 * and `CaseTakeoverController`; an endpoint here that also re-seated the case
 * would be a second door onto the one act, which is the thing the custody chain
 * exists to prevent.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\CaseCaller;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\IncidentStoreInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * List, record, assign and settle the incidents on a case.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseIncidentController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param IncidentStoreInterface   $incidents   The dated events inside a case.
	 * @param CaseAccessGuardInterface $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession    $userSession The session.
	 * @param LoggerInterface $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IncidentStoreInterface $incidents,
		private readonly CaseAccessGuardInterface $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The incidents on a case, oldest event first, each with its delay.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The incidents, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		if ($this->caller()->reader(caseId: $caseId) === null) {
			return $this->caller()->notYours();
		}

		$incidents = [];
		foreach ($this->incidents->onCase(caseId: $caseId) as $incident) {
			// THE DELAY TRAVELS WITH THE ROW. Computed here rather than in the
			// browser so the list and a report cannot disagree about how late
			// a report was written down.
			$incident['recordingDelayDays'] = $this->incidents->recordingDelayDays(incident: $incident);
			$incidents[] = $incident;
		}

		return new JSONResponse(['caseId' => $caseId, 'incidents' => $incidents, 'total' => count($incidents)]);
	}//end index()

	/**
	 * Record one report on the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The stored incident, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	#[NoAdminRequired]
	public function record(string $caseId): JSONResponse {
		$user = $this->caller()->writer(caseId: $caseId);
		if ($user === null) {
			return $this->caller()->notYours();
		}

		$fields = $this->incidentFields();
		if ($fields === null) {
			return $this->incomplete();
		}

		try {
			return new JSONResponse($this->storeIncident(caseId: $caseId, eventDate: $fields[0], description: $fields[1]));
		} catch (RefusedException $e) {
			return $this->refused(op: 'incident record', e: $e);
		}
	}//end record()

	/**
	 * When it happened and what happened, from the request; null when either is missing.
	 *
	 * @return array{0: string, 1: string}|null The event date and the description.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	private function incidentFields(): ?array {
		$eventDate = trim((string)$this->request->getParam('eventDate', ''));
		$description = trim((string)$this->request->getParam('description', ''));
		if ($eventDate === '' || $description === '') {
			return null;
		}

		return [$eventDate, $description];
	}//end incidentFields()

	/**
	 * The refusal for a report that does not say when or what happened.
	 *
	 * @return JSONResponse A 400.
	 */
	private function incomplete(): JSONResponse {
		return new JSONResponse(
			['message' => 'Say when it happened and what happened.', 'error' => 'incident-incomplete'],
			Http::STATUS_BAD_REQUEST,
		);
	}//end incomplete()

	/**
	 * Store one report on the case, with its reporter and assignee from the request.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $eventDate When it happened.
	 * @param string $description What happened.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the incident is refused.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	private function storeIncident(string $caseId, string $eventDate, string $description): array {
		return $this->incidents->record(
			caseId: $caseId,
			eventDate: $eventDate,
			description: $description,
			reporter: trim((string)$this->request->getParam('reporter', '')),
			assignee: trim((string)$this->request->getParam('assignee', '')),
		);
	}//end storeIncident()

	/**
	 * Hand one incident to somebody, leaving the case where it is.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $incidentId The incident uuid.
	 *
	 * @return JSONResponse The stored incident, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	#[NoAdminRequired]
	public function assign(string $caseId, string $incidentId): JSONResponse {
		if ($this->caller()->writer(caseId: $caseId) === null) {
			return $this->caller()->notYours();
		}

		try {
			return new JSONResponse(
				$this->incidents->assign(
					incidentId: $incidentId,
					assignee: trim((string)$this->request->getParam('assignee', '')),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'incident assign', e: $e);
		}
	}//end assign()

	/**
	 * Settle one incident with its outcome.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $incidentId The incident uuid.
	 *
	 * @return JSONResponse The stored incident, or the refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	#[NoAdminRequired]
	public function settle(string $caseId, string $incidentId): JSONResponse {
		if ($this->caller()->writer(caseId: $caseId) === null) {
			return $this->caller()->notYours();
		}

		$outcome = trim((string)$this->request->getParam('outcome', ''));
		if ($outcome === '') {
			return new JSONResponse(
				['message' => 'Say what came of the report.', 'error' => 'incident-incomplete'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new JSONResponse($this->incidents->settle(incidentId: $incidentId, outcome: $outcome));
		} catch (RefusedException $e) {
			return $this->refused(op: 'incident settle', e: $e);
		}
	}//end settle()

	/**
	 * Who may read or write on the case, and the refusal when they may not.
	 *
	 * @return CaseCaller
	 */
	private function caller(): CaseCaller {
		return new CaseCaller(userSession: $this->userSession, accessGuard: $this->accessGuard);
	}//end caller()
}//end class
