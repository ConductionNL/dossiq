<?php

/**
 * Dossiq Advice Service.
 *
 * Workflow service for advice requests (adviesAanvraag). CRUD is delegated
 * to the manifest renderer (OpenRegister); this service owns the domain
 * operations that require server-side side-effects:
 *   - transitionStatus()    — status transitions with notification dispatch
 *   - dispatchReminder()    — manual + automated reminder notifications
 *   - applyWorkflowGuard()  — block downstream steps while advice pending
 *   - getOpenAdvice()       — used by the deadline cron
 *   - expireAdvice()        — set status=verlopen (cron)
 *   - getAdviceForCase()    — used by the guard + case-detail tab
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 *
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\AdviceServiceInterface;
use OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard;
use OCA\Dossiq\Service\Advice\AdviceNotifier;
use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for advice request (adviesAanvraag) workflow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The thirteenth type is
 * CaseDateNormaliser, and it replaced inline date handling rather than adding a
 * concern: the two calls here are the received moment and the deadline, both of
 * which used to be formatted in this class. Control: phpmd on this file at
 * 8f9dc479^ is clean at twelve, so dropping the dependency is the only way back
 * under the threshold and it puts a second date write path back.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class AdviceService implements AdviceServiceInterface {

	/**
	 * The conclusions a received advice may carry.
	 *
	 * @var array<int, string>
	 */
	private const CONCLUSIONS = ['positive', 'positive-with-conditions', 'negative', 'clarification'];

	/**
	 * The statuses an advice request may move to.
	 *
	 * @var array<int, string>
	 */
	private const VALID_STATUSES = [
		'requested',
		'received',
		'expired',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param IUserSession $userSession The current user session
	 * @param LoggerInterface $logger The logger
	 * @param AdviceDelegationService $adviceDelegation Advice delegation to decidesk (ADR-019)
	 * @param AdviceRepository $repository OpenRegister access for adviesAanvraag records
	 * @param AdviceAuthorizationGuard $guard Per-object transition IDOR guard (Wilco #6)
	 * @param AdviceNotifier $notifier Advice notification fan-out
	 * @param CaseDateNormaliser $dates The one date write path.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly AdviceDelegationService $adviceDelegation,
		private readonly AdviceRepository $repository,
		private readonly AdviceAuthorizationGuard $guard,
		private readonly AdviceNotifier $notifier,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * Transition an advice request to a new status and fire notifications.
	 *
	 * Supported transitions:
	 *   - to=aangevraagd: notify the adviseur (used right after manifest create).
	 *   - to=ontvangen:   set receivedAt + optional adviesDocument; notify caller.
	 *   - to=verlopen:    mark expired (cron path).
	 *
	 * @param string $adviceId The advice UUID
	 * @param string $to Target status
	 * @param array<string, mixed> $payload Extra fields (adviesDocument, etc.)
	 *
	 * @return array<string, mixed> Updated advice record
	 *
	 * @throws \RuntimeException When OpenRegister unavailable / invalid status
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function transitionStatus(string $adviceId, string $to, array $payload = []): array {
		if (in_array($to, self::VALID_STATUSES, true) === false) {
			throw new RuntimeException('Invalid advice status');
		}

		$current = $this->repository->find(adviceId: $adviceId);
		if ($current === null) {
			// Collapse not-found and access-denied into one "not accessible"
			// error so the endpoint cannot be used as an existence oracle for
			// advice UUIDs (same pattern as docudesk#100 / Wilco #6).
			throw new RuntimeException('Advice request not accessible');
		}

		$this->guard->assertTransitionAuthorized(advice: $current, to: $to);

		return $this->applyTransition(adviceId: $adviceId, to: $to, current: $current, payload: $payload);
	}//end transitionStatus()

	/**
	 * Apply an advice status transition WITHOUT an authorization check.
	 *
	 * TRUST BOUNDARY: this is the system/cron seam. It must only ever be called
	 * from `transitionStatus()` (which authorizes first) or from a code-driven
	 * background job with no user session (`expireAdvice()`). Never call it with
	 * user-supplied intent that has not been through
	 * `assertAdviceTransitionAuthorized()`.
	 *
	 * @param string $adviceId The advice UUID.
	 * @param string $to Target status.
	 * @param array<string, mixed> $current The current advice record (pre-update).
	 * @param array<string, mixed> $payload Extra fields (adviesDocument, etc.).
	 *
	 * @return array<string, mixed> Updated advice record.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable / not configured.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function applyTransition(string $adviceId, string $to, array $current, array $payload = []): array {
		$update = ['status' => $to];

		if ($to === 'received') {
			$update['receivedAt'] = $this->dates->nowAsMoment();
			$fileId = (string)($payload['adviceDocument'] ?? ($payload['fileId'] ?? ''));
			if ($fileId !== '') {
				$update['adviceDocument'] = $fileId;
			}

			$update = array_merge($update, $this->outcomeOf(payload: $payload));
		}

		$advice = $this->repository->save(update: $update, adviceId: $adviceId);

		$this->notifier->fireTransitionNotification(
			to: $to,
			current: $current,
			adviceId: $adviceId,
			callerId: $this->getUserId(),
			summary: $this->summaryOf(update: $update),
		);

		return $advice;
	}//end applyTransition()

	/**
	 * The outcome a received advice carries: conclusion, text and the conditions ticked.
	 *
	 * @param array<string, mixed> $payload The transition payload.
	 *
	 * @return array<string, mixed> The fields to store; an unknown conclusion is left out.
	 *
	 * @spec openspec/specs/advice-management/spec.md#requirement-an-advice-request-keeps-its-kind-subject-and-outcome
	 */
	private function outcomeOf(array $payload): array {
		$outcome    = [];
		$conclusion = (string)($payload['conclusion'] ?? '');
		if (in_array($conclusion, self::CONCLUSIONS, true) === true) {
			$outcome['conclusion'] = $conclusion;
		}

		if (is_string($payload['adviceText'] ?? null) === true) {
			$outcome['adviceText'] = $payload['adviceText'];
		}

		if (is_array($payload['conditionsChecked'] ?? null) === true) {
			$outcome['conditionsChecked'] = array_values(
				array_filter($payload['conditionsChecked'], static fn (mixed $condition): bool => is_string($condition) === true)
			);
		}

		return $outcome;
	}//end outcomeOf()

	/**
	 * The one-line summary the received notification carries.
	 *
	 * @param array<string, mixed> $update The stored update.
	 *
	 * @return string The conclusion and the start of the advice text, or ''.
	 */
	private function summaryOf(array $update): string {
		$parts = [];
		if (isset($update['conclusion']) === true) {
			$parts[] = $update['conclusion'];
		}

		$text = trim((string)($update['adviceText'] ?? ''));
		if ($text !== '') {
			$parts[] = mb_substr($text, 0, 140);
		}

		return implode(': ', $parts);
	}//end summaryOf()

	/**
	 * The configured advice request schema.
	 *
	 * @return string The schema id or slug.
	 */
	private function adviceSchema(): string {
		$schema = (string)$this->settingsService->getConfigValue('advies_aanvraag_schema');
		if ($schema === '') {
			return 'adviesAanvraag';
		}

		return $schema;
	}//end adviceSchema()

	/**
	 * Dispatch a reminder on behalf of the authenticated caller.
	 *
	 * The HTTP seam. `POST /api/advice/{id}/remind` used to call
	 * {@see self::dispatchReminder()} directly, which has no authorization —
	 * so any authenticated user could make any adviseur receive a reminder for
	 * any advice request, and the endpoint doubled as an existence oracle for
	 * advice UUIDs. This mirrors the split that already exists in this class
	 * between `transitionStatus()` (authorizes, then delegates) and
	 * `applyTransition()` (the system/cron seam).
	 *
	 * @param string $adviceId The advice UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the advice is not accessible to the caller.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function dispatchReminderAsUser(string $adviceId): void {
		$advice = $this->repository->find(adviceId: $adviceId);
		if ($advice === null) {
			// Collapsed with denied, as in transitionStatus(): no existence
			// oracle for advice UUIDs.
			throw new RuntimeException('Advice request not accessible');
		}

		$this->guard->assertReminderAuthorized(advice: $advice);

		$this->dispatchReminder(adviceId: $adviceId);
	}//end dispatchReminderAsUser()

	/**
	 * Dispatch a reminder notification to the adviseur WITHOUT an
	 * authorization check.
	 *
	 * TRUST BOUNDARY: this is the system seam (`AdviceTimerFiredListener`, on the engine timer's fire), which
	 * runs with no user session. It must only be called from
	 * {@see self::dispatchReminderAsUser()} (which authorizes first) or from a
	 * code-driven background job. Never call it with user-supplied intent that
	 * has not been through `assertReminderAuthorized()`.
	 *
	 * @param string $adviceId The advice UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function dispatchReminder(string $adviceId): void {
		$advice = $this->repository->find(adviceId: $adviceId);
		if ($advice === null) {
			return;
		}

		$advisor = (string)($advice['advisor'] ?? '');
		if ($advisor === '') {
			return;
		}

		$this->notifier->sendUserNotification(
			userId: $advisor,
			subject: 'advies_herinnering',
			objectId: $adviceId
		);
	}//end dispatchReminder()

	/**
	 * Workflow guard — return pending advice (status=aangevraagd) for a case.
	 *
	 * Callers (case-status transitions, parafering routes) use this to block
	 * downstream steps while advice is still outstanding.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<int, array<string, mixed>> Pending advice records
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyWorkflowGuard(string $caseId): array {
		$all = $this->getAdviceForCase(caseId: $caseId);
		$pending = [];

		foreach ($all as $advice) {
			$status = (string)($advice['status'] ?? '');
			if ($status === 'requested') {
				$pending[] = $advice;
			}
		}

		return $pending;
	}//end applyWorkflowGuard()

	/**
	 * Get all advice requests linked to a case.
	 *
	 * Used by the workflow guard and by the case-detail "Adviezen" tab.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<int, array<string, mixed>> Advice records for the case
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAdviceForCase(string $caseId): array {
		return $this->repository->findForCase(caseId: $caseId);
	}//end getAdviceForCase()

	/**
	 * Load all open advice requests across the system (for the deadline job).
	 *
	 * @return array<int, array<string, mixed>> Open advice records
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getOpenAdvice(): array {
		return $this->repository->findOpen();
	}//end getOpenAdvice()

	/**
	 * Mark an advice request as expired (status -> verlopen).
	 *
	 * SYSTEM PATH — called by AdviceTimerFiredListener and AdviceTimerListener, which may run with NO user
	 * session. It therefore goes straight to applyTransition() and deliberately
	 * bypasses assertAdviceTransitionAuthorized(): that guard requires a session
	 * and would reject the cron with 'Not authenticated', silently breaking
	 * advice expiry. `verlopen` is unreachable over HTTP for the same reason —
	 * the guard denies it for every caller, so expiry stays a system-owned
	 * transition.
	 *
	 * The advice id originates from getOpenAdvice() (code-driven), never from
	 * user-supplied request data.
	 *
	 * @param string $adviceId The advice UUID
	 *
	 * @return array<string, mixed> Updated advice record
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function expireAdvice(string $adviceId): array {
		try {
			$current = $this->repository->find(adviceId: $adviceId);
			if ($current === null) {
				throw new RuntimeException('Advice request not accessible');
			}

			return $this->applyTransition(adviceId: $adviceId, to: 'expired', current: $current);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to expire advice: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
			return [];
		}
	}//end expireAdvice()

	/**
	 * Resolve the current user id from session (never trust client-supplied user).
	 *
	 * @return string The current user UID or empty string
	 */
	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end getUserId()

	/**
	 * Create an advice request for a VTH case.
	 *
	 * Stores the adviceRequest in the `adviceRequest` schema and sends a
	 * notification to the adviseur. Corresponds to tasks.md#task-6.
	 *
	 * @param string $caseId UUID of the case
	 * @param array<string, mixed> $data Advice request data (adviseur, deadline, vraag, etc.)
	 * @param string $requestedBy User UID of the requester
	 *
	 * @return array<string, mixed> Saved adviceRequest object
	 *
	 * @throws RuntimeException If OpenRegister is unavailable or decidesk fails closed
	 * @throws \InvalidArgumentException If the deadline is not a date this system can read
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function requestAdvice(string $caseId, array $data, string $requestedBy): array {
		$objectService = $this->requireObjectService();

		$register = $this->settingsService->getConfigValue('register');

		$external = (($data['kind'] ?? '') === 'external');

		$payload = $this->advicePayload(caseId: $caseId, data: $data, requestedBy: $requestedBy, external: $external);

		$savedRecord = $this->savedAdvice(objectService: $objectService, register: $register, payload: $payload);

		$this->delegateAdviceDecision(
			advice: ['objectService' => $objectService, 'register' => $register, 'data' => $data, 'payload' => $payload, 'saved' => $savedRecord]
		);

		$this->notifyAdviseurUnlessExternal(caseId: $caseId, payload: $payload, savedRecord: $savedRecord, external: $external);

		$this->logger->info('Advice request created for case ' . $caseId . ' by ' . $requestedBy, ['app' => AppIdentity::APP_ID]);

		return $savedRecord;
	}//end requestAdvice()

	/**
	 * OpenRegister's object service, refusing when it is not available.
	 *
	 * @return object The object service
	 *
	 * @throws RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 */
	private function requireObjectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end requireObjectService()

	/**
	 * Notify the adviseur of a new request, unless the advisor is external.
	 *
	 * @param string $caseId UUID of the case
	 * @param array<string, mixed> $payload The adviceRequest payload
	 * @param array<string, mixed> $savedRecord The saved adviceRequest
	 * @param bool $external Whether the advisor is an external organisation
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 */
	private function notifyAdviseurUnlessExternal(string $caseId, array $payload, array $savedRecord, bool $external): void {
		// An external advisor is an organisation name, not a user: nobody to notify.
		if ($external === false) {
			$this->notifier->notifyAdviseur(
				caseId: $caseId,
				payload: $payload,
				saved: $savedRecord,
			);
		}
	}//end notifyAdviseurUnlessExternal()

	/**
	 * The adviceRequest payload, with the deadline parsed.
	 *
	 * @param string               $caseId      UUID of the case
	 * @param array<string, mixed> $data        Advice request data (adviseur, deadline, vraag, etc.)
	 * @param string               $requestedBy User UID of the requester
	 * @param bool                 $external    Whether the advisor is an external organisation
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @throws \InvalidArgumentException If the deadline is not a date this system can read
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 */
	private function advicePayload(string $caseId, array $data, string $requestedBy, bool $external): array {
		// The deadline used to be stored verbatim, so 31-01-2028 became an
		// advice request nobody could act on and nobody was told about. It is
		// parsed here, and an unreadable value refuses the write.
		$deadline = null;
		if (($data['deadline'] ?? null) !== null && $data['deadline'] !== '') {
			$deadline = $this->dates->toCalendarDate($data['deadline'], 'deadline');
		}

		$type = 'intern';
		if ($external === true) {
			$type = 'extern';
		}

		// The adviesAanvraag schema is the one the list, the transitions and
		// the reminders read (AdviceRepository), so a request is written there.
		return [
			'case' => $caseId,
			'requestedBy' => $requestedBy,
			'advisor' => (string)($data['advisor'] ?? ''),
			'type' => $type,
			'subject' => (string)($data['subject'] ?? ''),
			'deadline' => $deadline,
			'status' => 'requested',
			'questions' => (string)($data['question'] ?? ''),
		];
	}//end advicePayload()

	/**
	 * Save the adviceRequest payload and answer the stored record.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The register id.
	 * @param array<string, mixed> $payload       The adviceRequest payload.
	 *
	 * @return array<string, mixed> The stored record, or [] when the save answered no array.
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-6
	 */
	private function savedAdvice(object $objectService, string $register, array $payload): array {
		$saved = $objectService->saveObject(
			register: $register,
			schema: $this->adviceSchema(),
			object: $payload
		);

		$savedRecord = [];
		if (is_array($saved) === true) {
			$savedRecord = $saved;
		}

		return $savedRecord;
	}//end savedAdvice()

	/**
	 * Raise the decidesk `advice` Decision for a new advice request and
	 * persist its reference on the saved adviceRequest.
	 *
	 * The advice request travels as requestAdvice() wrote it: `objectService` (the
	 * OpenRegister ObjectService), `register` (the register id), `data` (the advice
	 * request data), `payload` (the persisted adviceRequest payload) and `saved`
	 * (the normalized saveObject() result).
	 *
	 * @param array<string, mixed> $advice The advice request.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If decidesk fails closed
	 */
	private function delegateAdviceDecision(array $advice): void {
		// REQ-PDRD-001 / REQ-PDRD-002: the advice is *made* in decidesk. Raise a
		// decidesk `advice` Decision for this request and persist its ref. Fail
		// CLOSED — never author an advice outcome locally as a fallback.
		$saved = $advice['saved'];
		$adviceId = (string)($saved['id'] ?? ($saved['uuid'] ?? ''));
		$subjectId = (string)$advice['payload']['case'];
		if ($adviceId !== '') {
			$subjectId = $adviceId;
		}

		try {
			$decisionRef = $this->adviceDelegation->raiseAdviceDecision(
				subjectSchema: 'adviesAanvraag',
				subjectId: $subjectId,
				payload: $this->decisionPayload(advice: $advice),
			);

			if ($adviceId !== '') {
				$this->storeDecisionRef(advice: $advice + ['adviceId' => $adviceId], decisionRef: $decisionRef);
			}
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Dossiq: requestAdvice: decidesk advice Decision raise failed — failing closed: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
			// REQ-PDRD-002: fail closed; surface the error.
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end delegateAdviceDecision()

	/**
	 * Persist the decidesk Decision reference on the saved adviceRequest.
	 *
	 * @param array<string, mixed> $advice      The advice request, with its `adviceId`.
	 * @param string               $decisionRef The decidesk Decision reference.
	 *
	 * @return void
	 */
	private function storeDecisionRef(array $advice, string $decisionRef): void {
		$advice['objectService']->saveObject(
			object: array_merge($advice['saved'], ['decisionRef' => $decisionRef]),
			register: $advice['register'],
			schema: $this->adviceSchema(),
			uuid: $advice['adviceId'],
		);
	}//end storeDecisionRef()

	/**
	 * What decidesk is told about the advice request.
	 *
	 * @param array<string, mixed> $advice See {@see self::delegateAdviceDecision()}.
	 *
	 * @return array<string, string>
	 */
	private function decisionPayload(array $advice): array {
		$data = $advice['data'];

		return [
			'subjectRegister' => $advice['register'],
			'externalReference' => (string)$advice['payload']['case'],
			'subjectLabel' => (string)($data['question'] ?? 'Adviesaanvraag'),
			'question' => (string)($data['question'] ?? ''),
			'advisor' => (string)$advice['payload']['advisor'],
		];
	}//end decisionPayload()
}//end class
