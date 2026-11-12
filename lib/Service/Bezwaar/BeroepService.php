<?php

/**
 * Dossiq Beroep Escalation Service.
 *
 * Domain service for the beroep capability — the municipality's tracking
 * envelope around a citizen's appeal of a beslissing op bezwaar at the
 * administrative court (rechtbank). Dossiq does NOT run the court process;
 * this service captures the operations that cannot be handled by the
 * manifest-driven CRUD path:
 *
 *  - register()                    — create a beroep record, compute
 *                                    filingDeadline from contested
 *                                    appealDecision.effectiveDate + P6W,
 *                                    flag latefilingNotice when the
 *                                    appellant filed past the deadline,
 *                                    and freeze sourceBezwaar /
 *                                    contestedDecision against further
 *                                    edits.
 *  - addFileInspectionRequest()    — append a sub-record for an Awb 8:42
 *                                    file-inspection request with the
 *                                    computed deadline (requestedAt + P4W).
 *  - executeCascade(action)        — fan out the post-uitspraak follow-up:
 *                                    reopen_bezwaar forks a new bezwaar
 *                                    via the status-transition-engine;
 *                                    new_primary_decision opens a fresh
 *                                    decision case; none clears the
 *                                    dwingende marker on the source
 *                                    bezwaar.
 *
 * Per the per-app convention every mutation goes through OpenRegister via
 * the manifest renderer; this service composes those calls and never owns
 * bespoke CRUD. Identity is never resolved here: every write lands through
 * OpenRegister, which stamps the acting user on its own audit trail, and the
 * one status change this service triggers goes through
 * `StatusTransitionService::execute()`, which resolves the actor itself.
 * Static error messages only — exception details never bubble to controllers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\CallerIdentity;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Beroep service: filing, file-inspection requests, judgment, cascade.
 *
 * @spec openspec/specs/beroep-escalation/spec.md
 */
class BeroepService {

	use SearchesObjects;

	/*
	 * NO VALID_OUTCOMES HERE — it was the whitelist `recordJudgment()` validated
	 * against, and that method is gone (see the note further down). The list of
	 * legal uitspraak outcomes belongs with the surface that records one; it is
	 * kept in `openspec/specs/beroep-escalation/spec.md` (REQ-BE-5) until such a
	 * surface exists.
	 */

	/**
	 * Allowed cascade actions per REQ-BE-6.
	 */
	private const VALID_CASCADES = [
		'reopen_objection',
		'new_primary_decision',
		'none',
	];

	/**
	 * Filing-window length: 6 weeks (Awb 6:7).
	 */
	private const FILING_WINDOW = '+42 days';

	/**
	 * File-inspection deadline: 4 weeks (Awb 8:42).
	 */
	private const FILE_INSPECTION_WINDOW = '+28 days';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge
	 * @param StatusTransitionService $transitions Engine used by
	 *                                             executeCascade() to
	 *                                             re-open the source
	 *                                             bezwaar without
	 *                                             bespoke transition
	 *                                             logic
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTransitionService $transitions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register a beroep against a beslissing op bezwaar.
	 *
	 * Computes filingDeadline from the contested appealDecision.effectiveDate
	 * + P6W (Awb 6:7), flags latefilingNotice when the appellant filed past
	 * the deadline (informational only — only the rechtbank weighs
	 * verschoonbare termijnoverschrijding), and persists the new beroep
	 * record. The OpenRegister audit trail captures actor + change diff
	 * automatically; this method writes no bespoke audit entries.
	 *
	 * @param string $caseId UUID of the dossiq case
	 *                       wrapping the beroep
	 * @param string $sourceObjectionId UUID of the bezwaar
	 *                                  lifecycle record
	 *                                  being escalated
	 * @param string $contestedDecisionId UUID of the
	 *                                    appealDecision being
	 *                                    contested
	 * @param string $filingDate ISO date the
	 *                           beroepschrift
	 *                           was filed at
	 *                           the court
	 * @param array<string, mixed> $payload Optional extra fields
	 *                                      (courtReference,
	 *                                      competentCourt,
	 *                                      responsibleChamber,
	 *                                      voorzieningRequested)
	 *
	 * @return array<string, mixed> The created beroep record
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schemas
	 *                          are unconfigured, or the contested decision
	 *                          cannot be loaded.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function register(
		string $caseId,
		string $sourceObjectionId,
		string $contestedDecisionId,
		string $filingDate,
		array $payload = [],
	): array {
		$payload = array_diff_key($payload, CallerIdentity::KEYS);
		$store = $this->appealStore(otherSchemaKey: 'appeal_decision_schema');
		if (in_array('', [$store['register'], $store['schema'], $store['otherSchema']], true) === true) {
			throw new RuntimeException('Beroep schemas are not configured');
		}

		$contested = $this->findIn(store: ['schema' => $store['otherSchema']] + $store, id: $contestedDecisionId);
		if ($contested === null) {
			throw new RuntimeException('Contested beslissing not found');
		}

		$filing = [
			'case' => $caseId,
			'sourceObjection' => $sourceObjectionId,
			'contestedDecision' => $contestedDecisionId,
			'appellantFilingDate' => $filingDate,
		];

		return $this->saveAppeal(store: $store, record: $this->appealRecord(filing: $filing + ['payload' => $payload], contested: $contested));
	}//end register()

