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
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for advice request (adviesAanvraag) workflow.
 */
class AdviceService
{

    use SearchesObjects;


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
     * @param SettingsService         $settingsService     The settings service
     * @param IUserSession            $userSession         The current user session
     * @param IGroupManager           $groupManager        Group manager (Wilco #6 IDOR fix)
     * @param INotificationManager    $notificationManager The notification manager
     * @param LoggerInterface         $logger              The logger
     * @param AdviceDelegationService $adviceDelegation    Advice delegation to decidesk (ADR-019)
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
        private readonly AdviceDelegationService $adviceDelegation,
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
     * @param string               $adviceId The advice UUID
     * @param string               $to       Target status
     * @param array<string, mixed> $payload  Extra fields (adviesDocument, etc.)
     *
     * @return array<string, mixed> Updated advice record
     *
     * @throws \RuntimeException When OpenRegister unavailable / invalid status

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function transitionStatus(string $adviceId, string $to, array $payload=[]): array
    {
        if (in_array($to, self::VALID_STATUSES, true) === false) {
            throw new RuntimeException('Invalid advice status');
        }

        $current = $this->loadAdvice(adviceId: $adviceId);
        if ($current === null) {
            // Collapse not-found and access-denied into one "not accessible"
            // error so the endpoint cannot be used as an existence oracle for
            // advice UUIDs (same pattern as docudesk#100 / Wilco #6).
            throw new RuntimeException('Advice request not accessible');
        }

        $this->assertAdviceTransitionAuthorized(advice: $current, to: $to);

        return $this->applyTransition(adviceId: $adviceId, to: $to, current: $current, payload: $payload);
    }//end transitionStatus()

    /**
     * Authorize an advice status transition against the CALLER's relationship
     * to the advice request. Fails closed.
     *
     * This is the procest#17 / Wilco #6 IDOR guard, ported onto the live path.
     * It previously existed only on `submitAdvice()`, which had zero callers and
     * was never routed — so the IDOR it was written to close stayed open on
     * `transitionStatus()`, the path the UI actually calls
     * (`POST /api/advice/{id}/transition`, appinfo/routes.php).
     *
     * Keyed on the LIVE `adviesAanvraag` schema. The dead guard read a
     * `requestedBy` field that does not exist there (it belongs to the unused
     * `adviceRequest` schema); the requester relationship is expressed instead
     * through `case` -> `case.assignee`.
     *
     * Matrix (admins bypass all per-object checks):
     *   - ontvangen:   the assigned `adviseur` only. This is the open IDOR —
     *                  previously ANY authenticated user could mark ANY advice
     *                  request as received.
     *   - aangevraagd: the handler of the linked case, or the `adviseur`.
     *   - verlopen:    nobody. Expiry is a system transition owned by
     *                  AdviceDeadlineJob; it reaches the write through
     *                  applyTransition() and never through this method.
     *   - default:     denied (fail closed).
     *
     * @param array<string, mixed> $advice The current advice record.
     * @param string               $to     Target status.
     *
     * @return void
     *
     * @throws \RuntimeException When the caller is not authorized.
     *
     * @spec openspec/changes/authz-bypass-fixes/specs/authz-bypass-fixes/spec.md
     */
    private function assertAdviceTransitionAuthorized(array $advice, string $to): void
    {
        $uid = $this->getUserId();
        if ($uid === '') {
            throw new RuntimeException('Not authenticated');
        }

        if ($this->groupManager->isAdmin($uid) === true) {
            return;
        }

        $adviseur = (string) ($advice['adviseur'] ?? '');

        if ($to === 'ontvangen') {
            if ($adviseur !== '' && $adviseur === $uid) {
                return;
            }

            throw new RuntimeException('Advice request not accessible');
        }

        if ($to === 'aangevraagd') {
            if ($adviseur !== '' && $adviseur === $uid) {
                return;
            }

            if ($this->isHandlerOfLinkedCase(advice: $advice, uid: $uid) === true) {
                return;
            }

            throw new RuntimeException('Advice request not accessible');
        }

        // `verlopen` is system-only; any other status is unknown. Fail closed.
        throw new RuntimeException('Advice request not accessible');
    }//end assertAdviceTransitionAuthorized()

    /**
     * Whether the given uid is the assignee of the case this advice belongs to.
     *
     * @param array<string, mixed> $advice The advice record.
     * @param string               $uid    The caller's user id.
     *
     * @return bool True when the caller handles the linked case.
     *
     * @spec openspec/changes/authz-bypass-fixes/specs/authz-bypass-fixes/spec.md
     */
    private function isHandlerOfLinkedCase(array $advice, string $uid): bool
    {
        $caseId = (string) ($advice['case'] ?? '');
        if ($caseId === '') {
            return false;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($caseSchema) === true) {
            return false;
        }

        $case = $this->findObjectAsArray(
            objectService: $objectService,
            register: $register,
            schema: $caseSchema,
            id: $caseId
        );

        if ($case === null) {
            return false;
        }

        $assignee = (string) ($case['assignee'] ?? '');

        return ($assignee !== '' && $assignee === $uid);
    }//end isHandlerOfLinkedCase()

    /**
     * Apply an advice status transition WITHOUT an authorization check.
     *
     * TRUST BOUNDARY: this is the system/cron seam. It must only ever be called
     * from `transitionStatus()` (which authorizes first) or from a code-driven
     * background job with no user session (`expireAdvice()`). Never call it with
     * user-supplied intent that has not been through
     * `assertAdviceTransitionAuthorized()`.
     *
     * @param string               $adviceId The advice UUID.
     * @param string               $to       Target status.
     * @param array<string, mixed> $current  The current advice record (pre-update).
     * @param array<string, mixed> $payload  Extra fields (adviesDocument, etc.).
     *
     * @return array<string, mixed> Updated advice record.
     *
     * @throws \RuntimeException When OpenRegister is unavailable / not configured.
     *
     * @spec openspec/changes/authz-bypass-fixes/specs/authz-bypass-fixes/spec.md
     */
    private function applyTransition(string $adviceId, string $to, array $current, array $payload=[]): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new RuntimeException('Advice schema is not configured');
        }

