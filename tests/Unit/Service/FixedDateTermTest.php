<?php

/**
 * A case type's term as a fixed closing date rather than a lead time.
 *
 * A subsidy round closes on a date, not so many days after each application.
 * The case that matters most here is the last one: a round whose date has
 * already passed still creates the case, with a term that reads expired, because
 * refusing the case would lose a real application that has a real answer.
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
 * REQ-TERM-064: a case type's term is a lead time or a fixed date.
 *
 * @covers \OCA\Dossiq\Service\CaseTermsService
 * @covers \OCA\Dossiq\Service\TermDeclarationReader
 */
class FixedDateTermTest extends TestCase {
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
	 * The statutory instance the store already holds, when any.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $existing = null;

	/**
	 * What `updateTermijnInstance()` was patched with.
	 *
	 * @var array<string, mixed>
	 */
	private array $patched = [];

	/**
	 * The service under test.
	 *
	 * @var CaseTermsService
	 */
	private CaseTermsService $service;

	/**
	 * Wire the service.
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
		$this->termService->method('getTermijnInstanceForZaak')->willReturnCallback(
			fn (string $caseId): ?array => $this->existing
		);
		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			function (string $id, array $patch): array {
				$this->patched = $patch;

				return array_merge(($this->existing ?? []), $patch);
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
	 * Two cases created in different months both end on the declared date.
	 *
	 * @return void
	 */
	public function testASubsidyRoundClosesOnADate(): void {
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['fixedEndDate' => '2027-03-01'])
		);

		$this->service->bindForCase(caseId: 'c1', caseTypeId: 'ct1', start: new DateTimeImmutable('2027-01-10'));
		$this->service->bindForCase(caseId: 'c2', caseTypeId: 'ct1', start: new DateTimeImmutable('2027-02-20'));

		self::assertCount(2, $this->written);
		self::assertSame('2027-03-01', $this->written[0]['endDateCurrent']);
		self::assertSame('2027-03-01', $this->written[1]['endDateCurrent']);
		self::assertSame(TermKind::STATUTORY, $this->written[0]['kind']);
	}//end testASubsidyRoundClosesOnADate()

	/**
	 * A fixed date already past creates the case with an expired term.
	 *
	 * @return void
	 */
	public function testAnApplicationToAClosedRoundIsStillACase(): void {
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['fixedEndDate' => '2026-03-01'])
		);

		$bound = $this->service->bindForCase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			start: new DateTimeImmutable('2026-09-15'),
		);

		self::assertCount(1, $bound, 'The case is created and the term is bound; nothing refuses.');
		self::assertSame('2026-03-01', $this->written[0]['endDateCurrent']);

		$this->instances = [$this->instanceOf(kind: TermKind::STATUTORY, end: '2026-03-01')];
		$terms = $this->service->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertTrue($terms[0]['overdue'], 'The term reads expired, visibly.');
	}//end testAnApplicationToAClosedRoundIsStillACase()

	/**
	 * A lead-time term already bound is MOVED onto the fixed date, not doubled.
	 *
	 * @return void
	 */
	public function testAnExistingStatutoryTermIsMovedRatherThanDuplicated(): void {
		$this->existing = $this->instanceOf(kind: TermKind::STATUTORY, end: '2026-10-27');
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['fixedEndDate' => '2027-03-01'])
		);

		$this->service->bindForCase(caseId: 'c1', caseTypeId: 'ct1', start: new DateTimeImmutable('2026-09-01'));

		self::assertSame([], $this->written, 'A second statutory instance would give the case two legal clocks.');
		self::assertSame('2027-03-01', $this->patched['endDateCurrent']);
		self::assertSame('2027-03-01', $this->patched['endDateCalculated']);
	}//end testAnExistingStatutoryTermIsMovedRatherThanDuplicated()

	/**
	 * A case type declaring only a lead time is left to TermijnService.
	 *
	 * @return void
	 */
	public function testALeadTimeCaseTypeIsNotTouchedHere(): void {
		$this->declarations->method('forCaseType')->willReturn(
			$this->declared(['leadTimeDays' => 56])
		);

		$this->service->bindForCase(caseId: 'c1', caseTypeId: 'ct1', start: new DateTimeImmutable('2026-09-01'));

		self::assertSame([], $this->written);
		self::assertSame([], $this->patched);
	}//end testALeadTimeCaseTypeIsNotTouchedHere()
}//end class
