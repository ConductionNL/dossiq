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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TERM-066: the declared extension length is enforced.
 *
 * @covers \OCA\Dossiq\Service\DeadlineExtensionService
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\Termijn\TermEndRoll
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
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
		);
	}//end setUp()

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
	 * The service with the real timer on the statutory fallback calendar, over
	 * one running term bound to a definition.
	 *
	 * @param array<string, mixed>|null $definitie The definition the store answers, or null.
	 * @param int                       $consumed  Extensions already used.
	 *
	 * @return DeadlineExtensionService The service.
	 */
	private function rollingService(?array $definitie, int $consumed = 0): DeadlineExtensionService {
		$store = $this->createMock(TermijnService::class);
		$store->method('getTermijnInstance')->willReturn(
			[
				'id' => 't1',
				'case' => 'c1',
				'endDateCurrent' => '2026-11-02',
				'countExtensions' => $consumed,
				'deadlineDefinition' => 'td-woo-verzoek',
			]
		);
		$store->method('getTermijnDefinitieById')->willReturn($definitie);
		$store->method('updateTermijnInstance')->willReturnCallback(
			static fn (string $id, array $patch): array => array_merge(['id' => $id], $patch)
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new DeadlineExtensionService(
			termService: $store,
			dates: $this->caseDates(),
			timerService: new TermijnTimerService(
				settingsService: $settings,
				logger: $this->createMock(LoggerInterface::class),
				dates: $this->caseDates(),
				fallbackCalendar: new WorkingDayCalculator(),
			),
		);
	}//end rollingService()

	/**
	 * REQ-WTR-002: a Sunday sent as the new end lands on Monday, and the
	 * supplied date is kept beside it.
	 *
	 * @return void
	 */
	public function testASundayEndDateIsRolledToMonday(): void {
		$moved = $this->rollingService(['id' => 'td-woo-verzoek', 'countExtensions' => 1])
			->requestExtension('t1', 'Zienswijzen van derden', '2026-11-15');

		self::assertSame('2026-11-16', $moved['endDateCurrent']);
		self::assertSame('2026-11-15', $moved['endDateBeforeRoll']);
	}//end testASundayEndDateIsRolledToMonday()

	/**
	 * REQ-WTR-003: the seeded Woo definition allows one extension, read from
	 * the definition itself, and a second is refused.
	 *
	 * @return void
	 */
	public function testTheWooDefinitionAllowsOneExtension(): void {
		$seed = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/termijnbewaking_seed_data.json'), true);
		$woo = array_values(array_filter($seed['termijnDefinities'], static fn (array $row): bool => $row['caseType'] === 'woo-verzoek'))[0];
		self::assertSame(1, $woo['countExtensions'], 'the seeded Woo term allows exactly one extension');

		$moved = $this->rollingService($woo)->requestExtension('t1', 'Zienswijzen van derden', '2026-11-16');
		self::assertSame(1, $moved['countExtensions']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('maximum aantal verlengingen');
		$this->rollingService($woo, consumed: 1)->requestExtension('t1', 'Nog een keer', '2026-11-30');
	}//end testTheWooDefinitionAllowsOneExtension()

	/**
	 * A definition that cannot be read counts as one extension, the safe value.
	 *
	 * @return void
	 */
	public function testAMissingDefinitionCountsAsOne(): void {
		$this->expectException(RuntimeException::class);
		$this->rollingService(null, consumed: 1)->requestExtension('t1', 'Nog een keer', '2026-11-30');
	}//end testAMissingDefinitionCountsAsOne()
}//end class
