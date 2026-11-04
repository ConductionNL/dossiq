<?php

/**
 * AanvullingsverzoekService::ask() golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\ResidentQuestionTask;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\Dossiq\Woo\WooRequestIntake;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * Golden test for AanvullingsverzoekService::ask(): the answer or refusal, every collaborator call and the logs.
 *
 * @covers \OCA\Dossiq\Service\AanvullingsverzoekService
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class AanvullingsverzoekServiceAskGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls made in one world.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * Run one ask in a world and observe it.
	 *
	 * @param array<string, mixed> $world The arguments and how the collaborators answer.
	 *
	 * @return array<string, mixed> The answer or refusal, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$act = $this->createMock(originalClassName: InformationRequestService::class);
		$act->method('ask')->willReturnCallback(
			function (string $caseId, array $items, string $recipient, int $durationDays, string $rationale = '', string $pauseReason = '') use ($world): array {
				$this->calls[] = ['act::ask', compact('caseId', 'items', 'recipient', 'durationDays', 'rationale', 'pauseReason')];
				if (($world['act'] ?? null) === 'throw') {
					throw new RuntimeException('term unreadable');
				}

				return ($world['act'] ?? ['sent' => true, 'instance' => ['id' => 'term-1', 'pauseDeadline' => '2026-12-01']]);
			}
		);

		$task = null;
		if (array_key_exists('task', $world) === true) {
			$task = $this->createMock(originalClassName: ResidentQuestionTask::class);
			$task->method('raise')->willReturnCallback(
				function (array $question, string $actor) use ($world): ?string {
					$this->calls[] = ['task::raise', compact('question', 'actor')];
					return $world['task'];
				}
			);
		}

		$service = $this->getMockBuilder(className: AanvullingsverzoekService::class)
			->setConstructorArgs(['act' => $act, 'settingsService' => $this->createMock(originalClassName: SettingsService::class), 'logger' => $logger, 'portalTask' => $task])
			->onlyMethods(['openFor', 'write', 'markCaseWaiting', 'portalSubjectOf', 'caseTypeOf'])
			->getMock();
		$service->method('openFor')->willReturnCallback(
			function (string $caseId) use ($world): ?array {
				$this->calls[] = ['openFor', $caseId];
				return ($world['open'] ?? null);
			}
		);
		$service->method('caseTypeOf')->willReturnCallback(
			function (string $caseId) use ($world): string {
				$this->calls[] = ['caseTypeOf', $caseId];
				return ($world['caseType'] ?? WooRequestIntake::CASE_TYPE_ID);
			}
		);
		$service->method('portalSubjectOf')->willReturnCallback(
			function (string $caseId) use ($world): string {
				$this->calls[] = ['portalSubjectOf', $caseId];
				return ($world['subject'] ?? '');
			}
		);
		$writes = 0;
		$service->method('write')->willReturnCallback(
			function (array $request, string $id = '') use ($world, &$writes): array {
				$this->calls[] = ['write', func_get_args()];
				$writes++;
				if (in_array($writes, ($world['writeFails'] ?? []), true) === true) {
					throw new RefusedException(rule: 'store-down', sentence: 'The store refused.', status: RefusedException::STATUS_INDETERMINATE);
				}

				return array_merge($request, ['id' => ($world['writtenId'] ?? 'avr-1')]);
			}
		);
		$service->method('markCaseWaiting')->willReturnCallback(
			function (string $caseId, ?string $since): void {
				$this->calls[] = ['markCaseWaiting', $caseId, $since];
			}
		);

		try {
			$answer = ['written' => $service->ask(...$world['ask'])];
		} catch (Throwable $e) {
			$answer = ['throws' => get_class($e), 'message' => $e->getMessage()];
			if ($e instanceof RefusedException === true) {
				$answer += ['rule' => $e->getRule(), 'status' => $e->getStatus()];
			}
		}

		$json = (string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION);
		$json = (string)preg_replace('/"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d"/', '"<now>"', $json);

		return json_decode($json, true);
	}//end observe()

	/**
	 * Every path through ask().
	 *
	 * @return void
	 */
	public function testGoldenAsk(): void {
		$base = ['caseId' => 'case-1', 'items' => ['Bankafschrift', '  ', 'Huurcontract '], 'recipient' => ' aanvrager@example.org ', 'durationDays' => 14, 'userId' => 'handler1'];
		$full = $base + ['pauseReason' => ' reason-awb-45 ', 'rationale' => ' Zonder bankafschrift niet te beoordelen ', 'party' => ' initiator '];
		$question = ['caseId' => 'case-1', 'items' => [], 'recipient' => 'a@example.org', 'durationDays' => 7, 'userId' => 'handler1', 'question' => '  Welke periode bedoelt u? '];
		$worlds = [
			'documents, the minimum' => ['ask' => $base],
			'documents, every argument' => ['ask' => $full, 'subject' => 'person:hash-1', 'task' => 'task-1'],
			'zero days still pauses one' => ['ask' => ['durationDays' => 0] + $base],
			'negative days still pause one' => ['ask' => ['durationDays' => -3] + $base],
			'instance id under @self, no deadline' => ['ask' => $base, 'act' => ['sent' => true, 'instance' => ['@self' => ['id' => 'term-9']]]],
			'no instance' => ['ask' => $base, 'act' => ['sent' => true]],
			'already open' => ['ask' => $base, 'open' => ['id' => 'avr-0']],
			'not sent' => ['ask' => $base, 'act' => ['sent' => false, 'instance' => ['id' => 'term-1']]],
			'sent as a string' => ['ask' => $base, 'act' => ['sent' => 'true']],
			'the act throws' => ['ask' => $base, 'act' => 'throw'],
			'the write fails' => ['ask' => $base, 'writeFails' => [1]],
			'a clarification on a Woo case' => ['ask' => $question, 'subject' => 'person:hash-2', 'task' => 'task-2'],
			'a clarification on a later Woo version' => ['ask' => $question, 'caseType' => WooRequestIntake::CASE_TYPE_IDENTIFIER],
			'a clarification on another case type' => ['ask' => $question, 'caseType' => 'type-other'],
			'a clarification listing documents' => ['ask' => ['items' => ['Bankafschrift']] + $question],
			'a clarification with only blank items' => ['ask' => ['items' => [' ', '']] + $question],
			'a blank clarification' => ['ask' => ['question' => '   '] + $question],
			'a clarification is checked before the open request' => ['ask' => ['items' => ['x']] + $question, 'open' => ['id' => 'avr-0']],
			'the open request is checked before the act' => ['ask' => $question, 'open' => ['id' => 'avr-0'], 'act' => 'throw'],
			'task without a subject' => ['ask' => $base, 'task' => null],
			'task raised, remembering it fails' => ['ask' => $base, 'subject' => 'person:hash-1', 'task' => 'task-3', 'writeFails' => [2]],
			'task raised for a write without id' => ['ask' => $base, 'subject' => 'person:hash-1', 'task' => 'task-4', 'writtenId' => ''],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'information-request-ask', value: $observed);
	}//end testGoldenAsk()
}//end class
