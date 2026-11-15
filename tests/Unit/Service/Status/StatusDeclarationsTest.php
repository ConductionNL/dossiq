<?php

/**
 * The status panel and the facade calls a caller makes on a status.
 *
 * `panelFor()` answers three questions about the status a case is in, each
 * from its own declaration: who the case waits on, how long it has been there
 * against the declared maximum, and which derived status it is still short
 * of. The facade's other calls hand a question to the one service that owns
 * it; what is asserted here is that the answer comes back unchanged.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\DerivedStatusService;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Service\Status\StatusDwellService;
use OCA\Dossiq\Service\Status\StatusDwellTimer;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Status\StatusDeclarations
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Status\DerivedStatusEvaluator
 * @uses \OCA\Dossiq\Service\Status\DerivedStatusService
 * @uses \OCA\Dossiq\Service\Status\StatusDeclaration
 * @uses \OCA\Dossiq\Service\Status\StatusDwellService
 * @uses \OCA\Dossiq\Service\Status\StatusDwellTimer
 * @uses \OCA\Dossiq\Service\Support\FilledValue
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class StatusDeclarationsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The status types of the case type under test, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const STATUS_TYPES = [
		'intake' => ['id' => 'intake', 'name' => 'Intake', 'order' => 1],
		'review' => [
			'id' => 'review',
			'name' => 'Under review',
			'order' => 2,
			'waitingOn' => 'applicant',
			'maximumDwell' => 3,
		],
		'decision' => [
			'id' => 'decision',
			'name' => 'Decision',
			'order' => 3,
			'derivedWhen' => [['kind' => 'fieldPresent', 'field' => 'decisionDate', 'label' => 'Decision date']],
		],
	];

	/**
	 * The lookup every facade is built over.
	 *
	 * @var StatusTypeLookup&\PHPUnit\Framework\MockObject\MockObject
	 */
	private StatusTypeLookup $lookup;

	/**
	 * The facade over the status types above.
	 *
	 * @return StatusDeclarations
	 */
	private function declarations(): StatusDeclarations {
		$statusTypes = self::STATUS_TYPES;

		$this->lookup = $this->createMock(originalClassName: StatusTypeLookup::class);
		$this->lookup->method('rowFor')->willReturnCallback(
			static fn (string $statusTypeId): array => ($statusTypes[$statusTypeId] ?? []),
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('statusTypesFor')->willReturnCallback(
			static fn (string $caseTypeId): array => match ($caseTypeId) {
				'ct' => array_values($statusTypes),
				default => [],
			},
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$declaration = new StatusDeclaration();

		return new StatusDeclarations(
			lookup: $this->lookup,
			declaration: $declaration,
			derived: new DerivedStatusService(
				caseTypes: $resolver,
				declaration: $declaration,
				evaluator: new DerivedStatusEvaluator(declaration: $declaration),
			),
			dwell: new StatusDwellService(
				calendar: new WorkingDayCalculator(),
				dates: $this->caseDates(),
			),
			timer: new StatusDwellTimer(
				settingsService: $settings,
				logger: $this->createMock(originalClassName: LoggerInterface::class),
			),
		);
	}//end declarations()

	/**
	 * The panel answers who the case waits on, its dwell against the maximum,
	 * and the derived status it is still short of.
	 *
	 * Entered on Monday 2 March, read on Friday 6 March: four working days in
	 * a status with a maximum of three is a breach.
	 *
	 * @return void
	 */
	public function testThePanelAnswersFromEachDeclaration(): void {
		$panel = $this->declarations()->panelFor(
			case: [
				'caseType' => 'ct',
				'status' => 'review',
				'currentStatusEnteredAt' => '2026-03-02T10:00:00+01:00',
			],
			now: new DateTimeImmutable('2026-03-06T12:00:00+01:00'),
		);

		self::assertSame(expected: ['waitingOn', 'dwell', 'derivation'], actual: array_keys($panel));
		self::assertSame(expected: 'applicant', actual: $panel['waitingOn']);
		self::assertSame(expected: 4, actual: $panel['dwell']['days']);
		self::assertSame(expected: 3, actual: $panel['dwell']['maximum']);
		self::assertTrue(condition: $panel['dwell']['breached']);
		self::assertSame(
			expected: (new DateTimeImmutable('2026-03-02T10:00:00+01:00'))->format('c'),
			actual: $panel['dwell']['enteredAt'],
		);
		self::assertSame(
			expected: ['statusId' => 'decision', 'name' => 'Decision', 'unmet' => ['Decision date']],
			actual: $panel['derivation'],
		);
	}//end testThePanelAnswersFromEachDeclaration()

	/**
	 * A status that declares nothing waits on us, has no maximum and no breach,
	 * and a satisfied derivation blocks nothing.
	 *
	 * @return void
	 */
	public function testAStatusThatDeclaresNothingHasTheDefaults(): void {
		$panel = $this->declarations()->panelFor(
			case: ['caseType' => 'ct', 'status' => 'intake', 'decisionDate' => '2026-03-05'],
			now: new DateTimeImmutable('2026-03-06T12:00:00+01:00'),
		);

		self::assertSame(expected: StatusDeclaration::WAITING_ON_US, actual: $panel['waitingOn']);
		self::assertSame(
			expected: ['days' => 0, 'maximum' => null, 'breached' => false, 'enteredAt' => ''],
			actual: $panel['dwell'],
		);
		self::assertNull(actual: $panel['derivation']);
	}//end testAStatusThatDeclaresNothingHasTheDefaults()

	/**
	 * Only a status that declares a derivation is a derived status, and only
	 * in the case type that holds it.
	 *
	 * @return void
	 */
	public function testOnlyADeclaringStatusIsDerived(): void {
		$declarations = $this->declarations();

		self::assertTrue(condition: $declarations->isDerivedStatus(caseTypeId: 'ct', statusTypeId: 'decision'));
		self::assertFalse(condition: $declarations->isDerivedStatus(caseTypeId: 'ct', statusTypeId: 'review'));
		self::assertFalse(condition: $declarations->isDerivedStatus(caseTypeId: 'other', statusTypeId: 'decision'));
		self::assertFalse(condition: $declarations->isDerivedStatus(caseTypeId: 'ct', statusTypeId: 'unknown'));
	}//end testOnlyADeclaringStatusIsDerived()

	/**
	 * The name comes from the lookup, asked once for that id.
	 *
	 * @return void
	 */
	public function testTheNameComesFromTheLookup(): void {
		$declarations = $this->declarations();
		$this->lookup->expects($this->once())
			->method('nameFor')
			->with('review')
			->willReturn('Under review');

		self::assertSame(expected: 'Under review', actual: $declarations->nameOf(statusTypeId: 'review'));
	}//end testTheNameComesFromTheLookup()

	/**
	 * A status change books the days spent in the status the case left and
	 * restarts the clock on the one it enters.
	 *
	 * @return void
	 */
	public function testAStatusChangeBooksTheDaysSpentAndRestartsTheClock(): void {
		$now = new DateTimeImmutable('2026-03-06T12:00:00+01:00');

		$case = $this->declarations()->applyStatusChange(
			case: [
				'status' => 'review',
				'currentStatusEnteredAt' => '2026-03-02T10:00:00+01:00',
				'currentStatusDwellDays' => 4,
				'statusDwellBreached' => true,
				'statusDwellTotals' => [['statusType' => 'intake', 'workingDays' => 2]],
			],
			toStatus: 'decision',
			now: $now,
		);

		self::assertSame(
			expected: [
				['statusType' => 'intake', 'workingDays' => 2],
				['statusType' => 'review', 'workingDays' => 4],
			],
			actual: $case['statusDwellTotals'],
		);
		self::assertSame(expected: $now->format('c'), actual: $case['currentStatusEnteredAt']);
		self::assertSame(expected: 0, actual: $case['currentStatusDwellDays']);
		self::assertFalse(condition: $case['statusDwellBreached']);
	}//end testAStatusChangeBooksTheDaysSpentAndRestartsTheClock()
}//end class
