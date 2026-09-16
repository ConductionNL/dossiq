<?php

/**
 * The planned end and the planned start, beside the statutory term.
 *
 * The pair these cases exist for: a case late against its plan and inside its
 * statutory term has to read as BOTH, because those two facts lead to different
 * actions. A test that only asserted the plan was overdue would pass on an
 * implementation that had collapsed the two into one field.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-TERM-062: a planned end and a planned start sit beside the statutory term.
 *
 * @covers \OCA\Dossiq\Service\CaseTermsService
 * @uses \OCA\Dossiq\Service\TermKind
 */
class PlannedTermTest extends TestCase {
	use BindsTermFixtures;

	/**
	 * The store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * The declarations.
	 *
	 * @var TermDeclarationReader&MockObject
	 */
	private TermDeclarationReader $declarations;

	/**
	 * The service under test.
	 *
	 * @var CaseTermsService
	 */
	private CaseTermsService $service;

	/**
	 * Wire the service against a store that remembers what it was handed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnArgument(0);

		$this->written = [];
		$this->termService->method('saveTermInstance')->willReturnCallback(
			function (array $instance): array {
				$instance['id'] = ('written-' . count($this->written));
				$this->written[] = $instance;

				return $instance;
			}
		);
		$this->termService->method('instancesForCase')->willReturnCallback(
			fn (string $caseId): array => $this->instances
		);

		$this->service = new CaseTermsService(
			termService: $this->termService,
			declarations: $this->declarations,
			timers: $timers,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A declared planned lead time becomes its own instance of kind planned.
	 *
	 * @return void
	 */
	public function testThePlannedEndIsItsOwnInstance(): void {
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['plannedLeadTimeDays' => 30])
		);

		$this->service->bindForCase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			start: new DateTimeImmutable('2026-09-01'),
		);

		self::assertCount(1, $this->written);
		self::assertSame(TermKind::PLANNED, $this->written[0]['kind']);
		self::assertSame('2026-10-01', $this->written[0]['endDateCurrent']);
	}//end testThePlannedEndIsItsOwnInstance()

	/**
	 * A case with a planned start carries it on the planned instance.
	 *
	 * @return void
	 */
	public function testWorkIsScheduledFromAPlannedStart(): void {
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['plannedLeadTimeDays' => 30])
		);

		$this->service->bindForCase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			start: new DateTimeImmutable('2026-09-01'),
			plannedStart: '2026-09-15',
		);

		self::assertSame('2026-09-15', $this->written[0]['plannedStartDate']);
	}//end testWorkIsScheduledFromAPlannedStart()

	/**
	 * Late against the plan and on time against the law, read as both.
	 *
	 * @return void
	 */
	public function testLateAgainstThePlanAndOnTimeAgainstTheLaw(): void {
		$this->instances = [
			$this->instanceOf(kind: TermKind::STATUTORY, end: '2026-11-01'),
			$this->instanceOf(kind: TermKind::PLANNED, end: '2026-09-10'),
		];

		$terms = $this->service->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		$byKind = array_column($terms, null, 'kind');

		self::assertTrue($byKind[TermKind::PLANNED]['overdue'], 'The planned end has passed.');
		self::assertFalse($byKind[TermKind::STATUTORY]['overdue'], 'The statutory term has not.');
	}//end testLateAgainstThePlanAndOnTimeAgainstTheLaw()

	/**
	 * The two overruns are reported on separately, not folded into one flag.
	 *
	 * @return void
	 */
	public function testThePlannedOverrunIsReportedApartFromTheStatutoryOne(): void {
		$this->instances = [
			$this->instanceOf(kind: TermKind::STATUTORY, end: '2026-11-01'),
			$this->instanceOf(kind: TermKind::PLANNED, end: '2026-09-10'),
		];
		$this->declarations->method('phasesOf')->willReturn([]);

		$progress = $this->service->progressFor(
			caseId: 'c1',
			caseTypeId: 'ct1',
			now: new DateTimeImmutable('2026-09-15'),
		);

		self::assertTrue($progress['plannedOverdue']);
		self::assertFalse($progress['statutoryOverdue']);
	}//end testThePlannedOverrunIsReportedApartFromTheStatutoryOne()

	/**
	 * A case type that declares no plan gets no planned instance.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoPlanBindsNothing(): void {
		$this->declarations->method('forCaseType')->willReturn($this->declared([]));

		$this->service->bindForCase(caseId: 'c1', caseTypeId: 'ct1', start: new DateTimeImmutable('2026-09-01'));

		self::assertSame([], $this->written);
	}//end testACaseTypeWithNoPlanBindsNothing()
}//end class