        $update = ['status' => $to];

        if ($to === 'ontvangen') {
            $update['receivedAt'] = date('c');
            $fileId = (string) ($payload['adviesDocument'] ?? ($payload['fileId'] ?? ''));
            if ($fileId !== '') {
                $update['adviesDocument'] = $fileId;
            }
        }

        try {
            $advice = $objectService->saveObject(object: $update, register: $register, schema: $schema, uuid: (string) $adviceId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to transition advice status: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new RuntimeException('Could not update advice request');
        }

        $advice = $this->normalizeResult(result: $advice);

        $this->fireTransitionNotification(to: $to, current: $current, adviceId: $adviceId);

        return $advice;
    }//end applyTransition()

    /**
     * Dispatch a reminder notification to the adviseur.
     *
     * Called by the manual remind endpoint and by the daily deadline cron.
     *
     * @param string $adviceId The advice UUID
     *
     * @return void

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function dispatchReminder(string $adviceId): void
    {
        $advice = $this->loadAdvice(adviceId: $adviceId);
        if ($advice === null) {
            return;
        }

        $adviseur = (string) ($advice['adviseur'] ?? '');
        if ($adviseur === '') {
            return;
        }

        $this->sendUserNotification(userId: $adviseur, subject: 'advies_herinnering', objectId: $adviceId);
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function applyWorkflowGuard(string $caseId): array
    {
        $all     = $this->getAdviceForCase(caseId: $caseId);
        $pending = [];

        foreach ($all as $advice) {
            $status = (string) ($advice['status'] ?? '');
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getAdviceForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        try {
            return $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['case' => $caseId, '_limit' => 200],
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to fetch advice for case: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }
    }//end getAdviceForCase()

    /**
     * Load all open advice requests across the system (for the deadline job).
     *
     * @return array<int, array<string, mixed>> Open advice records

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getOpenAdvice(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        try {
            return $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['status' => 'aangevraagd', '_limit' => 500],
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to load open advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }
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

     * @spec openspec/changes/authz-bypass-fixes/specs/authz-bypass-fixes/spec.md
     */
    public function expireAdvice(string $adviceId): array
    {
        try {
            $current = $this->loadAdvice(adviceId: $adviceId);
            if ($current === null) {
                throw new RuntimeException('Advice request not accessible');
            }

            return $this->applyTransition(adviceId: $adviceId, to: 'verlopen', current: $current);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to expire advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }
    }//end expireAdvice()

    /**
     * Load a single advice request by id.
     *
     * @param string $adviceId The advice UUID
     *
     * @return array<string, mixed>|null Advice data or null
     */
    private function loadAdvice(string $adviceId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $advice = $objectService->find($adviceId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to load advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }

        return $this->normalizeResult(result: $advice);
    }//end loadAdvice()

    /**
     * Fire the notification that matches a status transition.
     *
     * @param string               $to       Target status
     * @param array<string, mixed> $current  Current advice record (pre-update)
     * @param string               $adviceId The advice UUID
     *
     * @return void
     */
    private function fireTransitionNotification(string $to, array $current, string $adviceId): void
    {
        if ($to === 'aangevraagd') {
            $adviseur = (string) ($current['adviseur'] ?? '');
            if ($adviseur !== '') {
                $this->sendUserNotification(
                    userId: $adviseur,
                    subject: 'advies_aangevraagd',
                    objectId: $adviceId,
                    message: (string) ($current['onderwerp'] ?? '')
                );
            }

            return;
        }

        if ($to === 'ontvangen') {
            $caller = $this->getUserId();
            if ($caller !== '') {
                $this->sendUserNotification(userId: $caller, subject: 'advies_ontvangen', objectId: $adviceId);
            }
        }
    }//end fireTransitionNotification()

