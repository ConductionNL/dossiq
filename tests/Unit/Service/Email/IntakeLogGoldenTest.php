<?php

/**
 * IntakeLog golden test
 *
 * Pins what the intake log writes, reads, logs and puts on a case timeline,
 * for a provisioned and an unprovisioned log, and for stores that answer,
 * answer nothing or fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use DateTime;
use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for IntakeLog.
 *
 * @covers \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\InboundMessage
 */
class IntakeLogGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Run one call against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The config, the store's behaviour and the call.
	 *
	 * @return array<string, mixed> The answer, the store calls, the log lines and the timeline lines.
	 */
	private function observe(array $world): array {
		$calls = [];
		$store = new class($world['store'] ?? [], $calls) {
			/**
			 * @param array<string, mixed> $behaviour What each method answers ('throw' throws).
			 * @param array<int, mixed>    $calls     Collects every call.
			 */
			public function __construct(private array $behaviour, private array &$calls) {
			}

			/**
			 * Answer as configured for this method.
			 *
			 * @param string $method The method.
			 * @param array  $args   Its arguments by name.
			 *
			 * @return mixed
			 */
			private function answer(string $method, array $args): mixed {
				if (strlen((string)($args['object']['original'] ?? '')) > 100) {
					$args['object']['original'] = strlen($args['object']['original']) . ' bytes, sha1 ' . sha1($args['object']['original']);
				}

				$this->calls[] = [$method, $args];
				$answer = (array_key_exists($method, $this->behaviour) === true ? $this->behaviour[$method] : null);
				if ($answer === 'throw') {
					throw new RuntimeException($method . ' failed');
				}

				return $answer;
			}

			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): mixed {
				return $this->answer('saveObject', ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]);
			}

			public function patchObject(string $objectId, array $data, string $register = '', string $schema = ''): mixed {
				return $this->answer('patchObject', ['objectId' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema]);
			}

			public function find(string $id, string $register = '', string $schema = ''): mixed {
				return $this->answer('find', ['id' => $id, 'register' => $register, 'schema' => $schema]);
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): mixed {
				return ($this->answer('searchObjectsBySlug', ['register' => $register, 'schema' => $schema, 'filters' => $filters]) ?? []);
			}
		};

		$config = ($world['config'] ?? ['register' => 'dossiq', IntakeLog::SCHEMA_KEY => 'mailIntakeEntry']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($world['noStore'] ?? false) === true ? null : $store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($config, &$calls): string {
				$calls[] = ['getConfigValue', $key];
				return ($config[$key] ?? $default);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-03-04T05:06:07+00:00'));

		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logs, $level): void {
					$logs[] = [$level, $message, $context];
				}
			);
		}

		$timeline = [];
		$caseTimeline = $this->createMock(CaseTimeline::class);
		$caseTimeline->method('record')->willReturnCallback(
			static function (string $caseId, string $kind, string $message, array $fields = [], string $visibility = '') use (&$timeline): string {
				$timeline[] = ['caseId' => $caseId, 'kind' => $kind, 'message' => $message, 'fields' => $fields, 'visibility' => $visibility];
				return 't1';
			}
		);

		$log = new IntakeLog(settingsService: $settings, time: $time, logger: $logger, timeline: $caseTimeline);
		$answer = ($world['call'])($log);

		return ['answer' => $answer, 'calls' => $calls, 'logs' => $logs, 'timeline' => $timeline];
	}//end observe()

	/**
	 * A message with every field set.
	 *
	 * @param string $subject The subject.
	 * @param string $source  The raw source.
	 *
	 * @return InboundMessage The message.
	 */
	private static function message(string $subject = 'Aanvraag vergunning', string $source = "From: a@b.nl\r\n\r\nTekst"): InboundMessage {
		return new InboundMessage(
			accountId: 7,
			mailbox: 'INBOX',
			uid: 42,
			messageId: '<m1@b.nl>',
			subject: $subject,
			from: 'Anna <a@b.nl>',
			to: 'Gemeente <post@gemeente.nl>',
			sentAt: '2026-03-01T10:00:00+00:00',
			source: $source,
		);
	}//end message()

	/**
	 * Every method across provisioning and store behaviour.
	 *
	 * @return void
	 */
	public function testGoldenIntakeLog(): void {
		$verdict = new FilterVerdict(outcome: 'accept', filterName: 'spam', reason: 'clean');
		$results = ['spf' => 'pass', 'dkim' => 'fail', 'dmarc' => 'none', 'threading' => 'match'];
		$record = static fn (string $caseId = 'case-1', ?InboundMessage $message = null, array $res = []): \Closure => static fn (IntakeLog $log): string => $log->record(
			message: ($message ?? self::message()),
			verdict: $verdict,
			results: ($res === [] ? $results : $res),
			outcome: IntakeLog::OUTCOME_CASE,
			reason: 'Opened a case',
			caseId: $caseId,
		);
		$channel = static fn (string $caseId = 'case-1'): \Closure => static fn (IntakeLog $log): string => $log->recordChannelMessage(
			channel: 'teams',
			channelMessageId: 'msg-9',
			sender: 'Bob',
			subject: 'Vraag',
			outcome: IntakeLog::OUTCOME_INBOX,
			reason: 'Nothing matched',
			caseId: $caseId,
		);
		$recordWith = static fn (array $res): \Closure => static fn (IntakeLog $log): string => $log->record(
			message: self::message(),
			verdict: $verdict,
			results: $res,
			outcome: IntakeLog::OUTCOME_CASE,
			reason: 'Opened a case',
			caseId: 'case-1',
		);
		$saved = ['saveObject' => ['@self' => ['id' => 'e-self'], 'id' => 'e-plain']];
		$long = str_repeat('x', IntakeLog::ORIGINAL_LIMIT_BYTES + 5);
		$unprovisioned = [
			'no store' => ['noStore' => true],
			'no register' => ['config' => [IntakeLog::SCHEMA_KEY => 'mailIntakeEntry']],
			'no schema' => ['config' => ['register' => 'dossiq']],
		];

		$scenarios = [
			'record, stored with @self id' => ['store' => $saved, 'call' => $record()],
			'record, stored with plain id' => ['store' => ['saveObject' => ['id' => 'e-plain']], 'call' => $record()],
			'record, stored without id' => ['store' => ['saveObject' => ['x' => 1]], 'call' => $record()],
			'record, store answers nothing' => ['store' => ['saveObject' => null], 'call' => $record()],
			'record, store fails' => ['store' => ['saveObject' => 'throw'], 'call' => $record()],
			'record, no case' => ['store' => $saved, 'call' => $record(caseId: '')],
			'record, blank case' => ['store' => $saved, 'call' => $record(caseId: '  ')],
			'record, blank subject' => ['store' => $saved, 'call' => $record(message: self::message(subject: '   '))],
			'record, long subject' => ['store' => $saved, 'call' => $record(message: self::message(subject: str_repeat('é', 300)))],
			'record, original over the limit' => ['store' => $saved, 'call' => $record(message: self::message(source: $long))],
			'record, original at the limit' => ['store' => $saved, 'call' => $record(message: self::message(source: substr($long, 0, IntakeLog::ORIGINAL_LIMIT_BYTES)))],
			'record, missing results' => ['store' => $saved, 'call' => $record(res: ['spf' => 'pass'])],
			'record, no results' => ['store' => $saved, 'call' => $recordWith([])],
			'record, results without spf' => ['store' => $saved, 'call' => $recordWith(['unknown' => 'pass'])],
			'channel, stored' => ['store' => $saved, 'call' => $channel()],
			'channel, stored without id' => ['store' => ['saveObject' => []], 'call' => $channel(caseId: '')],
			'channel, store answers nothing' => ['store' => ['saveObject' => null], 'call' => $channel()],
			'channel, store fails' => ['store' => ['saveObject' => 'throw'], 'call' => $channel()],
			'channel, long fields' => ['store' => $saved, 'call' => static fn (IntakeLog $log): string => $log->recordChannelMessage(
				channel: str_repeat('c', 300),
				channelMessageId: str_repeat('m', 300),
				sender: str_repeat('s', 300),
				subject: str_repeat('o', 300),
				outcome: IntakeLog::OUTCOME_REFUSED,
				reason: 'r',
			)],
			'amend, patched' => ['store' => ['patchObject' => ['id' => 'e1']], 'call' => static fn (IntakeLog $log): bool => $log->amend(entryId: 'e1', changes: ['outcome' => 'released'])],
			'amend, patch answers nothing' => ['store' => ['patchObject' => null], 'call' => static fn (IntakeLog $log): bool => $log->amend(entryId: 'e1', changes: ['a' => 1])],
			'amend, patch fails' => ['store' => ['patchObject' => 'throw'], 'call' => static fn (IntakeLog $log): bool => $log->amend(entryId: 'e1', changes: ['a' => 1])],
			'amend, no entry' => ['store' => ['patchObject' => ['id' => 'e1']], 'call' => static fn (IntakeLog $log): bool => $log->amend(entryId: '', changes: ['a' => 1])],
			'find, found' => ['store' => ['find' => ['id' => 'e1', 'outcome' => 'case']], 'call' => static fn (IntakeLog $log): ?array => $log->find(entryId: 'e1')],
			'find, read fails' => ['store' => ['find' => 'throw'], 'call' => static fn (IntakeLog $log): ?array => $log->find(entryId: 'e1')],
			'find, no entry' => ['store' => ['find' => ['id' => 'e1']], 'call' => static fn (IntakeLog $log): ?array => $log->find(entryId: '')],
			'search, rows' => ['store' => ['searchObjectsBySlug' => [['id' => 'e1'], ['id' => 'e2']]], 'call' => static fn (IntakeLog $log): array => $log->search(filters: ['outcome' => 'case', '_limit' => 5])],
			'search, fails' => ['store' => ['searchObjectsBySlug' => 'throw'], 'call' => static fn (IntakeLog $log): array => $log->search()],
			'channel entry, found' => ['store' => ['searchObjectsBySlug' => [['id' => 'e1', 'channel' => 'teams']]], 'call' => static fn (IntakeLog $log): ?array => $log->findChannelEntry(channel: 'teams', channelMessageId: 'msg-9')],
			'channel entry, none' => ['store' => ['searchObjectsBySlug' => []], 'call' => static fn (IntakeLog $log): ?array => $log->findChannelEntry(channel: 'teams', channelMessageId: 'msg-9')],
			'channel entry, blank id' => ['store' => [], 'call' => static fn (IntakeLog $log): ?array => $log->findChannelEntry(channel: 'teams', channelMessageId: ' ')],
			'configured' => ['store' => [], 'call' => static fn (IntakeLog $log): bool => $log->isConfigured()],
		];
		foreach ($unprovisioned as $name => $world) {
			$scenarios['record, ' . $name] = $world + ['call' => $record()];
			$scenarios['channel, ' . $name] = $world + ['call' => $channel()];
			$scenarios['amend, ' . $name] = $world + ['call' => static fn (IntakeLog $log): bool => $log->amend(entryId: 'e1', changes: ['a' => 1])];
			$scenarios['find, ' . $name] = $world + ['call' => static fn (IntakeLog $log): ?array => $log->find(entryId: 'e1')];
			$scenarios['search, ' . $name] = $world + ['call' => static fn (IntakeLog $log): array => $log->search()];
			$scenarios['configured, ' . $name] = $world + ['call' => static fn (IntakeLog $log): bool => $log->isConfigured()];
		}

		$observed = [];
		foreach ($scenarios as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden('intake-log', $observed);
	}//end testGoldenIntakeLog()

	/**
	 * A check that is missing from the results is written as unavailable, never as passed.
	 *
	 * @return void
	 */
	public function testMissingResultsAreWrittenAsUnavailable(): void {
		foreach ([[], ['unknown' => 'pass']] as $results) {
			$observed = $this->observe(
				world: [
					'store' => ['saveObject' => ['id' => 'e1']],
					'call' => static fn (IntakeLog $log): string => $log->record(
						message: self::message(),
						verdict: new FilterVerdict(outcome: 'accept', filterName: 'spam', reason: 'clean'),
						results: $results,
						outcome: IntakeLog::OUTCOME_CASE,
						reason: 'Opened a case',
						caseId: 'case-1',
					),
				]
			);
			$saves = array_values(array_filter($observed['calls'], static fn (array $call): bool => $call[0] === 'saveObject'));
			$this->assertCount(1, $saves);
			foreach (['spfResult', 'dkimResult', 'dmarcResult', 'threadingResult'] as $field) {
				$this->assertSame(AuthenticationResult::UNAVAILABLE, $saves[0][1]['object'][$field], $field);
			}
		}
	}//end testMissingResultsAreWrittenAsUnavailable()
}//end class
