<?php

/**
 * WOODeadlineService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\WOODeadlineService;
use OCP\Notification\IManager as INotificationManager;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService.
 *
 * WOODeadlineService resolves a single case via ObjectService::find(), which
 * is called with named arguments (id:/register:/schema:). A bare addMethods()
 * magic mock rejects named arguments with "Unknown named parameter"; this typed
 * interface lets PHPUnit generate a mock whose signature accepts them.
 */
interface WOODeadlineObjectServiceStub {
	/**
	 * Find a single object by ID (real ObjectService::find()).
	 *
	 * @param int|string $id Object UUID
	 * @param mixed ...$args Remaining find() args (extend/files/register/schema).
	 *
	 * @return mixed
	 */
	public function find(int|string $id, ...$args): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param mixed ...$args saveObject() arguments.
	 *
	 * @return mixed
	 */
	public function saveObject(...$args): mixed;
}//end interface

/**
 * Unit tests for WOODeadlineService.
 *
 * @covers \OCA\Dossiq\Service\WOODeadlineService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class WOODeadlineServiceTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private INotificationManager $notificationManager;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var WOODeadlineService
	 */
	private WOODeadlineService $service;

	/**
	 * @var TermDefinitions&\PHPUnit\Framework\MockObject\MockObject
	 */
	private TermDefinitions $definitions;

	/**
	 * @var DeadlineExtensionService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private DeadlineExtensionService $extensions;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->definitions = $this->createMock(TermDefinitions::class);
		$this->definitions->method('activeFor')->willReturnCallback(
			static fn (string $caseType): ?array => $caseType === 'woo-verzoek'
				? ['standardDurationDays' => 28, 'extensionCapacity' => 14, 'countExtensions' => 1]
				: null
		);
		$this->definitions->method('endDateFor')->willReturnCallback(
			static fn (DateTimeImmutable $start, int $days): DateTimeImmutable => $start->modify('+' . $days . ' days')
		);
		$this->definitions->method('countedEndDateFor')->willReturnCallback(
			static fn (DateTimeImmutable $start, int $days): DateTimeImmutable => $start->modify('+' . $days . ' days')
		);
		$this->extensions = $this->createMock(DeadlineExtensionService::class);

		$this->service = new WOODeadlineService(
			settingsService: $this->settingsService,
			notificationManager: $this->notificationManager,
			logger: $this->logger,
			dates: $this->caseDates(),
			definitions: $this->definitions,
			extensions: $this->extensions,
		);
	}//end setUp()

	/**
	 * Calculate returns 28-day deadline from receipt date.
	 *
	 * Acceptance criterion: case created 2026-05-01 → expectedResolution 2026-05-29.
	 *
	 * @return void
	 */
	public function testCalculateReturns28DayDeadline(): void {
		$result = $this->service->calculate('2026-05-01');

		$this->assertSame('2026-05-29', $result['expectedResolution']);
		$this->assertSame('P28D', $result['processingPeriod']);
	}//end testCalculateReturns28DayDeadline()

	/**
	 * Calculate refuses a date it cannot read, and names the field.
	 *
	 * The refusal used to say "Invalid receiptDate"; it now says which field
	 * and what a readable value looks like, because the handler reading it is
	 * the one who has to fix it.
	 *
	 * @return void
	 */
	public function testCalculateThrowsForInvalidDate(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/receiptDate/');

		$this->service->calculate('not-a-date');
	}//end testCalculateThrowsForInvalidDate()

	/**
	 * A d-m-Y value is refused rather than read as a day PHP happens to accept.
	 *
	 * @return void
	 */
	public function testCalculateRefusesADayMonthYearValue(): void {
		$this->expectException(exception: \InvalidArgumentException::class);
		$this->service->calculate('31-01-2028');
	}//end testCalculateRefusesADayMonthYearValue()

	/**
	 * Without an administered Woo term definition the term is not counted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testCalculateRefusesWithoutADefinition(): void {
		$service = new WOODeadlineService(
			settingsService: $this->settingsService,
			notificationManager: $this->notificationManager,
			logger: $this->logger,
			dates: $this->caseDates(),
		);

		try {
			$service->calculate('2026-05-01');
			self::fail('A Woo term without a definition must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('woo-term-definition-missing', $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testCalculateRefusesWithoutADefinition()

	/**
	 * The extension goes through the term engine on the statutory term.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testExtendDeadlineExtendsTheStatutoryTerm(): void {
		$this->extensions->expects(self::once())->method('extendStatutoryTermOfCase')
			->with('case-uuid-001', 'Complex request', 14, self::isInstanceOf(\Closure::class))
			->willReturn([
				'previous' => '2026-05-29',
				'instance' => ['id' => 'ti-1', 'status' => 'verlengd', 'endDateCurrent' => '2026-06-12', 'countExtensions' => 1],
			]);

		$result = $this->service->extendDeadline('case-uuid-001', 'Complex request');

		self::assertSame('2026-05-29', $result['previousDeadline']);
		self::assertSame('2026-06-12', $result['deadline']);
		self::assertSame(1, $result['countExtensions']);
		self::assertSame('ti-1', $result['termInstanceId'], 'the notice names the term it extended');
	}//end testExtendDeadlineExtendsTheStatutoryTerm()

	/**
	 * The two weeks count from the original end of the first four, unrolled.
	 *
	 * A request received on Saturday 2 May 2026 has its four weeks end on
	 * Saturday 30 May, which the Algemene termijnenwet carries to Monday
	 * 1 June. The extension counts from the Saturday (Ruben, 2026-10-09):
	 * 13 June, which the engine then rolls in turn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testTheExtensionCountsFromTheUnrolledOriginalEnd(): void {
		$base = null;
		$this->extensions->method('extendStatutoryTermOfCase')->willReturnCallback(
			static function (string $caseId, string $rationale, int $days, \Closure $baseOf) use (&$base): array {
				$base = $baseOf(['id' => 'ti-1', 'startDate' => '2026-05-02T10:00:00+02:00', 'endDateCurrent' => '2026-06-01']);
				return ['previous' => '2026-06-01', 'instance' => ['endDateCurrent' => '2026-06-15', 'countExtensions' => 1]];
			}
		);

		$this->service->extendDeadline('case-uuid-001', 'Veel documenten');

		self::assertSame('2026-05-30', $base, 'From the Saturday the four weeks end on, not the Monday it rolled to.');
	}//end testTheExtensionCountsFromTheUnrolledOriginalEnd()

	/**
	 * A second extension is refused with 409, not a 500.
	 *
	 * Acceptance criterion: second extension attempt returns error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function testExtendDeadlineRefusesASecondExtension(): void {
		$this->extensions->method('extendStatutoryTermOfCase')
			->willThrowException(new RefusedException(rule: 'extension-ceiling-reached', sentence: 'This term has had every extension it allows (1).'));

		try {
			$this->service->extendDeadline('case-uuid-001', 'Complex request');
			self::fail('A second extension must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('extension-ceiling-reached', $e->getRule());
			self::assertSame(409, $e->getStatus());
		}
	}//end testExtendDeadlineRefusesASecondExtension()

	/**
	 * ExtendDeadline throws when reason is empty.
	 *
	 * @return void
	 */
	public function testExtendDeadlineThrowsForEmptyReason(): void {
		try {
			$this->service->extendDeadline('case-uuid-001', '');
			self::fail('An extension without a reason must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('woo-extension-reason-missing', $e->getRule());
			self::assertSame(422, $e->getStatus());
		}
	}//end testExtendDeadlineThrowsForEmptyReason()

	/**
	 * CheckAndWarn returns warned=false when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testCheckAndWarnReturnsFalseWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->checkAndWarn('case-uuid-001', 'j.dejong');

		$this->assertFalse($result['warned']);
		$this->assertStringContainsString('OpenRegister', $result['reason']);
	}//end testCheckAndWarnReturnsFalseWhenORUnavailable()

	/**
	 * CheckAndWarn returns isOverdue=false and warned=false for a distant deadline.
	 *
	 * @return void
	 */
	public function testCheckAndWarnReturnsFalseForDistantDeadline(): void {
		$objectServiceMock = $this->createMock(WOODeadlineObjectServiceStub::class);
		$objectServiceMock->method('find')->willReturn([
			'id' => 'case-uuid-001',
			'deadline' => '2099-12-31',
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		$result = $this->service->checkAndWarn('case-uuid-001', 'j.dejong');

		$this->assertFalse($result['isOverdue']);
		$this->assertFalse($result['warned']);
	}//end testCheckAndWarnReturnsFalseForDistantDeadline()

}//end class