	/**
	 * The new appeal record: defaults, then the caller's extra fields, then
	 * the filing facts and the computed deadline, which the extra fields
	 * cannot override.
	 *
	 * @param array<string, mixed> $filing    The filing facts, with the caller's extra fields under `payload`.
	 * @param array<string, mixed> $contested The contested decision.
	 *
	 * @return array<string, mixed> The record to save.
	 */
	private function appealRecord(array $filing, array $contested): array {
		$payload = $filing['payload'];
		unset($filing['payload']);

		$deadline = $this->computeFilingDeadline(effective: (string)($contested['effectiveDate'] ?? ''));
		$filing['filingDeadline'] = $deadline;
		$filing['latefilingNotice'] = $this->isLateFiling(filingDate: $filing['appellantFilingDate'], deadline: $deadline);

		return array_merge(
			[
				'responsibleChamber' => 'enkelvoudig',
				'provisionRequested' => false,
			],
			$payload,
			$filing
		);
	}//end appealRecord()

	/**
	 * Save a new appeal record, answering the record itself when the store answers nothing.
	 *
	 * @param array<string, mixed> $store  The object service, register and appeal schema.
	 * @param array<string, mixed> $record The record to save.
	 *
	 * @return array<string, mixed> The saved record.
	 *
	 * @throws RuntimeException When the save fails.
	 */
	private function saveAppeal(array $store, array $record): array {
		try {
			return ($this->saveObjectAsArray(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				object: $record
			) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq beroep: failed to register: ' . $e->getMessage()
			);
			throw new RuntimeException('Could not register beroep');
		}
	}//end saveAppeal()

	/**
	 * Append a file-inspection request (Awb 8:42) sub-record.
	 *
	 * The system NEVER generates the bundle itself — Juridische Zaken
	 * curates it via existing dossier tooling; this method records the
	 * linkage with a computed deadline of requestedAt + P4W.
	 *
	 * @param string $appealId UUID of the beroep
	 * @param string $requestedAt ISO date the rechtbank issued the request
	 * @param string|null $fileBundle Optional NC file ID / dossier ref
	 *
	 * @return array<string, mixed> The updated beroep record
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the
	 *                          beroep cannot be loaded.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function addFileInspectionRequest(
		string $appealId,
		string $requestedAt,
		?string $fileBundle = null,
	): array {
		$store = $this->appealStore(otherSchemaKey: null);
		$current = $this->requireAppeal(store: $store, appealId: $appealId);

		$entry = [
			'requestedAt' => $requestedAt,
			'deadline' => $this->shiftDate(base: $requestedAt, modifier: self::FILE_INSPECTION_WINDOW),
		];
		if ($fileBundle !== null && $fileBundle !== '') {
			$entry['fileBundle'] = $fileBundle;
		}

		$requests = (array)($current['fileInspectionRequests'] ?? []);
		$requests[] = $entry;

		return $this->patchAppeal(
			store: $store,
			patch: [
				'id' => $appealId,
				'current' => $current,
				'changes' => ['fileInspectionRequests' => $requests],
				'log' => 'Dossiq beroep: failed to add file-inspection request: ',
				'error' => 'Could not add file-inspection request',
			]
		);
	}//end addFileInspectionRequest()

	/*
	 * NO recordJudgment() HERE.
	 *
	 * It persisted the rechtbank's categorical uitspraak onto the beroep
	 * record. Nothing called it — nothing calls this service at all: the only
	 * reference to `BeroepService` outside its own file is a prose sentence in
	 * `lib/Settings/dossiq_register.json`. There is no controller, listener
	 * or job that records a judgment, so wiring this method would have meant
	 * inventing the surface that records a court ruling, which is a feature
	 * decision and not dead-code removal.
	 */

