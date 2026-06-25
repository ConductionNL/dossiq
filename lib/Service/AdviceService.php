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

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new RuntimeException('Advice schema is not configured');
        }

        $current = $this->loadAdvice(adviceId: $adviceId);
        if ($current === null) {
            throw new RuntimeException('Advice request not found');
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
    }//end transitionStatus()

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
     * Convenience wrapper used by the deadline cron. Delegates to
     * transitionStatus() to keep the notification dispatch consistent.
     *
     * @param string $adviceId The advice UUID
     *
     * @return array<string, mixed> Updated advice record

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function expireAdvice(string $adviceId): array
    {
        try {
            return $this->transitionStatus(adviceId: $adviceId, to: 'verlopen');
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
     * Submit advice (mark as received with optional adviesText).
     *
     * @param string               $adviceId The advice request UUID
     * @param array<string, mixed> $payload  {adviesText, adviesDocument}
     *
     * @return array<string, mixed> The updated advice request
     *
     * @throws \RuntimeException When OpenRegister unavailable or transition invalid.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-6
     */
    public function submitAdvice(string $adviceId, array $payload): array
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

        // Wilco #6 / procest#17 IDOR fix (2026-06-06): the caller must be
        // the assigned `adviseur` (or an admin) — previously any authed
        // user could submit advice on any UUID, including ones the
        // adviseur hadn't yet seen. Read the current record and gate
        // before applying the update.
        $this->assertAdviceCallerIsAuthorized(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            adviceId: $adviceId,
            action: 'submit',
        );

        $update = [
            'status'     => 'received',
            'receivedAt' => date('c'),
        ];

        $adviesText = (string) ($payload['adviesText'] ?? '');
        if ($adviesText !== '') {
            $update['adviesText'] = $adviesText;
        }

        $adviesDocument = (string) ($payload['adviesDocument'] ?? '');
        if ($adviesDocument !== '') {
            $update['adviesDocument'] = $adviesDocument;
        }

        try {
            // Pre-existing positional-arg drift fixed (CLAUDE.md mandate):
            // saveObject is (object, register, schema, uuid) — the previous
            // ($register, $schema, $update, $adviceId) order wrote the wrong
            // fields. Use named args to match every other call in this service.
            $result = $objectService->saveObject(
                object: $update,
                register: $register,
                schema: $schema,
                uuid: (string) $adviceId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to submit advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new RuntimeException('Could not submit advice');
        }

        return $this->normalizeResult(result: $result);
    }//end submitAdvice()

    /**
     * Cancel an advice request.
     *
     * @param string $adviceId The advice request UUID
     *
     * @return array<string, mixed> The updated advice request
     *
     * @throws \RuntimeException When OpenRegister unavailable or advice not found.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-6
     */
    public function cancelAdvice(string $adviceId): array
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

        // Wilco #6 / procest#17 IDOR fix (2026-06-06): only the requester
        // (or an admin) may cancel — previously any authed user could
        // cancel any advice by UUID.
        $this->assertAdviceCallerIsAuthorized(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            adviceId: $adviceId,
            action: 'cancel',
        );

        try {
            // Pre-existing positional-arg drift fixed (CLAUDE.md mandate):
            // saveObject is (object, register, schema, uuid).
            $result = $objectService->saveObject(
                object: ['status' => 'cancelled'],
                register: $register,
                schema: $schema,
                uuid: (string) $adviceId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to cancel advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new RuntimeException('Could not cancel advice request');
        }

        return $this->normalizeResult(result: $result);
    }//end cancelAdvice()

    /**
     * Authorization gate for advice mutations (Wilco #6 / procest#17 IDOR fix).
     *
     * - submit: only the assigned `adviseur` (or an admin) may submit.
     * - cancel: only the `requestedBy` (or an admin) may cancel.
     *
     * The current user is obtained from IUserSession + IGroupManager
     * (already injected on this service). The advice record is fetched
     * from OR via the same objectService used by the calling method.
     *
     * @param object $objectService The OR object service
     * @param string $register      The register slug
     * @param string $schema        The advies_aanvraag schema slug
     * @param string $adviceId      The advice UUID
     * @param string $action        Either 'submit' or 'cancel'
     *
     * @return void
     *
     * @throws RuntimeException When the caller is not authorised, the
     *                          record is missing, or OR is unavailable.
     */
    private function assertAdviceCallerIsAuthorized(
        object $objectService,
        string $register,
        string $schema,
        string $adviceId,
        string $action,
    ): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new RuntimeException('Not authenticated');
        }

        $uid = $user->getUID();
        if ($this->groupManager->isAdmin($uid) === true) {
            return;
        }

        try {
            $record = $objectService->find($adviceId, $register, $schema);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: advice lookup failed during IDOR gate: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            // Collapse not-found and access-denied to the same "not
            // accessible" error to avoid an existence-probing oracle
            // (same pattern as docudesk#100 Wilco #6 fix).
            throw new RuntimeException('Advice request not accessible');
        }

        $data = $this->normalizeResult(result: $record);
        if ($action === 'submit') {
            $field = 'adviseur';
        } else {
            $field = 'requestedBy';
        }

        if (($data[$field] ?? '') !== $uid) {
            throw new RuntimeException('Advice request not accessible');
        }

    }//end assertAdviceCallerIsAuthorized()

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
