<?php

/**
 * Procest Berichtenbox Service.
 *
 * Sends citizen-facing messages through a pluggable Mijn Overheid
 * Berichtenbox adapter and records them in OpenRegister.
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
 * @spec openspec/specs/berichtenbox-integration/spec.md
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use OCA\Procest\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Procest\Service\BerichtenboxAdapter\MockAdapter;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for sending messages to Mijn Overheid Berichtenbox.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class BerichtenboxService
{
    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service.
     * @param IAppManager        $appManager      The Nextcloud app manager.
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send a message to the Berichtenbox.
     *
     * @param string      $caseId           The case UUID.
     * @param string      $bsn              Citizen BSN.
     * @param string      $subject          Message subject.
     * @param string      $body             Plain text message body.
     * @param string      $typeCode         Bericht type code.
     * @param string|null $attachmentFileId Optional Nextcloud file ID of the attachment.
     *
     * @return array<string, mixed> The stored message record or an error payload.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function sendMessage(
        string $caseId,
        string $bsn,
        string $subject,
        string $body,
        string $typeCode,
        ?string $attachmentFileId=null,
    ): array {
        // Validate inputs.
        $errors = $this->validateMessage(bsn: $bsn, subject: $subject, body: $body);
        if (empty($errors) === false) {
            return ['error' => implode('; ', $errors), 'errors' => $errors];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        // Get attachment content if provided.
        $attachmentContent = null;
        if ($attachmentFileId !== null) {
            // Attachment validation would check file size here.
            $attachmentContent = '';
            // Placeholder -- actual file reading via IRootFolder.
        }

        // Send via adapter.
        $adapter = $this->getAdapter();
        $result  = $adapter->sendMessage($bsn, $subject, $body, $typeCode, $attachmentContent);

        // Store message record.
        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('berichtenbox_message_schema');

        $messageData = [
            'caseId'            => $caseId,
            'bsn'               => $bsn,
            'subject'           => $subject,
            'body'              => $body,
            'berichtTypeCode'   => $typeCode,
            'attachmentFileId'  => $attachmentFileId,
            'externalMessageId' => $result['messageId'] ?? null,
            'status'            => $result['status'] ?? 'sent',
            'sentAt'            => $result['sentAt'] ?? (new DateTime())->format('c'),
        ];

        $saved = $objectService->saveObject(
            object: $messageData,
            register: (int) $register,
            schema: (int) $schema,
        );

        $this->logger->info(
                'Procest: Berichtenbox message sent',
                [
                    'caseId'    => $caseId,
                    'messageId' => $result['messageId'] ?? '',
                ]
                );

        return $saved->jsonSerialize();
    }//end sendMessage()

    /**
     * Get sent messages for a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<int, mixed> List of stored Berichtenbox messages for the case.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getMessagesForCase(string $caseId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('berichtenbox_message_schema');

        return $objectService->findAll(
            ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'caseId' => $caseId]],
        );
    }//end getMessagesForCase()

    /**
     * Get all messages whose read-status still needs to be polled.
     *
     * Returns messages with status 'sent' or 'unread_flagged' that carry an
     * externalMessageId (i.e. they were actually delivered to Berichtenbox).
     *
     * @return array<int, mixed> List of pending message records.

     * @spec openspec/specs/berichtenbox-integration/spec.md
     */
    public function getPendingMessages(): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('berichtenbox_message_schema');

        $sent = $objectService->findAll(
            ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'status' => 'sent']],
        );

        $flagged = $objectService->findAll(
            ['filters' => ['register' => (int) $register, 'schema' => (int) $schema, 'status' => 'unread_flagged']],
        );

        return array_merge($sent, $flagged);
    }//end getPendingMessages()

    /**
     * Poll read status for a message.
     *
     * @param string $messageId The OpenRegister message UUID.
     *
     * @return array<string, mixed> The message record, possibly updated with read status.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function pollReadStatus(string $messageId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('berichtenbox_message_schema');

        $message = $objectService->find($messageId, register: (int) $register, schema: (int) $schema);
        $data    = $message->jsonSerialize();

        if (empty($data['externalMessageId']) === true) {
            return $data;
        }

        $adapter = $this->getAdapter();
        $status  = $adapter->getReadStatus($data['externalMessageId']);

        $data['readPolledAt'] = (new DateTime())->format('c');

        if (($status['read'] ?? false) === true) {
            $data['status'] = 'read';
            $data['readAt'] = $status['readAt'];
            $objectService->saveObject(object: $data, register: (int) $register, schema: (int) $schema);

            return $data;
        }

        // Check if unread for > 7 days.
        if (empty($data['sentAt']) === false) {
            $sentAt = new DateTime($data['sentAt']);
            $diff   = (new DateTime())->diff($sentAt)->days;
            if ($diff >= 7 && $data['status'] !== 'unread_flagged') {
                $data['status'] = 'unread_flagged';
            }
        }

        $objectService->saveObject(object: $data, register: (int) $register, schema: (int) $schema);

        return $data;
    }//end pollReadStatus()

    /**
     * Validate a BSN using the 11-proef.
     *
     * @param string $bsn The BSN to validate.
     *
     * @return bool True when the BSN is a 9-digit number passing the 11-proef.

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function validateBsn(string $bsn): bool
    {
        if (preg_match('/^\d{9}$/', $bsn) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $bsn[$i] * (9 - $i);
        }

        $sum -= (int) $bsn[8];

        return ($sum % 11) === 0 && $sum !== 0;
    }//end validateBsn()

    /**
     * Validate message inputs.
     *
     * @param string $bsn     Citizen BSN.
     * @param string $subject Message subject.
     * @param string $body    Plain text message body.
     *
     * @return array<int, string> List of validation error messages, empty when valid.
     */
    private function validateMessage(string $bsn, string $subject, string $body): array
    {
        $errors = [];

        if (empty($bsn) === true) {
            $errors[] = 'BSN is verplicht voor berichten via Mijn Overheid';
        } else if ($this->validateBsn(bsn: $bsn) === false) {
            $errors[] = 'Ongeldig BSN-nummer';
        }

        if (empty($subject) === true) {
            $errors[] = 'Onderwerp is verplicht';
        }

        if (empty($body) === true) {
            $errors[] = 'Berichttekst is verplicht';
        }

        // Strip HTML from body (plain text only).
        if ($body !== strip_tags($body)) {
            $errors[] = 'Berichttekst mag alleen platte tekst bevatten';
        }

        return $errors;
    }//end validateMessage()

    /**
     * Get the configured Berichtenbox adapter.
     *
     * @return BerichtenboxAdapterInterface The adapter instance.
     */
    private function getAdapter(): BerichtenboxAdapterInterface
    {
        // For MVP, always use mock adapter.
        return new MockAdapter(logger: $this->logger);
    }//end getAdapter()

    /**
     * Resolve the OpenRegister ObjectService if OpenRegister is installed.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The object service or null.
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('Procest: Could not get ObjectService', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end getObjectService()
}//end class