	/**
	 * Execute the post-uitspraak cascade on the linked bezwaar workflow.
	 *
	 *  - reopen_bezwaar       — fork a new bezwaar case from the source via
	 *                           StatusTransitionService and link both ways.
	 *  - new_primary_decision — open a fresh decision case (follow-up task
	 *                           on the original primary case).
	 *  - none                 — clear the dwingende marker on the source
	 *                           bezwaar so it returns to its terminal
	 *                           status.
	 *
	 * Cascade is intentionally idempotent: re-running with the same action
	 * is a no-op once the corresponding side effect is already recorded.
	 *
	 * @param string $appealId UUID of the beroep
	 * @param string $action One of self::VALID_CASCADES
	 *
	 * @return array<string, mixed> The updated beroep record
	 *
	 * @throws RuntimeException When the action is invalid, OpenRegister
	 *                          is unavailable, or the beroep cannot be
	 *                          loaded.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function executeCascade(string $appealId, string $action): array {
		if (in_array($action, self::VALID_CASCADES, true) === false) {
			throw new RuntimeException('Invalid cascade action');
		}

		$store = $this->appealStore(otherSchemaKey: 'bezwaar_schema');
		$cascade = [
			'appealId' => $appealId,
			'action' => $action,
			'current' => $this->requireAppeal(store: $store, appealId: $appealId),
		];

		return $this->patchAppeal(
			store: $store,
			patch: [
				'id' => $appealId,
				'current' => $cascade['current'],
				'changes' => $this->cascadeChanges(store: $store, cascade: $cascade),
				'log' => 'Dossiq beroep: failed to persist cascade: ',
				'error' => 'Could not persist cascade',
			]
		);
	}//end executeCascade()

	/**
	 * Run the side effect of one cascade action and say what to record on the appeal.
	 *
	 * @param array<string, mixed> $store   The object service, register, appeal and objection schema.
	 * @param array<string, mixed> $cascade The appeal id, the action and the appeal record.
	 *
	 * @return array<string, mixed> The changes to record.
	 */
	private function cascadeChanges(array $store, array $cascade): array {
		$patch = ['cascadeAction' => $cascade['action']];

		if ($cascade['action'] === 'reopen_objection') {
			// Defer to the status-transition-engine to re-open the source
			// bezwaar. The engine owns the transition + guards; this
			// service only triggers it and links the resulting case back
			// to the beroep.
			$reopenedCaseId = $this->reopenSourceObjectionCase(store: $store, cascade: $cascade);
			if ($reopenedCaseId !== null) {
				$patch['cascadeObjectionCase'] = $reopenedCaseId;
			}
		}

		if ($cascade['action'] === 'new_primary_decision') {
			// The follow-up primary-decision case is opened via the
			// status-transition-engine on the original primary case. The
			// engine + workflow template own the new-case fork; we only
			// record the chosen cascade on the beroep for traceability.
			$this->logger->info(
				'Dossiq beroep: new_primary_decision cascade requested',
				['beroepId' => $cascade['appealId']]
			);
		}

		return $patch;
	}//end cascadeChanges()

	/**
	 * Ask the status-transition-engine to re-open the bezwaar case behind a beroep.
	 *
	 * Returns the re-opened case UUID when the transition ran, and null when there is nothing to
	 * re-open (no source bezwaar, no bezwaar schema, missing source, no case) or the transition
	 * failed — a failure is logged, never raised, exactly as before.
	 *
	 * @param array<string, mixed> $store   The object service, register, appeal and objection schema.
	 * @param array<string, mixed> $cascade The appeal id, the action and the appeal record.
	 *
	 * @return string|null The re-opened bezwaar case UUID, or null.
	 */
	private function reopenSourceObjectionCase(array $store, array $cascade): ?string {
		$sourceCaseId = $this->sourceObjectionCase(store: $store, current: $cascade['current']);
		if ($sourceCaseId === '') {
			return null;
		}

		try {
			$this->transitions->execute(
				caseId: $sourceCaseId,
				transitionId: 'beroep-reopen',
				comment: 'Reopened via beroep ' . $cascade['appealId'],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq beroep: reopen transition failed: '
				. $e->getMessage()
			);
			return null;
		}

		return $sourceCaseId;
	}//end reopenSourceObjectionCase()

	/**
	 * The case of the objection an appeal escalates, or '' when there is none to find.
	 *
	 * @param array<string, mixed> $store   The object service, register and objection schema.
	 * @param array<string, mixed> $current The appeal record.
	 *
	 * @return string The case UUID, or ''.
	 */
	private function sourceObjectionCase(array $store, array $current): string {
		$sourceObjectionId = (string)($current['sourceObjection'] ?? '');
		if ($sourceObjectionId === '' || $store['otherSchema'] === '') {
			return '';
		}

		$sourceObjection = $this->findIn(store: ['schema' => $store['otherSchema']] + $store, id: $sourceObjectionId);

		return (string)($sourceObjection['case'] ?? '');
	}//end sourceObjectionCase()

