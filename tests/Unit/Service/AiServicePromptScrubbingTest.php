<?php

/**
 * PII scrubbing coverage unit tests.
 *
 * Asserts that EVERY prompt reaching the one outbound-network seam has been
 * through {@see AiPiiRedactor}, and that the audit trail records the scrubbed
 * text rather than the raw input.
 *
 * WHAT ACTUALLY LEAVES THE INSTANCE, because it is narrower than "AI sends case
 * data to a model" suggests and the difference matters. Read
 * `lib/Service/Ai/AiPromptFactory.php`: five of the six prompts interpolate
 * IDENTIFIERS only — a case UUID, a document UUID — into fixed English
 * boilerplate, and `AiService::callAiModel()` posts that string and nothing
 * else. No document content and no case content is fetched or transmitted by
 * any of them. `question()` is the single exception: it interpolates
 * `$question`, free prose a case worker typed, which is where a BSN, an IBAN or
 * an address can appear.
 *
 * So the coverage gap was not "3 of 6 operations unprotected". It was worse than
 * that framing and narrower: stripping ran on exactly the three prompts that
 * cannot contain personal data, and was skipped on the one that can. It was
 * also being applied to the wrong side of the Q&A operation — the audit entry
 * recorded `$question` verbatim, so the unscrubbed text was written into
 * OpenRegister even when stripping was switched on.
 *
 * `testEveryOperationScrubs()` is a LOOP over all six on purpose. Naming three
 * of them would leave the next operation someone adds uncovered, which is how
 * `askQuestion` came to be the odd one out in the first place.
 *
 * TRUE-POSITIVE CONTROL, run rather than predicted — see the assertions listed
 * against each mutation in the PR body.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use OCA\Dossiq\Service\Ai\AiModelIdentity;
use OCA\Dossiq\Service\Ai\AiPiiRedactor;
use OCA\Dossiq\Service\Ai\AiPromptFactory;
use OCA\Dossiq\Service\AiService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-arg `saveObject()` signature the audit
 * writer calls.
 *
 * Declared here rather than reused from `AiServiceAuditLoggingCompletenessTest`
 * on purpose: a test file that depends on a type declared in a SIBLING test file
 * passes in a full-suite run and errors when the file is run on its own, which
 * makes the failure look like a broken test rather than a load-order accident.
 */
interface ScrubbingSaveObjectStub {

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
 * AiService subclass that CAPTURES the prompt handed to the network seam.
 *
 * The seam is `protected` precisely so a test can stand here and read what
 * would have gone out on the wire, without mocking curl.
 */
class PromptCapturingAiService extends AiService {

	/**
	 * Every prompt handed to the model, in call order.
	 *
	 * @var string[]
	 */
	public array $seenPrompts = [];

	/**
	 * Canned model response.
	 *
	 * @var array
	 */
	public array $stubbedResult = [
		'confidence' => 0.8,
		'answer' => 'ok',
		'summary' => 'ok',
		'fields' => [],
		'suggestions' => [],
		'averageConfidence' => 0.5,
	];

	/**
	 * @inheritDoc
	 */
	protected function callAiModel(string $prompt): array {
		$this->seenPrompts[] = $prompt;
		return $this->stubbedResult;
	}//end callAiModel()
}//end class

/**
 * Every prompt is scrubbed before it leaves, and the trail records the scrubbed
 * text.
 *
 * @covers \OCA\Dossiq\Service\AiService
 *
 * @uses \OCA\Dossiq\Service\Ai\AiAuditLog
 * @uses \OCA\Dossiq\Service\Ai\AiEndpointGuard
 * @uses \OCA\Dossiq\Service\Ai\AiModelIdentity
 * @uses \OCA\Dossiq\Service\Ai\AiPiiRedactor
 * @uses \OCA\Dossiq\Service\Ai\AiPromptFactory
 */
class AiServicePromptScrubbingTest extends TestCase {

	/**
	 * A nine-digit number the redactor recognises as a BSN.
	 */
	private const BSN = '123456782';

