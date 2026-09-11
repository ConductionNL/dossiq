<?php

/**
 * AI failure-audit and DPIA-gate unit tests.
 *
 * TWO defects, one file, because both are about the AI subsystem telling the
 * truth about what it did.
 *
 * 1. A FAILED AI CALL WROTE NO AUDIT ROW. All six operations caught their
 *    exception, logged it and returned; `AiAuditLog::record()` was reachable
 *    only from the success path. An audit trail that records only successes
 *    cannot answer "what did this thing try to do", which is the question an
 *    audit trail exists for — a model that refused a prompt, timed out, or was
 *    unreachable left no trace that it had been asked at all. The `action` enum
 *    gained `failed` and the schema gained `error` (aiAuditEntry 1.0.0 → 1.1.0).
 *
 * 2. `ai_dpia_acknowledged` GATED NOTHING. It was written by the admin tab and
 *    read back for display, and no PHP consulted it, while the same tab told the
 *    administrator the acknowledgement was required before AI features could be
 *    activated. It is now enforced in `isFeatureEnabled()`, the single
 *    chokepoint every operation already passes through.
 *
 * The DPIA tests are written as a LOOP over all six operations rather than one
 * representative call. A gate that holds for five operations and not the sixth
 * is the same failure mode this whole change is about.
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
use RuntimeException;

/**
 * ObjectService stub matching the audit writer's named-arg signature.
 */
interface FailureAuditSaveObjectStub {

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
 * AiService whose network seam always throws — a model that cannot be reached.
 */
class FailingAiService extends AiService {

	/**
	 * @inheritDoc
	 */
	protected function callAiModel(string $prompt): array {
		throw new RuntimeException('AI model connection failed: connection refused');
	}//end callAiModel()
}//end class

/**
 * A failed AI call is recorded, and the DPIA acknowledgement is enforced.
 *
 * @covers \OCA\Dossiq\Service\AiService
 *
 * @uses \OCA\Dossiq\Service\Ai\AiAuditLog
 * @uses \OCA\Dossiq\Service\Ai\AiEndpointGuard
 * @uses \OCA\Dossiq\Service\Ai\AiModelIdentity
 * @uses \OCA\Dossiq\Service\Ai\AiPiiRedactor
 * @uses \OCA\Dossiq\Service\Ai\AiPromptFactory
 */
class AiServiceFailureAndDpiaGateTest extends TestCase {

