<?php

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Procest\Service\BerichtenboxAdapter\MockAdapter;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for sending messages to Mijn Overheid Berichtenbox.
 */
class BerichtenboxService
{
    private const MAX_ATTACHMENT_SIZE = 10485760; // 10 MB

    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send a message to the Berichtenbox.
     */
    public function sendMessage(
        string $caseId,
        string $bsn,
        string $subject,
        string $body,
        string $typeCode,
        ?string $attachmentFileId = null,
    ): array {
        // Validate inputs.
        $errors = $this->validateMessage($bsn, $subject, $body);
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
            $attachmentContent = ''; // Placeholder -- actual file reading via IRootFolder.
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
            'sentAt'            => $result['sentAt'] ?? (new \DateTime())->format('c'),
        ];

        $saved = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $messageData,
        );

        $this->logger->info('Procest: Berichtenbox message sent', [
            'caseId'    => $caseId,
            'messageId' => $result['messageId'] ?? '',
        ]);

        return $saved->jsonSerialize();
    }

    /**
     * Get sent messages for a case.
     */
    public function getMessagesForCase(string $caseId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('berichtenbox_message_schema');

        $result = $objectService->getObjects(
            (int) $register,
            (int) $schema,
            ['caseId' => $caseId],
        );

        return $result['objects'] ?? [];
    }

    /**
     * Poll read status for a message.
     */
    public function pollReadStatus(string $messageId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('berichtenbox_message_schema');

        $message = $objectService->getObject((int) $register, (int) $schema, $messageId);
        $data    = $message->jsonSerialize();

        if (empty($data['externalMessageId']) === true) {
            return $data;
        }

        $adapter = $this->getAdapter();
        $status  = $adapter->getReadStatus($data['externalMessageId']);

        if (($status['read'] ?? false) === true) {
            $data['status']       = 'read';
            $data['readAt']       = $status['readAt'];
            $data['readPolledAt'] = (new \DateTime())->format('c');
            $objectService->saveObject((int) $register, (int) $schema, $data);
        } else {
            $data['readPolledAt'] = (new \DateTime())->format('c');

            // Check if unread for > 7 days.
            if (empty($data['sentAt']) === false) {
                $sentAt = new \DateTime($data['sentAt']);
                $diff   = (new \DateTime())->diff($sentAt)->days;
                if ($diff >= 7 && $data['status'] !== 'unread_flagged') {
                    $data['status'] = 'unread_flagged';
                }
            }

            $objectService->saveObject((int) $register, (int) $schema, $data);
        }

        return $data;
    }

    /**
     * Validate a BSN using the 11-proef.
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
    }

    /**
     * Validate message inputs.
     */
    private function validateMessage(string $bsn, string $subject, string $body): array
    {
        $errors = [];

        if (empty($bsn) === true) {
            $errors[] = 'BSN is verplicht voor berichten via Mijn Overheid';
        } elseif ($this->validateBsn($bsn) === false) {
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
    }

    private function getAdapter(): BerichtenboxAdapterInterface
    {
        // For MVP, always use mock adapter.
        return new MockAdapter($this->logger);
    }

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
    }
}
