<?php

/**
 * AiService Suggestion-Time Audit Logging Completeness Unit Tests
 *
 * Asserts every AI operation exposed by AiController (classify, extract,
 * ask, summarize, suggestRouting, suggestNext) records an `aiAuditEntry`
 * at suggestion time — EU AI Act Article 14 log-retention posture.
 *
 * `callAiModel()` is the only outbound-network seam in AiService; it is
 * `protected` specifically so this suite can stub it via an anonymous
 * subclass instead of mocking curl. That keeps the assertion focused on
 * what matters here — "does the audit writer fire on this path" — without
 * coupling the test to the AI model wire format.
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
 * @spec openspec/changes/ai-oversight-log/tasks.md#4.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Ai\AiAuditLog;
use OCA\Procest\Service\Ai\AiEndpointGuard;
use OCA\Procest\Service\Ai\AiModelIdentity;
use OCA\Procest\Service\Ai\AiPiiRedactor;
use OCA\Procest\Service\Ai\AiPromptFactory;
use OCA\Procest\Service\AiService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-arg saveObject() signature used by
 * AiService::recordAuditEntry() (`register`, `schema`, `object`).
 */
interface AiServiceSaveObjectStub {

	/**
	 * Save an object.
	 *
	 * @param string $register The register id/slug.
	 * @param string $schema The schema id/slug.
	 * @param array $object The object payload.
	 *
	 * @return mixed
	 */
	public function saveObject(string $register, string $schema, array $object): mixed;
}//end interface

/**
 * Test-only AiService subclass that stubs the outbound AI model call.
 */
class StubbedAiService extends AiService {

	/**
	 * Canned response returned in place of a real AI model call.
	 *
	 * @var array
	 */
	public array $stubbedResult = ['confidence' => 0.8, 'answer' => 'ok', 'summary' => 'ok'];

	/**
	 * @inheritDoc
	 */
	protected function callAiModel(string $prompt): array {
		return $this->stubbedResult;
	}//end callAiModel()
}//end class

/**
 * Unit tests proving suggestion-time audit logging completeness.
 *
 * @covers \OCA\Procest\Service\AiService
 *
 * @uses \OCA\Procest\Service\Ai\AiAuditLog
 * @uses \OCA\Procest\Service\Ai\AiEndpointGuard
 * @uses \OCA\Procest\Service\Ai\AiModelIdentity
 * @uses \OCA\Procest\Service\Ai\AiPromptFactory
 */
class AiServiceAuditLoggingCompletenessTest extends TestCase {

	/**
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface $container;

	private StubbedAiService $service;

	/**
	 * @var AiServiceSaveObjectStub|MockObject
	 */
	private $objectService;

	/**
	 * Set up test fixtures — AI globally + per-feature enabled, register and
	 * schema configured, saveObject capturing every call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default) {
				if ($key === 'ai_enabled') {
					return '1';
				}

				if (str_starts_with($key, 'ai_feature_') === true) {
					return '1';
				}

				if ($key === 'register') {
					return 'procest';
				}

				if ($key === 'ai_audit_entry_schema') {
					return 'aiAuditEntry';
				}

				if ($key === 'ai_pii_stripping') {
					return '0';
				}

				return $default;
			});

		$this->objectService = $this->createMock(AiServiceSaveObjectStub::class);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->objectService);

		$logger = $this->createMock(LoggerInterface::class);

		// Real collaborators, mocked boundaries: the audit sink still reaches
		// the same ObjectService stub, so this suite keeps asserting that every
		// suggestion-time operation actually WRITES an audit entry rather than
		// that AiService merely calls a mock.
		$this->service = new StubbedAiService(
			appConfig: $this->appConfig,
			prompts: new AiPromptFactory(),
			pii: new AiPiiRedactor(),
			endpointGuard: new AiEndpointGuard($logger),
			audit: new AiAuditLog($this->appConfig, $this->container, $logger),
			modelIdentity: new AiModelIdentity($this->appConfig),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * classifyDocument() records a suggestion-time audit entry.
	 *
	 * @return void
	 */
	public function testClassifyDocumentLogsAuditEntry(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $entry) => $entry['type'] === 'classification'
					&& $entry['action'] === 'suggestion'
					&& $entry['userId'] === 'user-1')
			);

		$result = $this->service->classifyDocument('case-a', 'doc-1', 'user-1');
		$this->assertTrue($result['success']);
	}//end testClassifyDocumentLogsAuditEntry()

	/**
	 * extractData() records a suggestion-time audit entry.
	 *
	 * @return void
	 */
	public function testExtractDataLogsAuditEntry(): void {
		$this->service->stubbedResult = ['averageConfidence' => 0.7, 'fields' => []];

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $entry) => $entry['type'] === 'extraction'
					&& $entry['action'] === 'suggestion')
			);

		$result = $this->service->extractData('case-a', 'doc-1', 'user-1');
		$this->assertTrue($result['success']);
	}//end testExtractDataLogsAuditEntry()

	/**
	 * askQuestion() records a suggestion-time audit entry.
	 *
	 * @return void
	 */
	public function testAskQuestionLogsAuditEntry(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $entry) => $entry['type'] === 'qa'
					&& $entry['action'] === 'suggestion')
			);

		$result = $this->service->askQuestion('case-a', 'Is dit compleet?', 'user-1');
		$this->assertTrue($result['success']);
	}//end testAskQuestionLogsAuditEntry()

	/**
	 * summarize() records a suggestion-time audit entry.
	 *
	 * @return void
	 */
	public function testSummarizeLogsAuditEntry(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $entry) => $entry['type'] === 'summary'
					&& $entry['action'] === 'suggestion')
			);

		$result = $this->service->summarize('case-a', 'case', null, 'user-1');
		$this->assertTrue($result['success']);
	}//end testSummarizeLogsAuditEntry()

	/**
	 * suggestRouting() records a suggestion-time audit entry.
	 *
	 * @return void
	 */
	public function testSuggestRoutingLogsAuditEntry(): void {
		$this->service->stubbedResult = ['confidence' => 0.6, 'suggestions' => []];

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $entry) => $entry['type'] === 'routing'
					&& $entry['action'] === 'suggestion')
			);

		$result = $this->service->suggestRouting('case-a', 'user-1');
		$this->assertTrue($result['success']);
	}//end testSuggestRoutingLogsAuditEntry()

	/**
	 * suggestNextStep() records a suggestion-time audit entry.
	 *
	 * @return void
	 */
	public function testSuggestNextStepLogsAuditEntry(): void {
		$this->service->stubbedResult = ['suggestions' => []];

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $entry) => $entry['type'] === 'decision_support'
					&& $entry['action'] === 'suggestion')
			);

		$result = $this->service->suggestNextStep('case-a', 'user-1');
		$this->assertTrue($result['success']);
	}//end testSuggestNextStepLogsAuditEntry()
}//end class
