<?php

/**
 * CaseAssistantService Unit Tests.
 *
 * Exercises validation (400s), fail-closed case loading (404 on missing OR /
 * unknown / unreadable case — never distinguished), the bounded case-context
 * summary sent to Hermiq, per-(user,case) session continuity via IConfig, and
 * audit recording through the existing AiService sink.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Assistant;

use Exception;
use OCA\Procest\Service\AiService;
use OCA\Procest\Service\Assistant\CaseAssistantService;
use OCA\Procest\Service\Assistant\HermiqAssistantClient;
use OCA\Procest\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal hand-written ObjectService double with a REAL `find(string $id,
 * string $register, string $schema)` signature.
 *
 * PHPUnit's `addMethods()`-generated mock methods do not support named-
 * argument calls (`find(id: ..., register: ..., schema: ...)`, which is how
 * every OpenRegister ObjectService call in this codebase is written) — a
 * mocking-tool limitation, not a real-code bug. A tiny hand-written double
 * with a concrete signature sidesteps it entirely.
 */
class FakeCaseObjectService
{
    /**
     * @param mixed          $returnValue The value `find()` returns, or...
     * @param \Throwable|null $throws     ...an exception `find()` throws instead.
     */
    public function __construct(
        private readonly mixed $returnValue=null,
        private readonly ?\Throwable $throws=null,
    ) {
    }

    /**
     * @param string $id       Object id.
     * @param string $register Register slug.
     * @param string $schema   Schema slug.
     *
     * @return mixed
     */
    public function find(string $id, string $register, string $schema): mixed
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->returnValue;
    }
}

/**
 * @covers \OCA\Procest\Service\Assistant\CaseAssistantService
 */
class CaseAssistantServiceTest extends TestCase
{
    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mock HermiqAssistantClient.
     *
     * @var HermiqAssistantClient&MockObject
     */
    private HermiqAssistantClient $hermiqClient;

    /**
     * Mock AiService.
     *
     * @var AiService&MockObject
     */
    private AiService $aiService;

