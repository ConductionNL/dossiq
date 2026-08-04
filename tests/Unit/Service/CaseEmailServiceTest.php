<?php

/**
 * CaseEmailService Security Unit Tests
 *
 * Tests for C4/H6/L1 security fixes in CaseEmailService.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\Email\CaseContactDirectory;
use OCA\Procest\Service\Email\CaseEmailAttachmentResolver;
use OCA\Procest\Service\Email\CaseEmailRepository;
use OCA\Procest\Service\SettingsService;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Security-focused unit tests for CaseEmailService.
 *
 * Covers C4 (IDOR + file-disclosure), H6 (XSS + reserved-domain), L1 (log-injection).
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
     * The mocked mailer.
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
     * The mocked root folder.
     *
     * @var IRootFolder|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRootFolder $rootFolder;

    /**
     * The mocked user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

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
        $this->rootFolder      = $this->createMock(IRootFolder::class);
        $this->userSession     = $this->createMock(IUserSession::class);

        // The repository and contact directory are real collaborators, not mocks:
        // every assertion below is about behaviour they inherited verbatim from
        // CaseEmailService, and the repository is still driven entirely by the
        // mocked SettingsService (getObjectService() === null ⇒ no case data).
        $this->service = new CaseEmailService(
            $this->mailer,
            $this->appConfig,
            $this->logger,
            new CaseEmailRepository($this->settingsService),
            new CaseContactDirectory(),
            new CaseEmailAttachmentResolver($this->rootFolder, $this->userSession, $this->logger),
        );

    }//end setUp()

    /**
     * H6: sendEmail throws when from-address is empty.
     *
     * @return void
     */
    public function testSendEmailThrowsWhenFromAddressEmpty(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $default='') {
                    if ($key === 'email_from_address') {
                        return '';
                    }

                    return $default;
                }
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/geconfigureerd/i');

        $this->service->sendEmail('case-uuid', 'to@example.com', 'Subject', 'Body');

    }//end testSendEmailThrowsWhenFromAddressEmpty()

    /**
     * H6: sendEmail throws when from-address is the reserved example.nl domain.
     *
     * @return void
     */
    public function testSendEmailThrowsWhenFromAddressIsReservedDomain(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $default='') {
                    if ($key === 'email_from_address') {
                        return 'noreply@example.nl';
                    }

                    return $default;
                }
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/geconfigureerd/i');

        $this->service->sendEmail('case-uuid', 'to@example.com', 'Subject', 'Body');

    }//end testSendEmailThrowsWhenFromAddressIsReservedDomain()

    /**
     * C4 IDOR: sendEmail throws when case is not found (access denied).
     *
     * @return void
     */
    public function testSendEmailThrowsWhenCaseNotFound(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $default='') {
                    if ($key === 'email_from_address') {
                        return 'real@municipality.nl';
                    }

                    return $default;
                }
            );

        // getObjectService returns null → loadCaseData returns [] → IDOR check fires.
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Zaak niet gevonden/i');

        $this->service->sendEmail('nonexistent-case', 'to@example.com', 'Subject', 'Body');

    }//end testSendEmailThrowsWhenCaseNotFound()

    /**
     * H6 XSS: resolveVariables escapes HTML characters by default.
     *
     * @return void
     */
    public function testResolveVariablesEscapesHtml(): void
    {
        $template = 'Beste {{naam}}, uw zaak: {{omschrijving}}';
        $data     = [
            'naam'         => 'Jan <script>alert(1)</script>',
            'omschrijving' => '<img src=x onerror="steal()">',
        ];

        $result = $this->service->resolveVariables($template, $data);

        $this->assertStringContainsString('Jan &lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;img', $result);
        $this->assertStringNotContainsString('<img', $result);

    }//end testResolveVariablesEscapesHtml()

    /**
     * H6 XSS: resolveVariablesRaw passes through raw values.
     *
     * @return void
     */
    public function testResolveVariablesPlaintextContextSkipsEscape(): void
    {
        $template = 'Beste {{naam}}';
        $data     = ['naam' => 'Jan & Piet'];

        $result = $this->service->resolveVariablesRaw($template, $data);

        $this->assertSame('Beste Jan & Piet', $result);

    }//end testResolveVariablesPlaintextContextSkipsEscape()

    /**
     * H6 XSS: resolveVariables leaves unresolved variables unchanged.
     *
     * @return void
     */
    public function testResolveVariablesLeavesUnresolvedUnchanged(): void
    {
        $template = 'Zaak {{nummer}} van {{naam}}';
        $data     = ['naam' => 'Henk'];

        $result = $this->service->resolveVariables($template, $data);

        $this->assertStringContainsString('{{nummer}}', $result);
        $this->assertStringContainsString('Henk', $result);

    }//end testResolveVariablesLeavesUnresolvedUnchanged()
}//end class
