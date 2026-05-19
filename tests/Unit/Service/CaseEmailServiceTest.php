<?php

/**
 * CaseEmailService Unit Tests
 *
 * Tests for the Procest CaseEmailService email handling logic.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseEmailService.
 *
 * @covers \OCA\Procest\Service\CaseEmailService
 */
class CaseEmailServiceTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked NC mailer.
     *
     * @var IMailer|\PHPUnit\Framework\MockObject\MockObject
     */
    private IMailer $mailer;

    /**
     * The mocked app config.
     *
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var CaseEmailService
     */
    private CaseEmailService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->mailer          = $this->createMock(IMailer::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new CaseEmailService(
            $this->settingsService,
            $this->mailer,
            $this->appConfig,
            $this->logger,
        );

    }//end setUp()

    /**
     * Test that extractCaseNumber returns null when the subject has no match.
     *
     * @return void
     */
    public function testExtractCaseNumberReturnsNullForUnmatchedSubject(): void
    {
        $result = $this->service->extractCaseNumber(subject: 'No case number here');
        $this->assertNull($result);

    }//end testExtractCaseNumberReturnsNullForUnmatchedSubject()

    /**
     * Test that extractCaseNumber returns the bracketed identifier from a subject.
     *
     * @return void
     */
    public function testExtractCaseNumberReturnsIdentifierFromSubject(): void
    {
        $result = $this->service->extractCaseNumber(subject: '[ZAAK-2026-000042] Test subject');
        $this->assertSame('ZAAK-2026-000042', $result);

    }//end testExtractCaseNumberReturnsIdentifierFromSubject()

    /**
     * Test that resolveTemplateVariables replaces all known placeholders.
     *
     * @return void
     */
    public function testResolveTemplateVariablesReplacesKnownVariables(): void
    {
        $data   = ['naam' => 'Jan de Vries', 'zaakNummer' => 'ZAAK-2026-000001'];
        $result = $this->service->resolveTemplateVariables(
            template: 'Beste {{naam}}, uw zaak {{zaakNummer}}',
            data: $data,
        );
        $this->assertSame('Beste Jan de Vries, uw zaak ZAAK-2026-000001', $result);

    }//end testResolveTemplateVariablesReplacesKnownVariables()

    /**
     * Test that resolveTemplateVariables leaves unknown placeholders intact.
     *
     * @return void
     */
    public function testResolveTemplateVariablesLeavesUnknownVariablesAsIs(): void
    {
        $data   = ['naam' => 'Jan'];
        $result = $this->service->resolveTemplateVariables(
            template: 'Beste {{naam}}, ref {{unknown}}',
            data: $data,
        );
        $this->assertSame('Beste Jan, ref {{unknown}}', $result);

    }//end testResolveTemplateVariablesLeavesUnknownVariablesAsIs()

    /**
     * Test that findUnresolvedVariables returns only the names without data.
     *
     * @return void
     */
    public function testFindUnresolvedVariablesReturnsUnresolvedNames(): void
    {
        $data       = ['naam' => 'Jan'];
        $unresolved = $this->service->findUnresolvedVariables(
            template: '{{naam}} {{deadline}}',
            data: $data,
        );
        $this->assertContains('deadline', $unresolved);
        $this->assertNotContains('naam', $unresolved);

    }//end testFindUnresolvedVariablesReturnsUnresolvedNames()

    /**
     * Test that generateMessageId returns a non-empty RFC 2822-bracketed string.
     *
     * @return void
     */
    public function testGenerateMessageIdReturnsNonEmptyString(): void
    {
        $id = $this->service->generateMessageId();
        $this->assertNotEmpty($id);
        $this->assertStringStartsWith('<', $id);
        $this->assertStringEndsWith('>', $id);

    }//end testGenerateMessageIdReturnsNonEmptyString()

    /**
     * Test that generateMessageId returns unique values on successive calls.
     *
     * @return void
     */
    public function testGenerateMessageIdIsUnique(): void
    {
        $id1 = $this->service->generateMessageId();
        $id2 = $this->service->generateMessageId();
        $this->assertNotSame($id1, $id2);

    }//end testGenerateMessageIdIsUnique()

    /**
     * Test that processInboundEmail returns 'duplicate' when message already stored.
     *
     * @return void
     */
    public function testProcessInboundEmailReturnsDuplicateWhenAlreadyStored(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', 'procest'],
                ['email_message_schema', '', 'emailMessage'],
                ['register', 'procest'],
                ['email_message_schema', 'emailMessage'],
            ]
        );

        // Simulate a duplicate: findObjects returns one result.
        $objectService->method('findObjects')->willReturn([['messageId' => '<dup@host>']]);

        $result = $this->service->processInboundEmail(
            messageId: '<dup@host>',
            from: 'sender@example.nl',
            to: 'inbox@example.nl',
            subject: 'Test',
            body: 'body',
        );

        $this->assertFalse($result['linked']);
        $this->assertSame('duplicate', $result['reason']);

    }//end testProcessInboundEmailReturnsDuplicateWhenAlreadyStored()

    /**
     * Test that processInboundEmail routes to unlinked when no case matches.
     *
     * @return void
     */
    public function testProcessInboundEmailQueuesUnlinkedWhenNoCaseFound(): void
    {
        $objectService = $this->createObjectServiceMock();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('somevalue');

        // findObjects: first call (duplicate check) returns empty; second call (case lookup) returns empty.
        $objectService->method('findObjects')->willReturn([]);
        $objectService->method('findObject')->willReturn(null);
        $objectService->method('saveObject')->willReturn([]);

        $result = $this->service->processInboundEmail(
            messageId: '<unique-msg@host>',
            from: 'sender@example.nl',
            to: 'inbox@example.nl',
            subject: 'No matching case here',
            body: 'body',
        );

        $this->assertFalse($result['linked']);
        $this->assertSame('unlinked', $result['method']);

    }//end testProcessInboundEmailQueuesUnlinkedWhenNoCaseFound()

    /**
     * Test that schedulePdfConversion skips processing for an empty message ID.
     *
     * @return void
     */
    public function testSchedulePdfConversionSkipsEmptyMessageId(): void
    {
        // No objectService call expected when messageId is empty.
        $this->settingsService->expects($this->never())->method('getObjectService');
        $this->service->schedulePdfConversion(messageId: '');

    }//end testSchedulePdfConversionSkipsEmptyMessageId()

    /**
     * Test that schedulePdfConversion skips when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testSchedulePdfConversionSkipsWhenObjectServiceUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        // Should return without error.
        $this->service->schedulePdfConversion(messageId: 'some-uuid');
        $this->assertTrue(true);

    }//end testSchedulePdfConversionSkipsWhenObjectServiceUnavailable()

    /**
     * Test that listEmailsForCase returns empty structure when OpenRegister unavailable.
     *
     * @return void
     */
    public function testListEmailsForCaseReturnsEmptyWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->listEmailsForCase(caseId: 'case-uuid');

        $this->assertSame(['threads' => [], 'total' => 0], $result);

    }//end testListEmailsForCaseReturnsEmptyWhenOpenRegisterUnavailable()

    /**
     * Test that listUnlinkedEmails returns empty structure when OpenRegister unavailable.
     *
     * @return void
     */
    public function testListUnlinkedEmailsReturnsEmptyWhenOpenRegisterUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->listUnlinkedEmails();

        $this->assertSame(['results' => [], 'total' => 0], $result);

    }//end testListUnlinkedEmailsReturnsEmptyWhenOpenRegisterUnavailable()

    /**
     * Build a minimal object-service mock with no-op save and find methods.
     *
     * @return object
     */
    private function createObjectServiceMock(): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'findObject', 'saveObject'])
            ->getMock();
        // phpcs:disable CustomSn.Functions.NamedParameters
        $mock->method('saveObject')->willReturn([]);
        $mock->method('findObject')->willReturn(null);
        // phpcs:enable CustomSn.Functions.NamedParameters
        return $mock;

    }//end createObjectServiceMock()
}//end class