	/**
	 * Every audit entry written during a test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * Build an AiService over an app-config with the given overrides.
	 *
	 * @param bool $failing Whether the model call throws.
	 * @param array<string, string> $overrides App-config overrides.
	 *
	 * @return AiService The service under test.
	 */
	private function service(bool $failing, array $overrides = []): AiService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default) use ($overrides): string {
					if (array_key_exists($key, $overrides) === true) {
						return $overrides[$key];
					}

					if ($key === 'ai_enabled'
						|| $key === 'ai_dpia_acknowledged'
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

		$objectService = $this->createMock(FailureAuditSaveObjectStub::class);
		$objectService->method('saveObject')
			->willReturnCallback(
				function (string $register, string $schema, array $object): mixed {
					$this->recorded[] = $object;
					return null;
				}
			);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$logger = $this->createMock(LoggerInterface::class);

		$arguments = [
			'appConfig' => $appConfig,
			'prompts' => new AiPromptFactory(),
			'pii' => new AiPiiRedactor(),
			'endpointGuard' => new AiEndpointGuard($logger),
			'audit' => new AiAuditLog($appConfig, $container, $logger),
			'modelIdentity' => new AiModelIdentity($appConfig),
			'logger' => $logger,
		];

		if ($failing === true) {
			return new FailingAiService(...$arguments);
		}

		return new AiService(...$arguments);
	}//end service()

	/**
	 * Call all six operations on a service.
	 *
	 * @param AiService $service The service to exercise.
	 *
	 * @return array<string, array<string, mixed>> The result of each, keyed by type.
	 */
	private function callEveryOperation(AiService $service): array {
		return [
			'classification' => $service->classifyDocument('case-a', 'doc-1', 'user-1'),
			'extraction' => $service->extractData('case-a', 'doc-1', 'user-1'),
			'qa' => $service->askQuestion('case-a', 'Is dit compleet?', 'user-1'),
			'summary' => $service->summarize('case-a', 'case', null, 'user-1'),
			'routing' => $service->suggestRouting('case-a', 'user-1'),
			'decision_support' => $service->suggestNextStep('case-a', 'user-1'),
		];
	}//end callEveryOperation()

	/**
	 * Every failed operation writes exactly one `action: failed` audit entry.
	 *
	 * @return void
	 */
	public function testEveryFailedOperationRecordsAnAuditEntry(): void {
		$results = $this->callEveryOperation($this->service(failing: true));

		foreach ($results as $type => $result) {
			$this->assertFalse($result['success'], $type . ' should have failed');
		}

		$this->assertCount(
			6,
			$this->recorded,
			'six operations failed and six audit entries should have been written'
		);

		$typesRecorded = array_column($this->recorded, 'type');
		foreach (array_keys($results) as $type) {
			$this->assertContains($type, $typesRecorded, 'no failure entry for ' . $type);
		}

		foreach ($this->recorded as $entry) {
			$this->assertSame('failed', $entry['action']);
			$this->assertSame('user-1', $entry['userId']);
			$this->assertNotEmpty($entry['timestamp']);
			$this->assertStringContainsString('connection refused', $entry['error']);
		}
	}//end testEveryFailedOperationRecordsAnAuditEntry()

	/**
	 * The failure entry carries only values the aiAuditEntry schema declares.
	 *
	 * A row the register would reject is a row that never lands, and
	 * `AiAuditLog::record()` swallows its own errors — so a schema mismatch here
	 * would look exactly like the defect being fixed.
	 *
	 * @return void
	 */
	public function testTheFailureEntryMatchesTheSchema(): void {
		$this->service(failing: true)->classifyDocument('case-a', 'doc-1', 'user-1');

		$this->assertCount(1, $this->recorded, 'the failed call wrote no audit entry at all');
		$entry = $this->recorded[0];

		$declared = [
			'type',
			'action',
			'caseId',
			'documentId',
			'model',
			'prompt',
			'suggestion',
			'confidence',
			'userAction',
			'actualValue',
			'reason',
			'userId',
			'timestamp',
			'responseTimeMs',
			'error',
		];

		foreach (array_keys($entry) as $key) {
			$this->assertContains($key, $declared, $key . ' is not declared on the aiAuditEntry schema');
		}

		// The schema's four required fields.
		foreach (['type', 'action', 'userId', 'timestamp'] as $required) {
			$this->assertArrayHasKey($required, $entry);
		}

		$this->assertContains(
			$entry['action'],
			['suggestion', 'accepted', 'rejected', 'modified', 'failed'],
			'action must be a value the schema enum allows'
		);
	}//end testTheFailureEntryMatchesTheSchema()

	/**
	 * Without the DPIA acknowledgement, every AI feature reports unavailable.
	 *
	 * @return void
	 */
	public function testWithoutTheDpiaEveryFeatureIsUnavailable(): void {
		$service = $this->service(failing: false, overrides: ['ai_dpia_acknowledged' => '']);

		foreach ($this->callEveryOperation($service) as $type => $result) {
			$this->assertFalse($result['success'], $type . ' ran without a DPIA acknowledgement');
			$this->assertStringContainsString(
				'DPIA',
				$result['message'],
				$type . ' refused without saying why'
			);
		}

		$this->assertSame([], $this->recorded, 'a gated call must not reach the model or the trail');
	}//end testWithoutTheDpiaEveryFeatureIsUnavailable()

	/**
	 * The DPIA gate is reported by `isFeatureEnabled()` itself.
	 *
	 * @return void
	 */
	public function testIsFeatureEnabledIsFalseWithoutTheDpia(): void {
		$withoutDpia = $this->service(failing: false, overrides: ['ai_dpia_acknowledged' => '']);
		$withDpia = $this->service(failing: false, overrides: ['ai_dpia_acknowledged' => '1']);

		foreach (['classification', 'extraction', 'qa', 'summary', 'routing', 'decision_support'] as $feature) {
			$this->assertFalse($withoutDpia->isFeatureEnabled($feature), $feature);
			$this->assertTrue($withDpia->isFeatureEnabled($feature), $feature);
		}

		$this->assertFalse($withoutDpia->isDpiaAcknowledged());
		$this->assertTrue($withDpia->isDpiaAcknowledged());
	}//end testIsFeatureEnabledIsFalseWithoutTheDpia()

	/**
	 * A feature switched off still reports as switched off, not as a DPIA
	 * problem — the two refusals name different toggles.
	 *
	 * @return void
	 */
	public function testAFeatureSwitchedOffSaysSoRatherThanBlamingTheDpia(): void {
		$service = $this->service(
			failing: false,
			overrides: ['ai_dpia_acknowledged' => '1', 'ai_feature_qa' => '']
		);

		$result = $service->askQuestion('case-a', 'Is dit compleet?', 'user-1');

		$this->assertFalse($result['success']);
		$this->assertStringNotContainsString('DPIA', $result['message']);
		$this->assertStringContainsString('not enabled', $result['message']);
	}//end testAFeatureSwitchedOffSaysSoRatherThanBlamingTheDpia()
}//end class
