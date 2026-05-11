<?php

/**
 * Procest Advice Service.
 *
 * Service for managing advice requests (adviesAanvraag) on cases.
 * Handles CRUD, status transitions, deadline tracking, and notification
 * dispatch for internal and external advice.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Service for advice request (adviesAanvraag) management.
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
     * Valid advice types.
     */
    private const VALID_TYPES = [
        'intern',
        'extern',
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
     * Create a new advice request linked to a case.
     *
     * @param string               $caseId The case UUID this advice is for
     * @param array<string, mixed> $data   Advice data (adviseur, type, onderwerp, deadline, questions)
     *
     * @return array<string, mixed> Created advice record
     *
     * @throws \RuntimeException When OpenRegister unavailable or validation fails
     */
    public function createAdvice(string $caseId, array $data): array
    {
        if ($caseId === '') {
            throw new \RuntimeException('Case ID is required');
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Advice schema is not configured');
        }

        $adviseur = (string) ($data['adviseur'] ?? '');
        $type     = (string) ($data['type'] ?? '');

        if ($adviseur === '') {
            throw new \RuntimeException('adviseur is required');
        }

        if (in_array($type, self::VALID_TYPES, true) === false) {
            throw new \RuntimeException('Invalid advice type');
        }

        $payload = [
            'case'        => $caseId,
            'adviseur'    => $adviseur,
            'type'        => $type,
            'onderwerp'   => (string) ($data['onderwerp'] ?? ''),
            'deadline'    => (string) ($data['deadline'] ?? ''),
            'status'      => 'aangevraagd',
            'requestedAt' => date('c'),
            'questions'   => (string) ($data['questions'] ?? ''),
        ];

        try {
            $advice = $objectService->saveObject($register, $schema, $payload);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to create advice request: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not create advice request');
        }

        $this->notifyAdviseur($adviseur, $caseId, $payload['onderwerp']);

        return $this->normalizeResult($advice);
    }//end createAdvice()

    /**
     * Mark an advice request as received (status -> ontvangen).
     *
     * @param string $adviceId The advice UUID
     * @param string $fileId   Nextcloud file ID of the advice document
     *
     * @return array<string, mixed> Updated advice record
     *
     * @throws \RuntimeException When OpenRegister unavailable
     */
    public function receiveAdvice(string $adviceId, string $fileId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Advice schema is not configured');
        }

        $update = [
            'status'         => 'ontvangen',
            'receivedAt'     => date('c'),
        ];

        if ($fileId !== '') {
            $update['adviesDocument'] = $fileId;
        }

        try {
            $advice = $objectService->saveObject($register, $schema, $update, $adviceId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to mark advice received: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            throw new \RuntimeException('Could not update advice request');
        }

        $current = $this->getUserId();
        if ($current !== '') {
            $this->sendUserNotification($current, 'advies_ontvangen', $adviceId);
        }

        return $this->normalizeResult($advice);
    }//end receiveAdvice()

    /**
     * Send a reminder notification to the adviseur for an advice request.
     *
     * @param string $adviceId The advice UUID
     *
     * @return void
     */
    public function sendReminder(string $adviceId): void
    {
        $advice = $this->loadAdvice($adviceId);
        if ($advice === null) {
            return;
        }

        $adviseur = (string) ($advice['adviseur'] ?? '');
        if ($adviseur === '') {
            return;
        }

        $this->sendUserNotification($adviseur, 'advies_herinnering', $adviceId);
    }//end sendReminder()

    /**
     * Get all advice requests for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return array<int, array<string, mixed>> Advice records for the case
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
        } catch (\Throwable $e) {
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
     * Get pending advice (status=aangevraagd) for a case — used as workflow guard.
     *
     * @param string $caseId The case UUID
     *
     * @return array<int, array<string, mixed>> Pending advice records
     */
    public function checkGuard(string $caseId): array
    {
        $all     = $this->getAdviceForCase($caseId);
        $pending = [];

        foreach ($all as $advice) {
            $status = (string) ($advice['status'] ?? '');
            if ($status === 'aangevraagd') {
                $pending[] = $advice;
            }
        }

        return $pending;
    }//end checkGuard()

    /**
     * Load all open advice requests across the system (for the deadline job).
     *
     * @return array<int, array<string, mixed>> Open advice records
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
        } catch (\Throwable $e) {
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
     * @param string $adviceId The advice UUID
     *
     * @return array<string, mixed> Updated advice record
     */
    public function expireAdvice(string $adviceId): array
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
            $advice = $objectService->saveObject(
                $register,
                $schema,
                ['status' => 'verlopen'],
                $adviceId,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to expire advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        return $this->normalizeResult($advice);
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
            $advice = $objectService->findObject($register, $schema, $adviceId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load advice: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }

        return $this->normalizeResult($advice);
    }//end loadAdvice()

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
     * Notify the adviseur (internal) about a new advice request.
     *
     * @param string $adviseur Adviseur identifier (UID for internal)
     * @param string $caseId   The case UUID
     * @param string $subject  Subject of the advice request
     *
     * @return void
     */
    private function notifyAdviseur(string $adviseur, string $caseId, string $subject): void
    {
        if ($adviseur === '') {
            return;
        }

        $this->sendUserNotification($adviseur, 'advies_aangevraagd', $caseId, $subject);
    }//end notifyAdviseur()

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
        string $message = '',
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('advies', $objectId)
                ->setSubject($subject, ['object' => $objectId]);

            if ($message !== '') {
                $notification->setMessage('plain', ['message' => $message]);
            }

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to send advice notification: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }//end sendUserNotification()
}//end class