	/**
	 * ObjectService stub capturing audit writes.
	 *
	 * @var ScrubbingSaveObjectStub|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	private PromptCapturingAiService $service;

	/**
	 * Set up: AI on, DPIA acknowledged, every feature on, PII stripping ON.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default): string {
					if ($key === 'ai_enabled'
						|| $key === 'ai_dpia_acknowledged'
						|| $key === 'ai_pii_stripping'
						|| str_starts_with($key, 'ai_feature_') === true
					) {
						return '1';
					}

					if ($key === 'register') {
						return 'dossiq';
					}

					if ($key === 'ai_audit_entry_schema') {
						return 'aiAuditEntry';
					}

					return $default;
				}
			);

		$this->objectService = $this->createMock(ScrubbingSaveObjectStub::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new PromptCapturingAiService(
			appConfig: $appConfig,
			prompts: new AiPromptFactory(),
			pii: new AiPiiRedactor(),
			endpointGuard: new AiEndpointGuard($logger),
			audit: new AiAuditLog($appConfig, $container, $logger),
			modelIdentity: new AiModelIdentity($appConfig),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Run each of the six operations with a BSN placed in every field that can
	 * carry free text or an identifier.
	 *
	 * @return void
	 */
	private function runEveryOperation(): void {
		$bsn = self::BSN;

		$this->service->classifyDocument($bsn, $bsn, 'user-1');
		$this->service->extractData($bsn, $bsn, 'user-1');
		$this->service->askQuestion('case-a', 'Klopt het BSN ' . $bsn . ' in dit dossier?', 'user-1');
		$this->service->summarize($bsn, 'case', $bsn, 'user-1');
		$this->service->suggestRouting($bsn, 'user-1');
		$this->service->suggestNextStep($bsn, 'user-1');
	}//end runEveryOperation()

	/**
	 * No prompt reaching the model carries an unscrubbed BSN — all six.
	 *
	 * @return void
	 */
	public function testEveryOperationScrubsThePromptItSends(): void {
		$this->runEveryOperation();

		$this->assertCount(6, $this->service->seenPrompts, 'all six operations reached the model');

		foreach ($this->service->seenPrompts as $index => $prompt) {
			$this->assertStringNotContainsString(
				self::BSN,
				$prompt,
				'operation ' . $index . ' sent an unscrubbed BSN to the model'
			);
			$this->assertStringContainsString(
				'[BSN_REMOVED]',
				$prompt,
				'operation ' . $index . ' did not go through the redactor'
			);
		}
	}//end testEveryOperationScrubsThePromptItSends()

	/**
	 * The Q&A prompt — the ONE that carries prose a person typed — is scrubbed.
	 *
	 * Called out separately from the loop above because it is the operation the
	 * gap actually mattered on, and a regression here should name itself.
	 *
	 * @return void
	 */
	public function testTheQuestionAUserTypedIsScrubbed(): void {
		$this->service->askQuestion(
			'case-a',
			'Het BSN is ' . self::BSN . ' en het IBAN NL91ABNA0417164300.',
			'user-1'
		);

		$prompt = $this->service->seenPrompts[0];

		$this->assertStringNotContainsString(self::BSN, $prompt);
		$this->assertStringNotContainsString('NL91ABNA0417164300', $prompt);
		$this->assertStringContainsString('[BSN_REMOVED]', $prompt);
		$this->assertStringContainsString('[IBAN_REMOVED]', $prompt);
	}//end testTheQuestionAUserTypedIsScrubbed()

	/**
	 * The audit trail records the SCRUBBED prompt, not the raw question.
	 *
	 * @return void
	 */
	public function testTheAuditTrailRecordsTheScrubbedPromptNotTheRawQuestion(): void {
		$recorded = null;
		$this->objectService->method('saveObject')
			->willReturnCallback(
				static function (string $register, string $schema, array $object) use (&$recorded): mixed {
					$recorded = $object;
					return null;
				}
			);

		$this->service->askQuestion('case-a', 'BSN ' . self::BSN . ' klopt niet.', 'user-1');

		$this->assertIsArray($recorded);
		$this->assertStringNotContainsString(
			self::BSN,
			$recorded['prompt'],
			'the audit entry wrote the raw question, BSN and all'
		);
		$this->assertStringContainsString('[BSN_REMOVED]', $recorded['prompt']);
	}//end testTheAuditTrailRecordsTheScrubbedPromptNotTheRawQuestion()

	/**
	 * With stripping switched OFF, the prompt goes out as written.
	 *
	 * The negative half of the control: it proves the assertions above are
	 * reading the redactor's effect and not something that happens regardless.
	 *
	 * @return void
	 */
	public function testWithStrippingOffThePromptIsNotScrubbed(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default): string {
					if ($key === 'ai_pii_stripping') {
						return '';
					}

					if ($key === 'ai_enabled'
						|| $key === 'ai_dpia_acknowledged'
						|| str_starts_with($key, 'ai_feature_') === true
					) {
						return '1';
					}

					return $default;
				}
			);

		$logger = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->createMock(ScrubbingSaveObjectStub::class));

		$service = new PromptCapturingAiService(
			appConfig: $appConfig,
			prompts: new AiPromptFactory(),
			pii: new AiPiiRedactor(),
			endpointGuard: new AiEndpointGuard($logger),
			audit: new AiAuditLog($appConfig, $container, $logger),
			modelIdentity: new AiModelIdentity($appConfig),
			logger: $logger,
		);

		$service->askQuestion('case-a', 'BSN ' . self::BSN, 'user-1');

		$this->assertStringContainsString(self::BSN, $service->seenPrompts[0]);
	}//end testWithStrippingOffThePromptIsNotScrubbed()
}//end class
