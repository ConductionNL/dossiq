<?php

/**
 * Dossiq DSO case actions.
 *
 * The dashboard read of the DSO cases, and the writes on one case behind the
 * per-object guard: the case the caller may change is looked up and
 * authorised first, then the status transition, the beschikking or the
 * forward is handed to its service. Split out of
 * {@see \OCA\Dossiq\Controller\DsoController}, which keeps the session check,
 * the input refusals, the dashboard filters and the mapping of outcomes onto
 * responses; the samenwerkverzoek writes are {@see CooperationRequestActions}.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\BeschikkingGenerationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DsoCaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DsoDoorsturenNotifierInterface;
use OCA\Dossiq\Kernel\Contracts\DsoObjectRepositoryInterface;
use OCP\IUser;

/**
 * The dashboard read and the guarded writes on a DSO case.
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class GuardedCaseActions {

	/**
	 * Reads optional fields of the request body.
	 *
	 * @var RequestBodyFields
	 */
	private readonly RequestBodyFields $fields;

	/**
	 * Constructor.
	 *
	 * @param DsoObjectRepositoryInterface          $repository         The OpenRegister read collaborator.
	 * @param DsoCaseServiceInterface               $dsoCaseService     The DSO case service.
	 * @param BeschikkingGenerationServiceInterface $decisionService    The beschikking generation service.
	 * @param DsoDoorsturenNotifierInterface        $doorsturenNotifier The doorsturen event dispatcher.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly DsoObjectRepositoryInterface $repository,
		private readonly DsoCaseServiceInterface $dsoCaseService,
		private readonly BeschikkingGenerationServiceInterface $decisionService,
		private readonly DsoDoorsturenNotifierInterface $doorsturenNotifier,
	) {
		$this->fields = new RequestBodyFields();
	}//end __construct()

	/**
	 * The dashboard's DSO cases, narrowed by the filters.
	 *
	 * @param array<string, mixed> $params  The query: the DSO statuses, the paging and the plain filters.
	 * @param array<string, string> $filters The activiteitgroep, regelkwalificatie and location filters.
	 *
	 * @return array{error: string|null, results: array<int, mixed>} What OpenRegister answered.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function dashboard(array $params, array $filters): array {
		return $this->repository->fetchDashboard(
			params: $params,
			activiteitgroep: (string)($filters['activiteitgroep'] ?? ''),
			regelkwalificatie: (string)($filters['regelkwalificatie'] ?? ''),
			location: (string)($filters['location'] ?? '')
		);
	}//end dashboard()

	/**
	 * The case the user may change, or null when it does not exist.
	 *
	 * The per-object guard throws `Not authorized` when the user may not
	 * change it, which the controller answers as a 403.
	 *
	 * @param string $caseId The case.
	 * @param IUser  $user   The caller.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function mutableCase(string $caseId, IUser $user): ?array {
		$case = $this->repository->findZaak(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		$this->dsoCaseService->authorizeZaakMutation(case: $case, user: $user);
		return $case;
	}//end mutableCase()

	/**
	 * Move the case to the DSO status the body asks for.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $body   The request body: newStatus, and optionally besluitdatum and notes.
	 * @param string               $userId Who moves it.
	 *
	 * @return array<string, mixed> The updated case.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function transition(string $caseId, array $body, string $userId): array {
		return $this->dsoCaseService->transitionStatus(
			caseId: $caseId,
			newStatus: (string)($body['newStatus'] ?? ''),
			besluitdatum: $this->fields->optionalString(body: $body, key: 'besluitdatum'),
			notes: $this->fields->optionalString(body: $body, key: 'notes'),
			userId: $userId
		);
	}//end transition()

	/**
	 * Generate the beschikking the body asks for.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $body   The request body: outcome and motivation.
	 *
	 * @return array<string, mixed> The generated beschikking.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function beschikking(string $caseId, array $body): array {
		return $this->decisionService->generateBeschikking(
			caseId: $caseId,
			outcome: (string)($body['outcome'] ?? ''),
			motivation: (string)($body['motivation'] ?? '')
		);
	}//end beschikking()

	/**
	 * Announce that the case goes to another bevoegd gezag.
	 *
	 * @param array<string, mixed> $case   The case, as read.
	 * @param string               $caseId The case id.
	 * @param array<string, mixed> $body   The request body: targetBevoegdGezag and reason.
	 * @param string               $userId Who forwards it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function forward(array $case, string $caseId, array $body, string $userId): void {
		$this->doorsturenNotifier->dispatchDoorgestuurd(
			case: $case,
			caseId: $caseId,
			targetBevoegdGezag: (string)($body['targetBevoegdGezag'] ?? ''),
			reason: (string)($body['reason'] ?? ''),
			userId: $userId
		);
	}//end forward()
}//end class
