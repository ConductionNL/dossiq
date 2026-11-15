<?php

/**
 * Characterisation tests for SetStatusHandler::handle: the exact write it
 * makes, the result it answers, the log lines of a skipped or missing
 * status, and the failure it answers when anything throws.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class SetStatusHandlerGoldenTest extends TestCase {

	/**
	 * Every line the handler logs, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $lines = [];

	/**
	 * The calls the writer received, as named arguments.
	 *
	 * @var array<int, array>
	 */
	private array $writes = [];

	/**
	 * A handler over stub settings, a lookup answering by role and name, and a recording writer.
	 *
	 * @param string          $byRole  What a role resolves to.
	 * @param string          $byName  What a name resolves to.
	 * @param \Throwable|null $failure What the writer throws, if anything.
	 *
	 * @return SetStatusHandler The handler.
	 */
	private function handler(string $byRole, string $byName, ?\Throwable $failure = null): SetStatusHandler {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new \stdClass());
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'reg' : 'case-schema')
		);

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('idForRole')->willReturn($byRole);
		$lookup->method('idForName')->willReturn($byName);

		$writer = $this->createMock(CaseFieldWriter::class);
		$writer->method('write')->willReturnCallback(
			function (object $objectService, string $register, string $schema, array $case, array $changes) use ($failure): void {
				$this->writes[] = [$register, $schema, $case, $changes];
				if ($failure !== null) {
					throw $failure;
				}
			}
		);

		$sink = &$this->lines;
		$logger = new class($sink) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = [(string)$level, (string)$message, $context];
			}
		};

		return new SetStatusHandler($settings, $lookup, $writer, $logger);
	}//end handler()

	public function testTheMoveWritesOnlyTheStatusAndAnswersWhatItResolved(): void {
		$case = ['id' => 'case-1', 'caseType' => 'ct-1', 'title' => 'T'];
		$result = $this->handler('', 'st-9')->handle(
			actionConfig: ['type' => 'setStatus', 'status' => '  In behandeling  ', 'role' => ' '],
			case: $case,
			transitionContext: []
		);

		self::assertSame([['reg', 'case-schema', $case, ['status' => 'st-9']]], $this->writes);
		self::assertTrue($result->succeeded);
		self::assertNull($result->error);
		self::assertSame(['status' => 'st-9', 'statusName' => 'In behandeling', 'statusRole' => ''], $result->data);
		self::assertSame(['status' => 'st-9'], $result->caseChanges);
		self::assertSame([], $this->lines);
	}//end testTheMoveWritesOnlyTheStatusAndAnswersWhatItResolved()

	public function testARoleThatResolvesWinsOverTheName(): void {
		$result = $this->handler('st-role', 'st-name')->handle(
			actionConfig: ['status' => 'X', 'role' => 'in-progress'],
			case: ['id' => 'c', 'caseType' => 'ct'],
			transitionContext: []
		);

		self::assertSame(['status' => 'st-role', 'statusName' => 'X', 'statusRole' => 'in-progress'], $result->data);
	}//end testARoleThatResolvesWinsOverTheName()

	public function testAMissingStatusLogsWhatWasTriedAndWritesNothing(): void {
		$result = $this->handler('', '')->handle(
			actionConfig: ['status' => 'Onderzoek', 'role' => 'review'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertSame('status_not_found_on_case_type', $result->error);
		self::assertSame([], $this->writes);
		self::assertSame(
			[['warning', 'Dossiq setStatus: the case type has no status with role "review" or name "Onderzoek"', ['caseType' => 'ct-1', 'case' => 'case-1']]],
			$this->lines
		);
	}//end testAMissingStatusLogsWhatWasTriedAndWritesNothing()

	public function testASkippedPhaseLogsItAtInfoWithoutACaseId(): void {
		$result = $this->handler('', '')->handle(
			actionConfig: ['role' => 'review', 'required' => false],
			case: ['caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame(
			['skipped' => true, 'reason' => 'case_type_models_no_such_phase', 'statusRole' => 'review', 'statusName' => ''],
			$result->data
		);
		self::assertSame([], $result->caseChanges);
		self::assertSame(
			[['info', 'Dossiq setStatus: skipping an optional phase; this case type models no status with role "review"', ['caseType' => 'ct-1', 'case' => null]]],
			$this->lines
		);
	}//end testASkippedPhaseLogsItAtInfoWithoutACaseId()

	public function testAFailureAnywhereIsLoggedWithTheContextAndAnsweredAsFailed(): void {
		$result = $this->handler('', 'st-1', new \RuntimeException('disk full'))->handle(
			actionConfig: ['status' => 'X'],
			case: ['id' => 'c', 'caseType' => 'ct'],
			transitionContext: ['run' => 'r-1']
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_status_failed', $result->error);
		self::assertSame(
			[['error', 'SetStatusHandler failed', ['exception' => 'disk full', 'context' => ['run' => 'r-1']]]],
			$this->lines
		);
	}//end testAFailureAnywhereIsLoggedWithTheContextAndAnsweredAsFailed()

	public function testANonStringCaseTypeAndStatusAreReadAsText(): void {
		$result = $this->handler('', 'st-1')->handle(
			actionConfig: ['status' => 12],
			case: ['id' => 'c', 'caseType' => 7],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('12', $result->data['statusName']);
	}//end testANonStringCaseTypeAndStatusAreReadAsText()
}//end class
