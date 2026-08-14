<?php

/**
 * Procest Advice Service.
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
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Advice\AdviceAuthorizationGuard;
use OCA\Procest\Service\Advice\AdviceNotifier;
use OCA\Procest\Service\Advice\AdviceRepository;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for advice request (adviesAanvraag) workflow.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class AdviceService {

	/**
	 * Valid advice statuses.
	 */
	private const VALID_STATUSES = [
		'aangevraagd',
		'ontvangen',
		'verlopen',
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
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly AdviceDelegationService $adviceDelegation,
		private readonly AdviceRepository $repository,
		private readonly AdviceAuthorizationGuard $guard,
		private readonly AdviceNotifier $notifier,
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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

		if ($to === 'ontvangen') {
			$update['receivedAt'] = date('c');
			$fileId = (string)($payload['adviceDocument'] ?? ($payload['fileId'] ?? ''));
			if ($fileId !== '') {
				$update['adviceDocument'] = $fileId;
			}
		}

		$advice = $this->repository->save(update: $update, adviceId: $adviceId);

		$this->notifier->fireTransitionNotification(
			to: $to,
			current: $current,
			adviceId: $adviceId,
			callerId: $this->getUserId(),
		);

		return $advice;
	}//end applyTransition()

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
	 * TRUST BOUNDARY: this is the system/cron seam (`AdviceDeadlineJob`), which
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function applyWorkflowGuard(string $caseId): array {
		$all = $this->getAdviceForCase(caseId: $caseId);
		$pending = [];

		foreach ($all as $advice) {
			$status = (string)($advice['status'] ?? '');
			if ($status === 'aangevraagd') {
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getAdviceForCase(string $caseId): array {
		return $this->repository->findForCase(caseId: $caseId);
	}//end getAdviceForCase()

	/**
	 * Load all open advice requests across the system (for the deadline job).
	 *
	 * @return array<int, array<string, mixed>> Open advice records
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getOpenAdvice(): array {
		return $this->repository->findOpen();
	}//end getOpenAdvice()

	/**
	 * Mark an advice request as expired (status -> verlopen).
	 *
	 * SYSTEM/CRON PATH — called by AdviceDeadlineJob, which runs with NO user
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

			return $this->applyTransition(adviceId: $adviceId, to: 'verlopen', current: $current);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest: failed to expire advice: ' . $e->getMessage(),
				['app' => Application::APP_ID]
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
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-6
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function requestAdvice(string $caseId, array $data, string $requestedBy): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');

		$payload = [
			'caseRef' => $caseId,
			'requestedBy' => $requestedBy,
			'advisor' => $data['advisor'] ?? '',
			'deadline' => $data['deadline'] ?? null,
			'status' => 'open',
			'question' => $data['question'] ?? '',
			'adviceText' => '',
			'addedToFile' => false,
		];

		$saved = $objectService->saveObject(
			register: $register,
			schema: 'adviceRequest',
			object: $payload
		);

		$savedRecord = [];
		if (is_array($saved) === true) {
			$savedRecord = $saved;
		}

		$adviceId = (string)($savedRecord['id'] ?? ($savedRecord['uuid'] ?? ''));

		$this->delegateAdviceDecision(
			objectService: $objectService,
			register: $register,
			caseId: $caseId,
			adviceId: $adviceId,
			data: $data,
			payload: $payload,
			saved: $savedRecord,
		);

		$this->notifier->notifyAdviseur(
			caseId: $caseId,
			payload: $payload,
			saved: $savedRecord,
		);

		$this->logger->info(
			'Advice request created for case ' . $caseId . ' by ' . $requestedBy,
			['app' => Application::APP_ID]
		);

		return $savedRecord;
	}//end requestAdvice()

	/**
	 * Raise the decidesk `advice` Decision for a new advice request and
	 * persist its reference on the saved adviceRequest.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $register The register id
	 * @param string $caseId UUID of the case
	 * @param string $adviceId UUID of the saved adviceRequest
	 * @param array<string, mixed> $data Advice request data
	 * @param array<string, mixed> $payload The persisted adviceRequest payload
	 * @param array<string, mixed> $saved The normalized saveObject() result
	 *
	 * @return void
	 *
	 * @throws RuntimeException If decidesk fails closed
	 */
	private function delegateAdviceDecision(
		object $objectService,
		string $register,
		string $caseId,
		string $adviceId,
		array $data,
		array $payload,
		array $saved,
	): void {
		// REQ-PDRD-001 / REQ-PDRD-002: the advice is *made* in decidesk. Raise a
		// decidesk `advice` Decision for this request and persist its ref. Fail
		// CLOSED — never author an advice outcome locally as a fallback.
		$subjectId = $caseId;
		if ($adviceId !== '') {
			$subjectId = $adviceId;
		}

		try {
			$decisionRef = $this->adviceDelegation->raiseAdviceDecision(
				subjectSchema: 'adviesAanvraag',
				subjectId: $subjectId,
				payload: [
					'subjectRegister' => $register,
					'externalReference' => $caseId,
					'subjectLabel' => (string)($data['question'] ?? 'Adviesaanvraag'),
					'question' => (string)($data['question'] ?? ''),
					'advisor' => (string)$payload['advisor'],
				],
			);

			if ($adviceId !== '') {
				$objectService->saveObject(
					object: array_merge($saved, ['decisionRef' => $decisionRef]),
					register: $register,
					schema: 'adviceRequest',
					uuid: $adviceId,
				);
			}
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Procest: requestAdvice: decidesk advice Decision raise failed — failing closed: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			// REQ-PDRD-002: fail closed; surface the error.
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end delegateAdviceDecision()
}//end class
