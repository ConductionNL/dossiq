<?php

/**
 * A planned term definition binds the planned clock (REQ-TERM-066).
 *
 * The English demo types and the melding openbare ruimte have a service norm
 * and no statutory term. The norm is shipped as a `kind: planned` definition,
 * and until this change nothing read one: the planned end was bound from the
 * case type's own `plannedLeadTime` only, so the norm armed nothing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\CaseTermsService
 * @uses \OCA\Dossiq\Service\TermKind
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 */
class CaseTermsServicePlannedDefinitionTest extends TestCase {
	use BindsTermFixtures;

	/**
	 * The declarations.
	 *
	 * @var TermDeclarationReader&MockObject
	 */
	private TermDeclarationReader $declarations;

	/**
	 * The modes the calendar was asked to count in, per call.
	 *
	 * @var array<int, string>
	 */
	private array $modes = [];

	/**
	 * The service under test.
	 *
	 * @var CaseTermsService
	 */
	private CaseTermsService $service;

	/**
	 * Wire the service against a store that remembers what it was handed.
	 *
	 * The calendar adds calendar days whatever mode it is asked for, so the
	 * dates stay readable, and records the mode, which is what is asserted.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnArgument(0);

		$calendar = $this->createMock(WorkingDayRoll::class);
		$calendar->method('endAfterOrCalendarDays')->willReturnCallback(
			function (DateTimeImmutable $start, int $days, string $mode): DateTimeImmutable {
				$this->modes[] = $mode;
				return $start->add(new DateInterval('P' . $days . 'D'));
			}
		);

		$this->written = [];
		$termService->method('saveTermInstance')->willReturnCallback(
			function (array $instance): array {
				$instance['id'] = ('written-' . count($this->written));
				$this->written[] = $instance;

				return $instance;
			}
		);

		$this->service = new CaseTermsService(
			termService: $termService,
			declarations: $this->declarations,
			timers: $timers,
			logger: new NullLogger(),
			calendar: $calendar,
		);
	}//end setUp()

	/**
	 * The shipped service norm for a building permit: 56 calendar days.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function plannedDefinition(): array {
		return [
			'id' => 'td-building-permit',
			'caseType' => 'building-permit',
			'kind' => TermKind::PLANNED,
			'standardDurationDays' => 56,
			'countingMode' => 'calendarDays',
		];
	}//end plannedDefinition()

	/**
	 * A case type with no planned lead time binds the definition's norm.
	 *
	 * @return void
	 */
	public function testAPlannedDefinitionBindsThePlannedClock(): void {
		$this->declarations->method('forCaseType')->willReturn($this->declared([]));

		$this->service->bindForCase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			start: new DateTimeImmutable('2026-09-01'),
			plannedDefinition: self::plannedDefinition(),
		);

		self::assertCount(1, $this->written, 'One clock: the planned end, and no statutory term.');
		self::assertSame(TermKind::PLANNED, $this->written[0]['kind']);
		self::assertSame('2026-10-27', $this->written[0]['endDateCurrent']);
		self::assertSame('td-building-permit', $this->written[0]['deadlineDefinition']);
		self::assertSame(['calendarDays'], $this->modes, 'The norm counts in the mode its definition declares.');
	}//end testAPlannedDefinitionBindsThePlannedClock()

	/**
	 * The case type's own planned lead time wins over a definition.
	 *
	 * @return void
	 */
	public function testTheCaseTypesOwnPlannedLeadTimeWins(): void {
		$this->declarations->method('forCaseType')->willReturn($this->declared(['plannedLeadTimeDays' => 10]));

		$this->service->bindForCase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			start: new DateTimeImmutable('2026-09-01'),
			plannedDefinition: self::plannedDefinition(),
		);

		self::assertCount(1, $this->written);
		self::assertSame('2026-09-11', $this->written[0]['endDateCurrent']);
		self::assertArrayNotHasKey('deadlineDefinition', $this->written[0]);
		self::assertSame(['workingDays'], $this->modes, 'The case type\'s own lead time keeps counting working days.');
	}//end testTheCaseTypesOwnPlannedLeadTimeWins()

	/**
	 * Without either, no planned clock is bound.
	 *
	 * @return void
	 */
	public function testNoNormBindsNoPlannedClock(): void {
		$this->declarations->method('forCaseType')->willReturn($this->declared([]));

		$this->service->bindForCase(caseId: 'c1', caseTypeId: 'ct1', start: new DateTimeImmutable('2026-09-01'));

		self::assertSame([], $this->written);
	}//end testNoNormBindsNoPlannedClock()
}//end class
