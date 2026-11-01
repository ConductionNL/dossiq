<?php

/**
 * HearingService golden test
 *
 * Runs every public method over a set of store, configuration and trail
 * behaviours and compares every object-service call, every audit entry,
 * every log line and every answer with a recorded golden file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder;
use OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner;
use OCA\Dossiq\Service\Bezwaar\HearingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden test for HearingService.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\HearingService
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner
 * @uses \OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder
 */
class HearingServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/golden/hearing-service.json';

	/**
	 * The calls each case makes: [method, named arguments, behaviour].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$future = self::at(modifier: 'today +20 days 10:00');
		$soon = self::at(modifier: 'today +3 days 10:00');
		$past = ['scheduledDate' => self::at(modifier: 'today -2 days 10:00'), 'attendance' => [['invitee' => 'old', 'present' => true]]];
		$coming = ['scheduledDate' => $future, 'attendance' => []];
		$invitees = [['role' => 'bezwaarmaker', 'channel' => 'email']];
		$plan = ['caseId' => 'case-1', 'scheduledDate' => $future, 'chairpersonId' => 'chair-1', 'invitees' => $invitees];
		$extras = ['location' => 'Room 1', 'recordingConsent' => 'granted', 'members' => ['m-1'], 'inspectionAvailableFrom' => self::at(modifier: 'today +5 days', format: 'Y-m-d'), 'case' => 'forged'];
		$late = [['invitee' => 'inv-1', 'present' => false, 'correctionReason' => 'came late']];
		$found = ['find' => ['session-1' => $past]];

		return [
			'schedule / happy' => ['schedule', $plan, []],
			'schedule / extras' => ['schedule', $plan + ['payload' => $extras], []],
			'schedule / no object service' => ['schedule', $plan, ['noObjectService' => true]],
			'schedule / no register' => ['schedule', $plan, ['config' => ['register' => '']]],
			'schedule / no schema' => ['schedule', $plan, ['config' => ['hearing_session_schema' => '']]],
			'schedule / no case' => ['schedule', ['caseId' => ''] + $plan, []],
			'schedule / no chair' => ['schedule', ['chairpersonId' => ''] + $plan, []],
			'schedule / no invitees' => ['schedule', ['invitees' => []] + $plan, []],
			'schedule / under the inspection floor' => ['schedule', ['scheduledDate' => $soon] + $plan, []],
			'schedule / bad date' => ['schedule', ['scheduledDate' => 'not a date'] + $plan, []],
			'schedule / save throws' => ['schedule', $plan, ['save' => 'throw']],
			'schedule / save answers null' => ['schedule', $plan, ['save' => 'null']],
			'schedule / entry fails' => ['schedule', $plan, ['trailFails' => true]],
			'waive / happy' => ['waive', ['caseId' => 'case-1', 'reason' => '  no need  '], []],
			'waive / chairperson' => ['waive', ['caseId' => 'case-1', 'reason' => 'r', 'payload' => ['chairperson' => 'c-1', 'chairpersonId' => 'c-2', 'invitees' => $invitees, 'note' => 'n']], []],
			'waive / chairperson id' => ['waive', ['caseId' => 'case-1', 'reason' => 'r', 'payload' => ['chairpersonId' => 'c-2', 'status' => 'forged']], []],
			'waive / blank reason' => ['waive', ['caseId' => 'case-1', 'reason' => '   '], []],
			'waive / no object service' => ['waive', ['caseId' => 'case-1', 'reason' => 'r'], ['noObjectService' => true]],
			'waive / no register' => ['waive', ['caseId' => 'case-1', 'reason' => 'r'], ['config' => ['register' => '']]],
			'waive / no schema' => ['waive', ['caseId' => 'case-1', 'reason' => 'r'], ['config' => ['hearing_session_schema' => '']]],
			'waive / save throws' => ['waive', ['caseId' => 'case-1', 'reason' => 'r'], ['save' => 'throw']],
			'waive / save answers null' => ['waive', ['caseId' => 'case-1', 'reason' => 'r'], ['save' => 'null']],
			'waive / entry fails' => ['waive', ['caseId' => 'case-1', 'reason' => 'r'], ['trailFails' => true]],
			'case for session' => ['getCaseIdForSession', ['sessionId' => 'session-1'], []],
			'attendance / no entries' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => []], $found],
			'attendance / no object service' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => $late], ['noObjectService' => true]],
			'attendance / not found' => ['recordAttendance', ['sessionId' => 'session-404', 'entries' => $late], $found],
			'attendance / within the window' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => [['invitee' => 'inv-1', 'present' => true], ['invitee' => 'inv-2']]], ['find' => ['session-1' => $coming]]],
			'attendance / no scheduled date' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => [['invitee' => 'inv-1']]], ['find' => ['session-1' => ['attendance' => 'x']]]],
			'attendance / late with reason' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => $late], $found],
			'attendance / late without reason' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => array_merge($late, [['invitee' => 'inv-2', 'correctionReason' => ' ']])], $found],
			'attendance / patch throws' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => $late], $found + ['patch' => 'throw']],
			'attendance / patch throws in the window' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => $late], ['find' => ['session-1' => $coming], 'patch' => 'throw']],
			'attendance / patch answers null' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => $late], $found + ['patch' => 'null']],
			'attendance / entry fails' => ['recordAttendance', ['sessionId' => 'session-1', 'entries' => $late], $found + ['trailFails' => true]],
			'minutes / summary' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 'said things']], $found],
			'minutes / stored document' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => []], ['find' => ['session-1' => ['minutesDocument' => 'doc-1']]]],
			'minutes / blank' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => '  ', 'minutesDocument' => ' ']], $found],
			'minutes / audio with consent' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 's', 'audioRecording' => 'a.mp3', 'recordingConsent' => 'granted']], $found],
			'minutes / audio without consent' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 's', 'audioRecording' => 'a.mp3']], $found],
			'minutes / no object service' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 's']], ['noObjectService' => true]],
			'minutes / not found' => ['addMinutes', ['sessionId' => 'session-404', 'payload' => ['minutesSummary' => 's']], $found],
			'minutes / patch throws' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 's']], $found + ['patch' => 'throw']],
			'minutes / patch answers null' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 's']], $found + ['patch' => 'null']],
			'minutes / entry fails' => ['addMinutes', ['sessionId' => 'session-1', 'payload' => ['minutesSummary' => 's']], $found + ['trailFails' => true]],
			'seed / via the objection' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['find' => ['obj-1' => ['case' => 'case-7']]]],
			'seed / objection without case' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['find' => ['obj-1' => ['title' => 't']]]],
			'seed / objection missing' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], []],
			'seed / objection lookup throws' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['find' => ['obj-1' => 'throw']]],
			'seed / no objection schema' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['config' => ['bezwaar_schema' => '']]],
			'seed / empty objection id' => ['seedDefaultHearing', ['objectionId' => ''], ['config' => ['bezwaar_schema' => '']]],
			'seed / already has a session' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['search' => 'some']],
			'seed / search throws' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['search' => 'throw']],
			'seed / save throws' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['save' => 'throw']],
			'seed / no object service' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['noObjectService' => true]],
			'seed / no register' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['config' => ['register' => '']]],
			'seed / no schema' => ['seedDefaultHearing', ['objectionId' => 'obj-1'], ['config' => ['hearing_session_schema' => '']]],
		];
	}//end cases()

	/**
	 * Every case matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $behaviour]) {
			$actual[$name] = $this->runCase(method: $method, args: $args, behaviour: $behaviour);
		}

		$json = $this->normaliseDates(json: (string)json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) . "\n";
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('Recorded the golden file; run again without GOLDEN_RECORD to compare.');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($json, true));
	}//end testEveryCaseMatchesTheGoldenRecord()

	/**
	 * Run one call against one behaviour and record what happened.
	 *
	 * @param string               $method    The public method.
	 * @param array<string, mixed> $args      Its arguments by name.
	 * @param array<string, mixed> $behaviour How the collaborators behave.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(string $method, array $args, array $behaviour): array {
		$log = [];
		$config = array_merge(
			['register' => 'dossiq', 'hearing_session_schema' => 'hearingSession', 'bezwaar_schema' => 'objectionProceeding'],
			($behaviour['config'] ?? [])
		);
		$objects = new GoldenHearingObjectService(behaviour: $behaviour, log: $log);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use ($behaviour, $objects, &$log): ?object {
				$log[] = 'settings getObjectService';
				return (($behaviour['noObjectService'] ?? false) === true) ? null : $objects;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($config, &$log): string {
				$log[] = 'settings getConfigValue ' . $key;
				return ($config[$key] ?? $default);
			}
		);

		$owning = $this->createMock(OwningCaseResolver::class);
		$owning->method('resolve')->willReturnCallback(
			static function (string $objectId, string $schemaKey, string $caseField = 'caseId') use (&$log): ?string {
				$log[] = 'owning resolve ' . json_encode(compact('objectId', 'schemaKey', 'caseField'));
				return 'case-9';
			}
		);

		$logger = new class ($log) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $log Shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = 'log ' . $level . ' ' . $message;
			}
		};

		$trail = new GoldenHearingAuditTrail(fails: (($behaviour['trailFails'] ?? false) === true), log: $log);
		$service = new HearingService(
			settingsService: $settings,
			logger: $logger,
			auditTrail: $trail,
			planner: new HearingSchedulePlanner(),
			minutes: new HearingMinutesRecorder(auditTrail: $trail),
			owningCase: $owning,
		);

		$outcome = [];
		try {
			$outcome['result'] = $service->$method(...$args);
		} catch (\Throwable $e) {
			$outcome['exception'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['log'] = array_map(
			static fn (string $line): mixed => (str_starts_with($line, '{') === true) ? json_decode($line, true) : $line,
			$log
		);

		return $outcome;
	}//end runCase()

	/**
	 * A date relative to today.
	 *
	 * @param string $modifier The relative date.
	 * @param string $format   The format.
	 *
	 * @return string
	 */
	private static function at(string $modifier, string $format = DateTimeInterface::ATOM): string {
		return (new DateTimeImmutable($modifier))->format($format);
	}//end at()

	/**
	 * Replace dates with their day offset from today, and moments near now
	 * with their hour offset from now.
	 *
	 * @param string $json The encoded record.
	 *
	 * @return string
	 */
	private function normaliseDates(string $json): string {
		$now = new DateTimeImmutable();
		$today = new DateTimeImmutable('today');

		return (string)preg_replace_callback(
			'/\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z))?/',
			static function (array $m) use ($now, $today): string {
				$when = new DateTimeImmutable($m[0]);
				if (isset($m[1]) === true) {
					$diff = ($when->getTimestamp() - $now->getTimestamp());
					$hours = (int)round($diff / 3600);
					if (abs($diff) < (3 * 3600) && abs($diff - ($hours * 3600)) < 120) {
						return '<now' . sprintf('%+d', $hours) . 'h>';
					}
				}

				$days = (int)$today->diff(new DateTimeImmutable($when->format('Y-m-d')))->format('%r%a');

				return '<d' . sprintf('%+d', $days) . '>' . ($m[1] ?? '');
			},
			$json
		);
	}//end normaliseDates()
}//end class
