<?php

/**
 * Golden test for SuspendTermHandler: every outcome a flow step can get, with its
 * exact error, data and log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\SuspendTermHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class SuspendTermHandlerGoldenTest extends TestCase {

	private TermijnService&MockObject $terms;

	private DeadlinePauseService&MockObject $pauses;

	/** @var array<int, array{0: string, 1: string, 2: array}> */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();
		$this->terms  = $this->createMock(TermijnService::class);
		$this->pauses = $this->createMock(DeadlinePauseService::class);
		$this->logged = [];
	}//end setUp()

	public function testTheCaseIdAndDurationAreCheckedFirst(): void {
		$this->terms->expects($this->never())->method('getTermijnInstanceForZaak');
		$this->assertSame([false, 'case_not_found', []], $this->outcome(['durationDays' => 3], ['id' => '  ']));
		$this->assertSame([false, 'case_not_found', []], $this->outcome(['durationDays' => 3], []));
		$this->assertSame([false, 'duration_required', []], $this->outcome(['durationDays' => '0'], ['id' => 'c']));
		$this->assertSame([false, 'duration_required', []], $this->outcome(['durationDays' => -2], ['uuid' => 'c']));
		$this->assertSame([], $this->logged);
	}//end testTheCaseIdAndDurationAreCheckedFirst()

	public function testThePauseIsRegisteredWithTheExactArguments(): void {
		$this->terms->expects($this->once())->method('getTermijnInstanceForZaak')->with('case-9')
			->willReturn(['uuid' => 'term-u', 'status' => 'verlengd']);
		$this->pauses->expects($this->once())->method('registerPauze')
			->with('term-u', 5, '', '', '')
			->willReturn([]);

		$this->assertSame([true, null, ['endDate' => '']], $this->outcome(['durationDays' => '5'], ['uuid' => ' case-9 ']));
		$this->assertSame([], $this->logged);
	}//end testThePauseIsRegisteredWithTheExactArguments()

	public function testNoTermAndAPausedTerm(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturnCallback(
			static fn (string $caseId): ?array => ($caseId === 'none') ? null : ['id' => 't', 'status' => 'paused']
		);
		$this->pauses->expects($this->never())->method('registerPauze');

		$this->assertSame([false, 'no_term_on_this_case', []], $this->outcome(['durationDays' => 1], ['id' => 'none']));
		$this->assertSame([false, 'term_already_paused', []], $this->outcome(['durationDays' => 1], ['id' => 'p']));
	}//end testNoTermAndAPausedTerm()

	public function testAFailureIsLoggedAndAnsweredGenerically(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturnCallback(
			static function (string $caseId): array {
				if ($caseId === 'read') {
					throw new RuntimeException('store down');
				}

				return ['id' => 't', 'status' => 'lopend'];
			}
		);
		$this->pauses->method('registerPauze')->willThrowException(new RuntimeException('calendar down'));

		$this->assertSame([false, 'suspend_term_failed', []], $this->outcome(['durationDays' => 1], ['id' => 'read']));
		$this->assertSame([false, 'suspend_term_failed', []], $this->outcome(['durationDays' => 1], ['id' => 'write']));
		$this->assertSame(
			[
				['error', 'SuspendTermHandler: the term could not be suspended', ['exception' => 'store down', 'case' => 'read']],
				['error', 'SuspendTermHandler: the term could not be suspended', ['exception' => 'calendar down', 'case' => 'write']],
			],
			$this->logged
		);
	}//end testAFailureIsLoggedAndAnsweredGenerically()

	public function testARefusalOnReadingTheTermIsTheStepsError(): void {
		$this->terms->method('getTermijnInstanceForZaak')
			->willThrowException(new RefusedException('no-access', 'Not yours.'));

		$this->assertSame([false, 'no_access', []], $this->outcome(['durationDays' => 1], ['id' => 'c']));
		$this->assertSame([], $this->logged);
	}//end testARefusalOnReadingTheTermIsTheStepsError()

	/**
	 * Run the handler and flatten its result.
	 *
	 * @return array{0: bool, 1: string|null, 2: array}
	 */
	private function outcome(array $config, array $case): array {
		$result = $this->handler()->handle($config, $case, ['ignored' => true]);
		$this->assertInstanceOf(ActionResult::class, $result);
		$this->assertSame([], $result->caseChanges);
		return [$result->succeeded, $result->error, $result->data];
	}//end outcome()

	private function handler(): SuspendTermHandler {
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new SuspendTermHandler($this->terms, $this->pauses, $logger);
	}//end handler()
}//end class
