<?php

/**
 * SupplierMessageService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\SupplierMessageService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenantAuditTrailService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\SupplierMessageService
 */
class SupplierMessageServiceTest extends TestCase
{
    private SupplierMessageService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $audit = new TenantAuditTrailService($this->createMock(LoggerInterface::class));
        $this->svc = new SupplierMessageService(
            scopeService: $this->createMock(SupplierScopeService::class),
            auditTrail: $audit,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testValidateAttachmentAcceptsAllowed(): void
    {
        $this->svc->validateAttachment(['mime' => 'application/pdf', 'bytes' => 1024]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateAttachmentRejectsBadMime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->validateAttachment(['mime' => 'application/x-msdownload', 'bytes' => 1024]);
    }

    public function testValidateAttachmentRejectsTooBig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->validateAttachment(['mime' => 'application/pdf', 'bytes' => SupplierMessageService::MAX_ATTACHMENT_BYTES + 1]);
    }

    public function testValidateAttachmentSetRejectsTooMany(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $set = array_fill(0, 6, ['mime' => 'application/pdf', 'bytes' => 1024]);
        $this->svc->validateAttachmentSet($set);
    }

    public function testValidateAttachmentSetAcceptsFive(): void
    {
        $set = array_fill(0, 5, ['mime' => 'application/pdf', 'bytes' => 1024]);
        $this->svc->validateAttachmentSet($set);
        $this->expectNotToPerformAssertions();
    }

    public function testSendMessageRejectsEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->sendMessage('c-1', 's-1', '   ', [], 'alice');
    }

    public function testAddResponseRejectsEmptyBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->addResponse('c-1', 's-1', '', [], 'alice');
    }

    public function testGetConversationHistoryReturnsEmptyWhenOrUnavailable(): void
    {
        $this->assertSame([], $this->svc->getConversationHistory('c-1', 's-1'));
    }
}