    /**
     * Mock IConfig.
     *
     * @var IConfig&MockObject
     */
    private IConfig $config;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => match ($key) {
                'register'    => 'procest',
                'case_schema' => 'case',
                default       => '',
            }
        );
        $this->hermiqClient = $this->createMock(HermiqAssistantClient::class);
        $this->aiService     = $this->createMock(AiService::class);
        $this->config        = $this->createMock(IConfig::class);
    }//end setUp()

    /**
     * Build the service wired to the current mocks.
     *
     * @return CaseAssistantService
     */
    private function service(): CaseAssistantService
    {
        return new CaseAssistantService(
            $this->settingsService,
            $this->hermiqClient,
            $this->aiService,
            $this->config,
            $this->createMock(LoggerInterface::class)
        );
    }//end service()

    /**
     * An empty message is rejected before any collaborator is touched.
     *
     * @return void
     */
    public function testEmptyMessageIsRejected(): void
    {
        $this->settingsService->expects($this->never())->method('getObjectService');

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service()->converse(userId: 'alice', caseId: 'case-1', message: '   ');
    }//end testEmptyMessageIsRejected()

    /**
     * A message over the length cap is rejected.
     *
     * @return void
     */
    public function testOversizedMessageIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service()->converse(userId: 'alice', caseId: 'case-1', message: str_repeat('a', 4001));
    }//end testOversizedMessageIsRejected()

    /**
     * OpenRegister being unavailable fails closed to 404 — never a raw 500,
     * and never distinguished from "case not found".
     *
     * @return void
     */
    public function testOpenRegisterUnavailableReturns404(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);
        $this->hermiqClient->expects($this->never())->method('converse');

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service()->converse(userId: 'alice', caseId: 'case-1', message: 'hello');
    }//end testOpenRegisterUnavailableReturns404()

    /**
     * An unknown/unreadable case (ObjectService returns null — the SAME
     * shape a permission-denied read returns, per ObjectService
     * multitenancy) fails closed to 404 without ever calling Hermiq.
     *
     * @return void
     */
    public function testUnreadableCaseReturns404WithoutCallingHermiq(): void
    {
        $objectService = new FakeCaseObjectService(returnValue: null);
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->hermiqClient->expects($this->never())->method('converse');

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service()->converse(userId: 'alice', caseId: 'case-1', message: 'hello');
    }//end testUnreadableCaseReturns404WithoutCallingHermiq()

    /**
     * A case find() that throws also fails closed to 404 (never a raw 500).
     *
     * @return void
     */
    public function testCaseLoadThrowingReturns404(): void
    {
        $objectService = new FakeCaseObjectService(throws: new \RuntimeException('db down'));
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->hermiqClient->expects($this->never())->method('converse');

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service()->converse(userId: 'alice', caseId: 'case-1', message: 'hello');
    }//end testCaseLoadThrowingReturns404()

    /**
     * Happy path: builds a bounded summary from only the safe fields, sends
     * the prior Hermiq session for continuity, persists the new session, and
     * records an audit entry via AiService.
     *
     * @return void
     */
    public function testHappyPathBuildsSummarySendsSessionAndRecordsAudit(): void
    {
        $caseObject = $this->getMockBuilder(\stdClass::class)->addMethods(['jsonSerialize'])->getMock();
        $caseObject->method('jsonSerialize')->willReturn([
            'title'           => 'Vergunning kappen boom',
            'identifier'      => '2026-0042',
            'description'     => 'Some description',
            'caseType'        => 'omgevingsvergunning',
            'status'          => 'status-uuid',
            'confidentiality' => 'openbaar',
            'startDate'       => '2026-01-01',
            'deadline'        => '2026-03-01',
            'isFinalStatus'   => false,
            // Deliberately NOT in the summary — must never reach Hermiq.
            'initiatorDisplayName' => 'Jan Jansen',
            'documents'            => [['id' => 'doc-1', 'content' => 'secret']],
        ]);

        $objectService = new FakeCaseObjectService(returnValue: $caseObject);
        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->config->method('getUserValue')->with('alice', 'procest', 'assistant_session_case-1', '')
            ->willReturn('prior-session');

        $capturedContext = null;
        $this->hermiqClient->method('converse')
            ->with(
                $this->equalTo('prior-session'),
                $this->equalTo('What is the status?'),
                $this->callback(function (array $context) use (&$capturedContext) {
                    $capturedContext = $context;
                    return true;
                })
            )
            ->willReturn(['sessionId' => 'new-session', 'reply' => 'It is in review.', 'usage' => ['promptTokens' => 5]]);

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'procest', 'assistant_session_case-1', 'new-session');

        $this->aiService->expects($this->once())
            ->method('recordAssistantAuditEntry')
            ->with($this->callback(function (array $entry) {
                return $entry['type'] === 'assistant'
                    && $entry['caseId'] === 'case-1'
                    && $entry['userId'] === 'alice'
                    && $entry['model'] === 'hermiq';
            }));

        $result = $this->service()->converse(userId: 'alice', caseId: 'case-1', message: 'What is the status?');

        $this->assertSame('It is in review.', $result['reply']);
        $this->assertSame(['promptTokens' => 5], $result['usage']);

        $summary = $capturedContext['contextData'];
        $this->assertSame('Vergunning kappen boom', $summary['title']);
        $this->assertSame('2026-0042', $summary['identifier']);
        $this->assertArrayNotHasKey('initiatorDisplayName', $summary);
        $this->assertArrayNotHasKey('documents', $summary);
        $this->assertSame('procest', $capturedContext['app']);
        $this->assertSame('case', $capturedContext['objectType']);
        $this->assertSame('case-1', $capturedContext['objectRef']);
    }//end testHappyPathBuildsSummarySendsSessionAndRecordsAudit()

    /**
     * A long description is truncated in the summary sent to Hermiq.
     *
     * @return void
     */
    public function testLongDescriptionIsTruncated(): void
    {
        $caseObject = $this->getMockBuilder(\stdClass::class)->addMethods(['jsonSerialize'])->getMock();
        $caseObject->method('jsonSerialize')->willReturn([
            'title'       => 'Case',
            'description' => str_repeat('x', 1000),
        ]);

        $objectService = new FakeCaseObjectService(returnValue: $caseObject);
        $this->settingsService->method('getObjectService')->willReturn($objectService);

        $this->config->method('getUserValue')->willReturn('');

        $capturedContext = null;
        $this->hermiqClient->method('converse')
            ->with($this->anything(), $this->anything(), $this->callback(function (array $context) use (&$capturedContext) {
                $capturedContext = $context;
                return true;
            }))
            ->willReturn(['sessionId' => 'new-session', 'reply' => 'ok', 'usage' => []]);

        $this->service()->converse(userId: 'alice', caseId: 'case-1', message: 'hi');

        // 500 chars + the 3-byte UTF-8 ellipsis ('…' = U+2026).
        $this->assertLessThanOrEqual(503, strlen($capturedContext['contextData']['description']));
        $this->assertStringEndsWith('…', $capturedContext['contextData']['description']);
    }//end testLongDescriptionIsTruncated()
}//end class
