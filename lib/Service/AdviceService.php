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
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for advice request (adviesAanvraag) workflow.
 */
class AdviceService
{

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
     * @param SettingsService      $settingsService     The settings service
     * @param IUserSession         $userSession         The current user session
     * @param INotificationManager $notificationManager The notification manager
     * @param LoggerInterface      $logger              The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
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
            $results = $objectService->findObjects(
                $register,
                $schema,
                ['case' => $caseId],
                [],
                200,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to fetch advice for case: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        if (is_array($results) === true) {
            return $results;
        }

        return [];
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
            $results = $objectService->findObjects(
                $register,
                $schema,
                ['status' => 'aangevraagd'],
                [],
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to load open advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        if (is_array($results) === true) {
            return $results;
        }

        return [];
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
     * @throws RuntimeException If OpenRegister is unavailable
     *
     * @spec openspec/changes/vth-module/tasks.md#task-6
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
