<?php

declare(strict_types=1);

namespace OCA\Procest\Service\BerichtenboxAdapter;

/**
 * Interface for Mijn Overheid Berichtenbox API adapters.
 */
interface BerichtenboxAdapterInterface
{
    /**
     * Send a message to the Berichtenbox.
     *
     * @param string      $bsn        Citizen BSN
     * @param string      $subject    Message subject
     * @param string      $body       Plain text message body
     * @param string      $typeCode   Bericht type code
     * @param string|null $attachment PDF attachment content (base64)
     *
     * @return array Result with messageId, status
     */
    public function sendMessage(
        string $bsn,
        string $subject,
        string $body,
        string $typeCode,
        ?string $attachment=null,
    ): array;

    /**
     * Get the read status of a sent message.
     *
     * @param string $messageId The external message ID
     *
     * @return array Status with read (bool), readAt (datetime|null)
     */
    public function getReadStatus(string $messageId): array;
}//end interface
