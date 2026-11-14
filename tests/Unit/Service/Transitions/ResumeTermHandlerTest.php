<?php

/**
 * Unit tests for ResumeTermHandler: finishing the task that processed an
 * aanvulling resumes the case's paused term, and every other state comes back
 * as a named error instead of a resume.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Transitions\ResumeTermHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ResumeTermHandler
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class ResumeTermHandlerTest extends TestCase {

	private TermijnService&MockObject $terms;

	private DeadlinePauseService&MockObject $pauses;

	private LoggerInterface&MockObject $logger;

	private ResumeTermHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->terms = $this->createMock(TermijnService::class);
		$this->pauses = $this->createMock(DeadlinePauseService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->handler = new ResumeTermHandler($this->terms, $this->pauses, $this->logger);
	}//end setUp()

	public function testAPausedTermIsResumedAndItsNewEndDateReturned(): void {
		$this->terms->expects($this->once())->method('getTermijnInstanceForZaak')->with('case-1')
			->willReturn(['id' => 'term-7', 'status' => 'paused']);
		$this->pauses->expects($this->once())->method('resumeAfterPauze')->with('term-7')
			->willReturn(['endDateCurrent' => '2026-12-24']);

		$result = $this->handler->handle([], ['id' => ' case-1 '], []);

		$this->assertTrue($result->succeeded);
		$this->assertNull($result->error);
		$this->assertSame(['endDate' => '2026-12-24'], $result->data);
	}//end testAPausedTermIsResumedAndItsNewEndDateReturned()

	public function testTheUuidsAreUsedWhenNoIdIsSet(): void {
		$this->terms->expects($this->once())->method('getTermijnInstanceForZaak')->with('case-uuid')
			->willReturn(['uuid' => 'term-uuid', 'status' => 'paused']);
		$this->pauses->expects($this->once())->method('resumeAfterPauze')->with('term-uuid')->willReturn([]);

		$result = $this->handler->handle([], ['uuid' => 'case-uuid'], []);

		$this->assertTrue($result->succeeded);
		$this->assertSame(['endDate' => ''], $result->data);
	}//end testTheUuidsAreUsedWhenNoIdIsSet()

	public function testACaseWithoutAnIdIsNotFound(): void {
		$this->terms->expects($this->never())->method('getTermijnInstanceForZaak');

		$result = $this->handler->handle([], ['id' => '  '], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('case_not_found', $result->error);
	}//end testACaseWithoutAnIdIsNotFound()

	public function testACaseWithoutATermIsRefused(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn(null);
		$this->pauses->expects($this->never())->method('resumeAfterPauze');

		$this->assertSame('no_term_on_this_case', $this->handler->handle([], ['id' => 'case-1'], [])->error);
	}//end testACaseWithoutATermIsRefused()

	public function testARunningTermIsNotResumed(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn(['id' => 'term-7', 'status' => 'running']);
		$this->pauses->expects($this->never())->method('resumeAfterPauze');

		$result = $this->handler->handle([], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('term_not_paused', $result->error);
	}//end testARunningTermIsNotResumed()

	public function testOnlyAPausedTermIsResumedNotAFinishedOrStatuslessOne(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturnOnConsecutiveCalls(
			['id' => 'term-7', 'status' => 'completed'],
			['id' => 'term-8']
		);
		$this->pauses->expects($this->never())->method('resumeAfterPauze');

		$this->assertSame('term_not_paused', $this->handler->handle([], ['id' => 'case-1'], [])->error);
		$this->assertSame('term_not_paused', $this->handler->handle([], ['id' => 'case-2'], [])->error);
	}//end testOnlyAPausedTermIsResumedNotAFinishedOrStatuslessOne()

	public function testAFailingResumeIsLoggedAndReported(): void {
		$this->terms->method('getTermijnInstanceForZaak')->willReturn(['id' => 'term-7', 'status' => 'paused']);
		$this->pauses->method('resumeAfterPauze')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with(
			'ResumeTermHandler: the term could not be resumed',
			['exception' => 'store down', 'case' => 'case-1']
		);

		$result = $this->handler->handle([], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('resume_term_failed', $result->error);
	}//end testAFailingResumeIsLoggedAndReported()
}//end class
