<?php

/**
 * A transition writes the end of a declared term, counted from a case date, onto the case
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\SetCaseAnswerHandler;
use OCA\Dossiq\Service\Transitions\TermEndDateHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TermEndDateHandler
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class TermEndDateHandlerTest extends TestCase {

	/**
	 * The action the aanbesteding flow declares on sending the award decision.
	 */
	private const ACTION = [
		'type' => 'setTermEnd',
		'start' => 'gunningsdatum',
		'end' => 'opschortendeTermijnEinde',
		'legalBasis' => 'Aw 2012 art. 2.127',
	];

	/**
	 * The standstill term the package seeds.
	 */
	private const STANDSTILL = [
		'caseType' => 'aanbesteding',
		'legalBasis' => 'Aw 2012 art. 2.127',
		'standardDurationDays' => 20,
		'countingMode' => 'calendarDays',
	];

	/**
	 * The term definitions.
	 *
	 * @var TermDefinitions&MockObject
	 */
	private TermDefinitions&MockObject $definitions;

	/**
	 * The case date writer.
	 *
	 * @var SetCaseAnswerHandler&MockObject
	 */
	private SetCaseAnswerHandler&MockObject $answers;

	/**
	 * The answers written, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $written = [];

	/**
	 * Set up the case type, the definitions, the dates and the writer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->definitions = $this->createMock(TermDefinitions::class);
		$this->definitions->method('endDateFor')->willReturnCallback(
			static fn (DateTimeImmutable $start, int $days, array $definitie): DateTimeImmutable => $start->modify('+' . $days . ' days')
		);
		$this->answers = $this->createMock(SetCaseAnswerHandler::class);
		$this->answers->method('handle')->willReturnCallback(
			function (array $actionConfig): ActionResult {
				$this->written[$actionConfig['name']] = $actionConfig['value'];
				return new ActionResult(succeeded: true);
			}
		);
	}//end setUp()

	/**
	 * The handler, with the aanbesteding case type and today as given.
	 *
	 * @param list<array<string, mixed>> $definitions The active definitions of the case type.
	 *
	 * @return TermEndDateHandler
	 */
	private function handler(array $definitions = [self::STANDSTILL]): TermEndDateHandler {
		$cases = $this->createMock(CaseObjectReaderInterface::class);
		$cases->method('loadCaseType')->with('case-type-uuid')->willReturn(['id' => 'case-type-uuid', 'identifier' => 'aanbesteding']);
		$this->definitions->method('allActiveFor')->with('aanbesteding')->willReturn($definitions);

		$dates = $this->createMock(CaseDateNormaliserInterface::class);
		$dates->method('toCalendarDateOrNull')->willReturnCallback(
			static fn (mixed $value): ?string => (is_string($value) === true && $value !== '' ? substr($value, 0, 10) : null)
		);
		$dates->method('todayAsCalendarDate')->willReturn('2026-11-16');

		return new TermEndDateHandler(
			cases: $cases,
			definitions: $this->definitions,
			dates: $dates,
			answers: $this->answers,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end handler()

	/**
	 * Sending the award decision today stamps today and ends the standstill 20 days later.
	 *
	 * @return void
	 */
	public function testAnEmptyStartIsTodayAndTheEndFollowsByTheTerm(): void {
		$result = $this->handler()->handle(self::ACTION, ['id' => 'case-1', 'caseType' => 'case-type-uuid'], []);

		$this->assertTrue($result->succeeded);
		$this->assertSame(['gunningsdatum' => '2026-11-16', 'opschortendeTermijnEinde' => '2026-12-06'], $this->written);
		$this->assertSame($this->written, $result->caseChanges);
		$this->assertSame(20, $result->data['days']);
	}//end testAnEmptyStartIsTodayAndTheEndFollowsByTheTerm()

	/**
	 * A start date the case already carries is kept, and the end is counted from it.
	 *
	 * @return void
	 */
	public function testAStartTheCaseCarriesIsKept(): void {
		$case = [
			'id' => 'case-1',
			'caseType' => 'case-type-uuid',
			'properties' => [['name' => 'gunningsdatum', 'value' => '2026-11-10']],
		];

		$result = $this->handler()->handle(self::ACTION, $case, []);

		$this->assertTrue($result->succeeded);
		$this->assertSame(['opschortendeTermijnEinde' => '2026-11-30'], $this->written, 'the start date is not rewritten');
	}//end testAStartTheCaseCarriesIsKept()

	/**
	 * The term is chosen by its legal basis among the case type's definitions.
	 *
	 * @return void
	 */
	public function testTheTermIsChosenByItsLegalBasis(): void {
		$other = ['legalBasis' => 'Awb 4:13', 'standardDurationDays' => 56];

		$this->handler([$other, self::STANDSTILL])->handle(self::ACTION, ['id' => 'case-1', 'caseType' => 'case-type-uuid', 'gunningsdatum' => '2026-11-01'], []);

		$this->assertSame(['opschortendeTermijnEinde' => '2026-11-21'], $this->written);
	}//end testTheTermIsChosenByItsLegalBasis()

	/**
	 * Without a term of that legal basis nothing is written and the action fails.
	 *
	 * @return void
	 */
	public function testWithoutTheTermNothingIsWritten(): void {
		$result = $this->handler([['legalBasis' => 'Awb 4:13', 'standardDurationDays' => 56]])->handle(self::ACTION, ['id' => 'case-1', 'caseType' => 'case-type-uuid'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('set_term_end_no_term', $result->error);
		$this->assertSame([], $this->written);
	}//end testWithoutTheTermNothingIsWritten()

	/**
	 * An action missing one of its three keys is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnIncompleteActionIsRefused(): void {
		$this->definitions->expects($this->never())->method('allActiveFor');

		$result = $this->handler()->handle(['type' => 'setTermEnd', 'start' => 'gunningsdatum', 'legalBasis' => 'Aw 2012 art. 2.127'], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('set_term_end_incomplete', $result->error);
	}//end testAnIncompleteActionIsRefused()

	/**
	 * A write that fails fails the action.
	 *
	 * @return void
	 */
	public function testAFailedWriteFailsTheAction(): void {
		$answers = $this->createMock(SetCaseAnswerHandler::class);
		$answers->method('handle')->willReturn(new ActionResult(succeeded: false, error: 'set_case_answer_failed'));
		$this->answers = $answers;

		$result = $this->handler()->handle(self::ACTION, ['id' => 'case-1', 'caseType' => 'case-type-uuid'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('set_term_end_failed', $result->error);
	}//end testAFailedWriteFailsTheAction()
}//end class
