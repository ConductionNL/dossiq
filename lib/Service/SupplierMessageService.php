<?php

/**
 * Procest Supplier Message Service
 *
 * Write-once per-case messaging between municipality + supplier.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class SupplierMessageService
{
    /**
     * Max attachments per message.
     */
    public const MAX_ATTACHMENTS = 5;

    /**
     * Max bytes per attachment (10MB).
     */
    public const MAX_ATTACHMENT_BYTES = 10_485_760;

    /**
     * Allowed attachment MIME prefixes / exact matches.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ATTACHMENT_MIME = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly TenantAuditTrailService $auditTrail,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate a single attachment.
     *
     * @param array{mime:string, bytes:int} $attachment Attachment.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateAttachment(array $attachment): void
    {
        $mime  = (string) ($attachment['mime'] ?? '');
        $bytes = (int) ($attachment['bytes'] ?? 0);
        if ($bytes > self::MAX_ATTACHMENT_BYTES) {
            throw new InvalidArgumentException('Attachment exceeds 10MB');
        }

        if (in_array($mime, self::ALLOWED_ATTACHMENT_MIME, true) === false) {
            throw new InvalidArgumentException('Unsupported attachment MIME: '.$mime);
        }
    }

    /**
     * Validate an attachment set (≤5 each ≤10MB).
     *
     * @param array<int, array{mime:string, bytes:int}> $attachments Attachments.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validateAttachmentSet(array $attachments): void
    {
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('Too many attachments (max 5)');
        }

        foreach ($attachments as $a) {
            $this->validateAttachment($a);
        }
    }

    /**
     * Persist an inbound message (supplier → municipality).
     *
     * @param string             $caseRef     Case ref.
     * @param string             $supplierRef Supplier ref.
     * @param string             $body        Body.
     * @param array<int, string> $attachmentRefs Attachment refs.
     * @param string             $sentBy      NC user id (or supplier email).
     *
     * @return array<string,mixed>|null Persisted message row.
     */
    public function sendMessage(string $caseRef, string $supplierRef, string $body, array $attachmentRefs, string $sentBy): ?array
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Message body required');
        }

        return $this->persist(direction: 'inbound', caseRef: $caseRef, supplierRef: $supplierRef, body: $body, attachmentRefs: $attachmentRefs, sentBy: $sentBy);
    }

    /**
     * Persist an outbound message (municipality → supplier).
     *
     * @param string             $caseRef     Case ref.
     * @param string             $supplierRef Supplier ref.
     * @param string             $body        Body.
     * @param array<int, string> $attachmentRefs Attachment refs.
     * @param string             $sentBy      NC user id.
     *
     * @return array<string,mixed>|null
     */
    public function addResponse(string $caseRef, string $supplierRef, string $body, array $attachmentRefs, string $sentBy): ?array
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Response body required');
        }

        return $this->persist(direction: 'outbound', caseRef: $caseRef, supplierRef: $supplierRef, body: $body, attachmentRefs: $attachmentRefs, sentBy: $sentBy);
    }

    /**
     * Retrieve the chronological conversation history for a case scoped by supplier.
     *
     * @param string $caseRef     Case ref.
     * @param string $supplierRef Supplier ref.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getConversationHistory(string $caseRef, string $supplierRef): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return [];
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'supplierMessage',
                limit: 200,
                offset: 0,
                filters: ['caseRef' => $caseRef, 'supplierRef' => $supplierRef]
            );
        } catch (Throwable $e) {
            return [];
        }

        $rows = is_array($rows) ? $rows : [];
        usort($rows, fn ($a, $b) => strcmp((string) ($a['sentAt'] ?? ''), (string) ($b['sentAt'] ?? '')));
        return $rows;
    }

    /**
     * @param string             $direction  inbound|outbound.
     * @param string             $caseRef    Case ref.
     * @param string             $supplierRef Supplier ref.
     * @param string             $body       Body.
     * @param array<int, string> $attachmentRefs Attachment refs.
     * @param string             $sentBy     Sender id.
     *
     * @return array<string,mixed>|null
     */
    private function persist(string $direction, string $caseRef, string $supplierRef, string $body, array $attachmentRefs, string $sentBy): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        $row = [
            'supplierRef'    => $supplierRef,
            'caseRef'        => $caseRef,
            'direction'      => $direction,
            'body'           => $body,
            'attachmentRefs' => array_values($attachmentRefs),
            'sentBy'         => $sentBy,
            'sentAt'         => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        try {
            $persisted = $os->saveObject(
                object: $row,
                register: TenantSaasService::REGISTER,
                schema: 'supplierMessage',
                uuid: null
            );
            $this->auditTrail->emit([
                'action'   => 'supplier_message.send',
                'actor'    => $sentBy,
                'resource' => 'case:'.$caseRef,
                'tenantId' => $supplierRef,
            ]);
            return $persisted;
        } catch (Throwable $e) {
            $this->logger->error('Procest: supplier message persist failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @return mixed|null
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }
}
