<?php

/**
 * The declared extension period is a ceiling that refuses, and names its rule.
 *
 * `caseType.extensionPeriod` existed and nothing read it but the ZGW mapping,
 * so any case could be extended by any amount and the Awb does not allow that.
 * The refusal has to name the declared period, because a handler told only "no"
 * retries with the same number.
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
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-TERM-066: the declared extension length is enforced.
 *
 * @covers \OCA\Dossiq\Service\DeadlineExtensionService
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class DeadlineExtensionLimitTest extends TestCase {
	use BindsTermFixtures;
	use MakesCaseDateNormaliser;

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
	 * @var DeadlineExtensionService
	 */
	private DeadlineExtensionService $service;

	/**
	 * Wire the service against a store holding one running term.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);

		$this->termService->method('getTermijnInstance')->willReturn(
			[
				'id' => 't1',
				'case' => 'c1',
				'endDateCurrent' => '2026-09-01',
				'countExtensions' => 0,
				'deadlineDefinition' => '',
			]
		);
		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			static fn (string $id, array $patch): array => array_merge(['id' => $id], $patch)
		);

		$this->service = new DeadlineExtensionService(
			termService: $this->termService,
			dates: $this->caseDates(),
			timerService: null,
			declarations: $this->declarations,
			mirror: $this->mirror(),
		);
	}//end setUp()

	/**
	 * The rule for which statutory term decides a case.
	 *
	 * @return CaseDeadlineMirror The mirror; its store is never reached here.
	 */
	private function mirror(): CaseDeadlineMirror {
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new CaseDeadlineMirror(
			settingsService: $settings,
			store: new TermInstanceStore(settingsService: $settings, logger: $logger),
			logger: $logger,
		);
	}//end mirror()

	/**
	 * An extension beyond the declared period is refused with a 4xx that names
	 * the rule and the period.
	 *
	 * @return void
	 */
	public function testAnExtensionBeyondTheDeclaredPeriodIsRefused(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionPeriodDays' => 42])
		);

		try {
			$this->service->requestExtension('t1', 'Extra onderzoek nodig', '2026-10-31');
			self::fail('A sixty day extension on a forty-two day period has to be refused.');
		} catch (RefusedException $refusal) {
			self::assertSame('extension-beyond-declared-period', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
			self::assertStringContainsString('42', $refusal->getSentence());
			self::assertStringContainsString('60', $refusal->getSentence());
		}
	}//end testAnExtensionBeyondTheDeclaredPeriodIsRefused()

	/**
	 * An extension within the period moves the term.
	 *
	 * @return void
	 */
	public function testAnExtensionWithinThePeriodIsAllowed(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionPeriodDays' => 42])
		);

		$moved = $this->service->requestExtension('t1', 'Extra onderzoek nodig', '2026-10-01');

		self::assertSame('2026-10-01', $moved['endDateCurrent'], 'Thirty days is inside forty-two.');
		self::assertSame('verlengd', $moved['status']);
	}//end testAnExtensionWithinThePeriodIsAllowed()

	/**
	 * The ceiling counts the days asked for, not the days the roll adds.
	 *
	 * Fourteen days from Tuesday 1 September 2026 asks for Tuesday the 15th;
	 * a roll that carries it on is the law's, not the handler's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-pause-extension/spec.md
	 */
	public function testTheRollDoesNotCountAgainstTheCeiling(): void {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnCallback(
			static fn (DateTimeImmutable $date): DateTimeImmutable => $date->modify('+2 days')
		);
		$service = new DeadlineExtensionService(
			termService: $this->termService,
			dates: $this->caseDates(),
			timerService: $timers,
			declarations: $this->declarations,
		);
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionPeriodDays' => 14])
		);

		$moved = $service->requestExtension('t1', 'Extra onderzoek nodig', '2026-09-15');

		self::assertSame('2026-09-17', $moved['endDateCurrent']);
		self::assertSame('2026-09-15', $moved['endDateBeforeRoll']);
	}//end testTheRollDoesNotCountAgainstTheCeiling()

	/**
	 * A law that names its extension in days extends the case's statutory term.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testTheStatutoryTermOfACaseIsExtendedByDays(): void {
		$this->termService->method('instancesForCase')->willReturn([
			['id' => 't1', 'case' => 'c1', 'status' => 'lopend', 'endDateCurrent' => '2026-09-01'],
		]);
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionPeriodDays' => 14])
		);

		$extended = $this->service->extendStatutoryTermOfCase('c1', 'Veel documenten', 14);

		self::assertSame('2026-09-01', $extended['previous']);
		self::assertSame('2026-09-15', $extended['instance']['endDateCurrent']);
	}//end testTheStatutoryTermOfACaseIsExtendedByDays()

	/**
	 * A named base day replaces the current end, and the new end is still rolled.
	 *
	 * The term's four weeks ended on Saturday 30 May 2026 and rolled to Monday
	 * 1 June; fourteen days from the Saturday ask for Saturday 13 June, which
	 * the roll carries on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testTheDaysCountFromTheNamedBaseAndTheEndIsRolled(): void {
		$termService = $this->createMock(TermijnService::class);
		$termService->method('instancesForCase')->willReturn([
			['id' => 't1', 'case' => 'c1', 'status' => 'lopend', 'endDateCurrent' => '2026-06-01'],
		]);
		$termService->method('getTermijnInstance')->willReturn(
			['id' => 't1', 'case' => 'c1', 'endDateCurrent' => '2026-06-01', 'countExtensions' => 0, 'deadlineDefinition' => '']
		);
		$termService->method('updateTermijnInstance')->willReturnCallback(
			static fn (string $id, array $patch): array => array_merge(['id' => $id], $patch)
		);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnCallback(
			static fn (DateTimeImmutable $date): DateTimeImmutable => $date->format('N') === '6' ? $date->modify('+2 days') : $date
		);
		$this->declarations->method('forCase')->willReturn($this->declared(['extensionPeriodDays' => 14]));
		$service = new DeadlineExtensionService(
			termService: $termService,
			dates: $this->caseDates(),
			timerService: $timers,
			declarations: $this->declarations,
			mirror: $this->mirror(),
		);

		$extended = $service->extendStatutoryTermOfCase('c1', 'Veel documenten', 14, static fn (array $term): string => '2026-05-30');

		self::assertSame('2026-06-01', $extended['previous']);
		self::assertSame('2026-06-13', $extended['instance']['endDateBeforeRoll']);
		self::assertSame('2026-06-15', $extended['instance']['endDateCurrent']);
	}//end testTheDaysCountFromTheNamedBaseAndTheEndIsRolled()

	/**
	 * A second extension of a term that allows one is a 409.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testASecondExtensionOfACaseTermIsAConflict(): void {
		$termService = $this->createMock(TermijnService::class);
		$termService->method('instancesForCase')->willReturn([
			['id' => 't1', 'case' => 'c1', 'status' => 'verlengd', 'endDateCurrent' => '2026-09-15'],
		]);
		$termService->method('getTermijnInstance')->willReturn(
			['id' => 't1', 'case' => 'c1', 'endDateCurrent' => '2026-09-15', 'countExtensions' => 1, 'deadlineDefinition' => '']
		);
		$service = new DeadlineExtensionService(termService: $termService, dates: $this->caseDates(), mirror: $this->mirror());

		try {
			$service->extendStatutoryTermOfCase('c1', 'Veel documenten', 14);
			self::fail('A second extension must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('extension-ceiling-reached', $e->getRule());
			self::assertSame(RefusedException::STATUS_REFUSED, $e->getStatus());
		}
	}//end testASecondExtensionOfACaseTermIsAConflict()

	/**
	 * A case without a running statutory term has nothing to extend.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testACaseWithoutARunningTermRefuses(): void {
		$this->termService->method('instancesForCase')->willReturn([]);

		$this->expectException(RefusedException::class);
		$this->service->extendStatutoryTermOfCase('c1', 'Veel documenten', 14);
	}//end testACaseWithoutARunningTermRefuses()

	/**
	 * A case type that allows no extension refuses every one of them.
	 *
	 * @return void
	 */
	public function testACaseTypeThatAllowsNoExtensionRefuses(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionAllowed' => false, 'extensionPeriodDays' => 42])
		);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('extension_not_allowed');

		$this->service->requestExtension('t1', 'Extra onderzoek nodig', '2026-09-10');
	}//end testACaseTypeThatAllowsNoExtensionRefuses()

	/**
	 * The supervisor path passes the ceiling, as it passes the count ceiling.
	 *
	 * @return void
	 */
	public function testTheSupervisorOverridePassesTheCeiling(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionPeriodDays' => 42])
		);

		$moved = $this->service->requestSupervisorExtension('t1', 'Awb 4:14 lid 3', '2026-10-31');

		self::assertSame('2026-10-31', $moved['endDateCurrent']);
	}//end testTheSupervisorOverridePassesTheCeiling()

	/**
	 * A case type declaring no period lets any length through, which is what
	 * this app did before the declaration was read at all.
	 *
	 * @return void
	 */
	public function testNoDeclaredPeriodMeansNoCeiling(): void {
		$this->declarations->method('forCase')->willReturn($this->declared([]));

		$moved = $this->service->requestExtension('t1', 'Extra onderzoek nodig', '2026-12-31');

		self::assertSame('2026-12-31', $moved['endDateCurrent']);
	}//end testNoDeclaredPeriodMeansNoCeiling()
	/**
	 * A Sunday sent to termijn#verleng lands on Monday, and the Sunday is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-pause-extension/spec.md#requirement-an-extensions-end-date-is-rolled-and-reaches-the-case-req-ote-03
	 */
	public function testASundayEndDateIsRolledToMonday(): void {
		$this->declarations->method('forCase')->willReturn(
			$this->declared(['extensionPeriodDays' => 42])
		);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnCallback(
			static fn (DateTimeImmutable $date): DateTimeImmutable => ($date->format('N') === '7' ? $date->modify('+1 day') : $date)
		);
		$service = new DeadlineExtensionService(
			termService: $this->termService,
			dates: $this->caseDates(),
			timerService: $timers,
			declarations: $this->declarations,
		);

		$moved = $service->requestExtension('t1', 'Zienswijzen van derden', '2026-09-13');

		self::assertSame('2026-09-14', $moved['endDateCurrent'], 'Sunday 13 September moves to Monday.');
		self::assertSame('2026-09-13', $moved['endDateBeforeRoll'], 'The day as asked is kept beside it.');
	}//end testASundayEndDateIsRolledToMonday()

}//end class
