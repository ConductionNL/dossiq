<?php

/**
 * AiService golden test
 *
 * Pins what the six AI operations answer, which prompt they send, which audit
 * entries they record (field order included) and what they log, across the
 * feature gates, PII stripping, model answers and model failures.
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
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the AI operations of AiService.
 *
 * @covers \OCA\Dossiq\Service\AiService
 * @uses \OCA\Dossiq\Service\Ai\AiModelIdentity
 * @uses \OCA\Dossiq\Service\Ai\AiPiiRedactor
 * @uses \OCA\Dossiq\Service\Ai\AiPromptFactory
 */
class AiServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const ON = [
		'ai_enabled' => '1',
		'ai_dpia_acknowledged' => '1',
		'ai_feature_classification' => '1',
		'ai_feature_extraction' => '1',
		'ai_feature_qa' => '1',
		'ai_feature_summary' => '1',
		'ai_feature_routing' => '1',
		'ai_feature_decision_support' => '1',
		'ai_pii_stripping' => '0',
		'ai_model_name' => 'llama-test',
	];

	private const FULL = [
		'confidence' => 0.7,
		'averageConfidence' => 0.4,
		'fields' => ['a' => 1],
		'answer' => 'yes',
		'sources' => ['s1'],
		'summary' => 'short',
		'suggestions' => ['x'],
		'other' => true,
	];

	/**
	 * Run one operation against a configuration and a model behaviour.
	 *
	 * @param array $scenario The operation, its arguments, the config and the model answer.
	 *
	 * @return array What was answered, sent, recorded and logged.
	 */
	private function observe(array $scenario): array {
		$config = $scenario['config'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (array_key_exists($key, $config) === true ? $config[$key] : $default)
		);

		$trail = [];
		$audit = $this->createMock(AiAuditLog::class);
		$audit->method('record')->willReturnCallback(
			static function (array $entry) use (&$trail): void {
				foreach (['timestamp', 'responseTimeMs'] as $volatile) {
					if (array_key_exists($volatile, $entry) === true) {
						$entry[$volatile] = get_debug_type($entry[$volatile]);
					}
				}

				$trail[] = $entry;
			}
		);

		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logs, $level): void {
					$logs[] = [$level, $message, $context];
				}
			);
		}

		$model = $scenario['model'];
		$service = new class($appConfig, new AiPromptFactory(), new AiPiiRedactor(), $this->createMock(AiEndpointGuard::class), $audit, new AiModelIdentity(appConfig: $appConfig), $logger, $model) extends AiService {
			public array $sent = [];

			public function __construct(IAppConfig $a, AiPromptFactory $p, AiPiiRedactor $r, AiEndpointGuard $g, AiAuditLog $l, AiModelIdentity $m, LoggerInterface $log, private mixed $model) {
				parent::__construct($a, $p, $r, $g, $l, $m, $log);
			}

			protected function callAiModel(string $prompt): array {
				$this->sent[] = $prompt;
				if (is_string($this->model) === true) {
					throw new RuntimeException($this->model);
				}

				return $this->model;
			}
		};

		$answer = $service->{$scenario['op']}(...$scenario['args']);

		return ['answer' => $answer, 'sent' => $service->sent, 'trail' => $trail, 'logs' => $logs];
	}//end observe()

	/**
	 * Every operation under every gate and model behaviour.
	 *
	 * @return void
	 */
	public function testGoldenOperations(): void {
		$operations = [
			'classifyDocument' => ['caseId' => 'case-1', 'documentId' => 'doc-1', 'userId' => 'ann'],
			'extractData' => ['caseId' => 'case-1', 'documentId' => 'doc-1', 'userId' => 'ann'],
			'extractData no document' => ['caseId' => 'case-1', 'documentId' => null, 'userId' => 'ann'],
			'askQuestion' => ['caseId' => 'case-1', 'question' => 'Mail jan@example.nl, BSN 123456782?', 'userId' => 'ann'],
			'summarize' => ['caseId' => 'case-1', 'type' => 'document', 'documentId' => 'doc-1', 'userId' => 'ann'],
			'summarize no document' => ['caseId' => 'case-1', 'type' => 'timeline', 'documentId' => null, 'userId' => 'ann'],
			'suggestRouting' => ['caseId' => 'case-1', 'userId' => 'ann'],
			'suggestNextStep' => ['caseId' => 'case-1', 'userId' => 'ann'],
		];
		$features = [
			'classifyDocument' => 'classification',
			'extractData' => 'extraction',
			'askQuestion' => 'qa',
			'summarize' => 'summary',
			'suggestRouting' => 'routing',
			'suggestNextStep' => 'decision_support',
		];
		$variants = [
			'full answer' => [self::ON, self::FULL],
			'empty answer' => [self::ON, []],
			'model fails' => [self::ON, 'model down'],
			'pii stripping on' => [array_merge(self::ON, ['ai_pii_stripping' => '1']), self::FULL],
			'pii stripping default' => [array_diff_key(self::ON, ['ai_pii_stripping' => 1]), ['confidence' => 1]],
			'ai off' => [array_merge(self::ON, ['ai_enabled' => '']), self::FULL],
			'dpia missing' => [array_diff_key(self::ON, ['ai_dpia_acknowledged' => 1]), self::FULL],
			'ai off and dpia missing' => [['ai_feature_qa' => '1'], self::FULL],
		];

		$observed = [];
		foreach ($operations as $name => $args) {
			$op = explode(' ', $name)[0];
			foreach ($variants as $variant => [$config, $model]) {
				$observed[$name . ', ' . $variant] = $this->observe(
					scenario: ['op' => $op, 'args' => $args, 'config' => $config, 'model' => $model]
				);
			}

			$off = $config = array_diff_key(self::ON, ['ai_feature_' . $features[$op] => 1]);
			$observed[$name . ', feature off'] = $this->observe(
				scenario: ['op' => $op, 'args' => $args, 'config' => $off, 'model' => self::FULL]
			);
			$observed[$name . ', feature stored as true'] = $this->observe(
				scenario: ['op' => $op, 'args' => $args, 'config' => array_merge(self::ON, ['ai_feature_' . $features[$op] => 'true']), 'model' => self::FULL]
			);
		}//end foreach

		$this->assertMatchesGolden('ai-service-operations', $observed);
	}//end testGoldenOperations()
}//end class