    /**
     * Convert an object/array result to an associative array.
     *
     * @param mixed $result The OpenRegister return value
     *
     * @return array<string, mixed> Normalized advice record
     */
    private function normalizeResult($result): array
    {
        if (is_array($result) === true) {
            return $result;
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $data = $result->jsonSerialize();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return [];
    }//end normalizeResult()

    /**
     * Resolve the current user id from session (never trust client-supplied user).
     *
     * @return string The current user UID or empty string
     */
    private function getUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();
    }//end getUserId()


    /**
     * Send a Nextcloud notification to a user.
     *
     * @param string $userId   Recipient user UID
     * @param string $subject  Notification subject key
     * @param string $objectId The object UUID (case or advice)
     * @param string $message  Additional message context
     *
     * @return void
     */
    private function sendUserNotification(
        string $userId,
        string $subject,
        string $objectId,
        string $message='',
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new DateTime())
                ->setObject('advies', $objectId)
                ->setSubject($subject, ['object' => $objectId]);

            if ($message !== '') {
                $notification->setMessage('plain', ['message' => $message]);
            }

            $this->notificationManager->notify($notification);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to send advice notification: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }//end sendUserNotification()

    /**
     * Create an advice request for a VTH case.
     *
     * Stores the adviceRequest in the `adviceRequest` schema and sends a
     * notification to the adviseur. Corresponds to tasks.md#task-6.
     *
     * @param string               $caseId      UUID of the case
     * @param array<string, mixed> $data        Advice request data (adviseur, deadline, vraag, etc.)
     * @param string               $requestedBy User UID of the requester
     *
     * @return array<string, mixed> Saved adviceRequest object
     *
     * @throws RuntimeException If OpenRegister is unavailable or decidesk fails closed
     *
     * @spec openspec/changes/vth-module/tasks.md#task-6
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-001-remaining-decisionadvice-flows-are-raised-as-decidesk-decisions
     * @spec openspec/changes/procest-delegate-remaining-decisions-to-decidesk/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
     */
    public function requestAdvice(string $caseId, array $data, string $requestedBy): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');

        $payload = [
            'caseRef'     => $caseId,
            'requestedBy' => $requestedBy,
            'adviseur'    => $data['adviseur'] ?? '',
            'deadline'    => $data['deadline'] ?? null,
            'status'      => 'open',
            'vraag'       => $data['vraag'] ?? '',
            'adviesText'  => '',
            'addedToFile' => false,
        ];

        $saved = $objectService->saveObject(
            register: $register,
            schema: 'adviceRequest',
            object: $payload
        );

        $adviceId = '';
        if (is_array($saved) === true) {
            $adviceId = (string) ($saved['id'] ?? ($saved['uuid'] ?? ''));
        }

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
                    'subjectRegister'   => $register,
                    'externalReference' => $caseId,
                    'subjectLabel'      => (string) ($data['vraag'] ?? 'Adviesaanvraag'),
                    'question'          => (string) ($data['vraag'] ?? ''),
                    'adviseur'          => (string) ($payload['adviseur'] ?? ''),
                ],
            );

            if ($adviceId !== '') {
                $savedBase = [];
                if (is_array($saved) === true) {
                    $savedBase = $saved;
                }

                $objectService->saveObject(
                    object: array_merge($savedBase, ['decisionRef' => $decisionRef]),
                    register: $register,
                    schema: 'adviceRequest',
                    uuid: $adviceId,
                );
            }
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Procest: requestAdvice: decidesk advice Decision raise failed — failing closed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            // REQ-PDRD-002: fail closed; surface the error.
            throw new RuntimeException('Decision service unavailable: '.$e->getMessage(), 0, $e);
        }//end try

        $adviseur = $payload['adviseur'];
        if ($adviseur !== '') {
            $notificationObjectId = $caseId;
            if (is_array($saved) === true) {
                $notificationObjectId = $saved['id'] ?? $caseId;
            }

            $this->sendUserNotification(
                userId: $adviseur,
                objectId: $notificationObjectId,
                subject: 'advice_requested',
                message: 'Adviesaanvraag voor zaak '.$caseId
            );
        }

        $this->logger->info(
            'Advice request created for case '.$caseId.' by '.$requestedBy,
            ['app' => Application::APP_ID]
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        return [];
    }//end requestAdvice()
}//end class
