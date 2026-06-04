<?php

/**
 * Procest Zaakportaal Message Service
 *
 * Read/write of citizen <-> handler messages (PortaalBericht) for the Mijn
 * gemeente portal. Messages persist via the OpenRegister ObjectService against
 * the portaalBericht schema. Reads are scoped to the authenticated subject
 * reference so a citizen can only ever see their own thread (IDOR-safe); the
 * sender identity is taken from the authenticated session, never from the
 * request body.
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakportaal
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-06
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Sends and lists citizen portal messages.
 *
 * @psalm-suppress UnusedClass
 */
class PortalMessageService
{
    /**
     * Maximum message body length.
     *
     * @var int
     */
    public const MAX_CONTENT_LENGTH = 5000;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build and validate a PortaalBericht payload from request data.
     *
     * The sender reference is supplied by the controller from the session and
     * is never read from the request body.
     *
     * @param array<string, mixed> $data       The request data.
     * @param string               $subjectRef The authenticated sender reference.
     *
     * @return array<string, mixed> The validated payload.
     *
     * @throws OCSBadRequestException When validation fails.
     */
    public function buildPayload(array $data, string $subjectRef): array
    {
        $caseId  = trim((string) ($data['caseId'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));

        if ($caseId === '') {
            throw new OCSBadRequestException('caseId is verplicht');
        }

        if ($content === '') {
            throw new OCSBadRequestException('Bericht mag niet leeg zijn');
        }

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new OCSBadRequestException('Bericht is te lang');
        }

        $senderType = (string) ($data['senderType'] ?? 'burger');
        if (in_array($senderType, ['burger', 'bedrijf', 'gemachtigde'], true) === false) {
            $senderType = 'burger';
        }

        $attachments = [];
        if (isset($data['attachments']) === true && is_array($data['attachments']) === true) {
            $attachments = array_values(array_map('strval', $data['attachments']));
        }

        return [
            'caseId'        => $caseId,
            'caseReference' => trim((string) ($data['caseReference'] ?? '')),
            'senderType'    => $senderType,
            'senderRef'     => $subjectRef,
            'senderName'    => trim((string) ($data['senderName'] ?? '')),
            'subject'       => trim((string) ($data['subject'] ?? 'Vraag')),
            'content'       => $content,
            'attachments'   => $attachments,
            'direction'     => 'citizen_to_handler',
            'sentAt'        => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM),
        ];
    }//end buildPayload()

    /**
     * Send a message: persist a PortaalBericht for the subject.
     *
     * @param array<string, mixed> $data       The request data.
     * @param string               $subjectRef The authenticated sender reference.
     *
     * @return array<string, mixed> The saved message.
     *
     * @throws OCSBadRequestException When validation or storage fails.
     */
    public function send(array $data, string $subjectRef): array
    {
        $payload = $this->buildPayload(data: $data, subjectRef: $subjectRef);

        [$objectService, $register, $schema] = $this->resolve();

        $saved = $objectService->saveObject($register, $schema, $payload);

        $this->logger->info('Zaakportaal: message sent', ['caseId' => $payload['caseId']]);

        return $this->toArray(value: $saved);
    }//end send()

    /**
     * List the message thread for a case, scoped to the subject.
     *
     * @param string $caseId     The case id.
     * @param string $subjectRef The authenticated subject reference.
     *
     * @return array<int, array<string, mixed>> The messages.
     *
     * @throws OCSBadRequestException When storage is unavailable.
     */
    public function threadForSubject(string $caseId, string $subjectRef): array
    {
        if (trim($caseId) === '') {
            throw new OCSBadRequestException('caseId is verplicht');
        }

        [$objectService, $register, $schema] = $this->resolve();

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register'  => (int) $register,
                        'schema'    => (int) $schema,
                        'caseId'    => $caseId,
                        'senderRef' => $subjectRef,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Zaakportaal: message thread failed', ['error' => $e->getMessage()]);
            throw new OCSBadRequestException('Could not retrieve messages');
        }

        return array_map([$this, 'toArray'], $results);
    }//end threadForSubject()

    /**
     * Resolve the ObjectService and register/schema identifiers.
     *
     * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
     *
     * @throws OCSBadRequestException When OpenRegister is unavailable.
     */
    private function resolve(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new OCSBadRequestException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('portaal_bericht_schema');

        if ($register === '' || $schema === '') {
            throw new OCSBadRequestException('Portaal bericht schema is not configured');
        }

        return [$objectService, $register, $schema];
    }//end resolve()

    /**
     * Normalise an ObjectService result to a plain array.
     *
     * @param mixed $value The value to normalise.
     *
     * @return array<string, mixed> The normalised array.
     */
    private function toArray(mixed $value): array
    {
        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($value) === true) {
            return $value;
        }

        return [];
    }//end toArray()
}//end class