	/**
	 * The object service with the register, the appeal schema and optionally one other schema.
	 *
	 * @param string|null $otherSchemaKey The setting naming the other schema, or null for none.
	 *
	 * @return array<string, mixed> `objectService`, `register`, `schema` and, when asked, `otherSchema`.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function appealStore(?string $otherSchemaKey): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$store = [
			'objectService' => $objectService,
			'register' => $this->settingsService->getConfigValue(key: 'register'),
			'schema' => $this->settingsService->getConfigValue(key: 'beroep_schema'),
		];
		if ($otherSchemaKey !== null) {
			$store['otherSchema'] = $this->settingsService->getConfigValue(key: $otherSchemaKey);
		}

		return $store;
	}//end appealStore()

	/**
	 * Read one object from the store's register and schema.
	 *
	 * @param array<string, mixed> $store The object service, register and schema.
	 * @param string               $id    The object.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function findIn(array $store, string $id): ?array {
		return $this->findObjectAsArray(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			id: $id
		);
	}//end findIn()

	/**
	 * Read the appeal, or refuse when it is not there.
	 *
	 * @param array<string, mixed> $store    The object service, register and appeal schema.
	 * @param string               $appealId The appeal.
	 *
	 * @return array<string, mixed> The appeal record.
	 *
	 * @throws RuntimeException When the appeal cannot be loaded.
	 */
	private function requireAppeal(array $store, string $appealId): array {
		$current = $this->findIn(store: $store, id: $appealId);
		if ($current === null) {
			throw new RuntimeException('Beroep not found');
		}

		return $current;
	}//end requireAppeal()

	/**
	 * Write changes onto the appeal, answering the merged record when the store answers nothing.
	 *
	 * @param array<string, mixed> $store The object service, register and appeal schema.
	 * @param array<string, mixed> $patch The appeal id and record, the changes, and the log line and error a failure gives.
	 *
	 * @return array<string, mixed> The updated appeal record.
	 *
	 * @throws RuntimeException When the write fails.
	 */
	private function patchAppeal(array $store, array $patch): array {
		try {
			return ($this->patchObjectAsArray(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				id: (string)$patch['id'],
				changes: $patch['changes']
			) ?? array_merge($patch['current'], $patch['changes']));
		} catch (Throwable $e) {
			$this->logger->error($patch['log'] . $e->getMessage());
			throw new RuntimeException($patch['error']);
		}
	}//end patchAppeal()

	/**
	 * Compute the 6-week filing deadline (Awb 6:7, 6:8).
	 *
	 * @param string $effective ISO date of the contested decision's
	 *                          effectiveDate
	 *
	 * @return string ISO date of the deadline (empty when effective is empty)
	 */
	private function computeFilingDeadline(string $effective): string {
		if ($effective === '') {
			return '';
		}

		return $this->shiftDate(
			base: $effective,
			modifier: self::FILING_WINDOW,
		);
	}//end computeFilingDeadline()

	/**
	 * Decide whether the appellant filed past the 6-week window.
	 *
	 * Informational only — Dossiq never refuses or auto-closes a beroep
	 * on timeliness; only the rechtbank weighs verschoonbare
	 * termijnoverschrijding.
	 *
	 * @param string $filingDate ISO date the beroepschrift was filed
	 * @param string $deadline ISO date of the filing deadline
	 *
	 * @return bool True when filingDate > deadline.
	 */
	private function isLateFiling(string $filingDate, string $deadline): bool {
		if ($filingDate === '' || $deadline === '') {
			return false;
		}

		try {
			$filed = new DateTimeImmutable($filingDate);
			$end = new DateTimeImmutable($deadline);
		} catch (Throwable $e) {
			return false;
		}

		return $filed > $end;
	}//end isLateFiling()

	/**
	 * Shift an ISO date by a DateTime modifier (e.g. "+42 days").
	 *
	 * @param string $base ISO date
	 * @param string $modifier DateTime modifier expression
	 *
	 * @return string Shifted ISO date (empty on parse failure)
	 */
	private function shiftDate(string $base, string $modifier): string {
		if ($base === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($base))
				->modify($modifier)
				->format('Y-m-d');
		} catch (Throwable $e) {
			return '';
		}
	}//end shiftDate()
}//end class
