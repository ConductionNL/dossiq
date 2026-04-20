<?php

declare(strict_types=1);

namespace OCA\Procest\Service\BerichtenboxAdapter;

use Psr\Log\LoggerInterface;

/**
 * Mock Berichtenbox adapter for development and testing.
 *
 * Simulates message sending and read status without external API calls.
 */
class MockAdapter implements BerichtenboxAdapterInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    public function sendMessage(
        string $bsn,
        string $subject,
        string $body,
        string $typeCode,
        ?string $attachment=null,
    ): array {
        $messageId = 'mock-'.bin2hex(random_bytes(8));

        $this->logger->info(
                'MockBerichtenbox: Message sent',
                [
                    'messageId'     => $messageId,
                    'bsn'           => substr($bsn, 0, 4).'*****',
                    'subject'       => $subject,
                    'typeCode'      => $typeCode,
                    'hasAttachment' => $attachment !== null,
                ]
                );

        return [
            'messageId' => $messageId,
            'status'    => 'sent',
            'sentAt'    => (new \DateTime())->format('c'),
        ];
    }//end sendMessage()

    public function getReadStatus(string $messageId): array
    {
        // Simulate: messages are "read" after they've existed for a while.
        return [
            'read'   => true,
            'readAt' => (new \DateTime('-1 hour'))->format('c'),
        ];
    }//end getReadStatus()
}//end class
