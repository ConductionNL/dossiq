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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineFollower;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
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
 * A store that drops every case key the real case schema does not declare,
 * as OpenRegister does.
 */
class UndeclaredCaseKeyDroppingStore extends FakeTermijnStore {
	/**
	 * @param array<string, mixed> $caseSchema The real case schema.
	 */
	public function __construct(private array $caseSchema) {
	}

	/**
	 * Save, dropping undeclared case keys.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param array|null           $extend   Ignored.
	 * @param string|int|null      $register Register.
	 * @param string|int|null      $schema   Schema.
	 * @param string|null          $uuid     Uuid.
	 *
	 * @return FakeStoredObject The stored object.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
	): FakeStoredObject {
		if ((string)$schema === 'case') {
			$object = array_intersect_key($object, ($this->caseSchema['properties'] ?? []) + ['id' => true]);
		}

		return parent::saveObject($object, $extend, $register, $schema, $uuid);
	}
}

/**
 * Unit tests for WOODeadlineService.
 *
 * @covers \OCA\Dossiq\Service\WOODeadlineService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\DeadlineExtensionService
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\TermKind
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Termijn\CaseDeadlineFollower
 * @uses \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermEndRoll
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WOODeadlineService(
			$this->settingsService,
			$this->notificationManager,
			$this->logger,
			$this->caseDates(),
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
	 * A Woo service over the real term engine: TermijnService on a store, the
	 * real extension service on the statutory fallback calendar, and the case
	 * deadline follower. The store holds one Woo case, its statutory term
	 * (end 2026-11-02, no extensions) and the seeded Woo definition.
	 *
	 * @param FakeTermijnStore   $store  The store.
	 * @param CaseDeadlineMirror $mirror The mirror the follower fills.
	 *
	 * @return WOODeadlineService The service.
	 */
	private function wooOverTheEngine(FakeTermijnStore $store, CaseDeadlineMirror $mirror): WOODeadlineService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_definitie_schema' => 'deadlineDefinition',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
				default => '',
			}
		);

		$seed = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/termijnbewaking_seed_data.json'), true);
		foreach ($seed['termijnDefinities'] as $definition) {
			if ($definition['caseType'] === 'woo-verzoek') {
				$store->seed('deadlineDefinition', $definition);
			}
		}

		$store->seed('case', ['id' => 'case-woo', 'title' => 'Woo-verzoek over de ringweg', 'deadline' => '2026-11-02']);
		$store->seed('deadlineInstance', [
			'id' => 'ti-woo',
			'case' => 'case-woo',
			'kind' => 'statutory',
			'deadlineDefinition' => 'td-woo-verzoek',
			'startDate' => '2026-10-05T09:00:00+02:00',
			'endDateCalculated' => '2026-11-02',
			'endDateCurrent' => '2026-11-02',
			'status' => 'lopend',
			'countExtensions' => 0,
		]);

		$logger = $this->createMock(LoggerInterface::class);
		$timer = new TermijnTimerService(
			settingsService: $settings,
			logger: $logger,
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
		);
		$terms = new TermijnService(
			$settings,
			$logger,
			follower: new CaseDeadlineFollower($settings, $mirror, $logger),
		);

		return new WOODeadlineService(
			$settings,
			$this->notificationManager,
			$logger,
			$this->caseDates(),
			$timer,
			$terms,
			new DeadlineExtensionService(termService: $terms, dates: $this->caseDates(), timerService: $timer),
		);
	}//end wooOverTheEngine()

	/**
	 * REQ-WTR-003, "One extension, rolled": the term instance moves by two
	 * weeks, the case follows it, and the reason is the verleng rationale.
	 *
	 * @return void
	 */
	public function testTheExtensionMovesTheTermInstanceAndTheCase(): void {
		$store = new FakeTermijnStore();
		$mirror = new CaseDeadlineMirror();

		$result = $this->wooOverTheEngine($store, $mirror)->extendDeadline('case-woo', 'Zienswijzen van derden');

		$this->assertSame(
			[
				'caseId' => 'case-woo',
				'previousDeadline' => '2026-11-02',
				'deadline' => '2026-11-16',
				'extensionReason' => 'Zienswijzen van derden',
				'countExtensions' => 1,
			],
			$result
		);

		$instance = $store->get('deadlineInstance', 'ti-woo');
		$this->assertSame('2026-11-16', $instance['endDateCurrent']);
		$this->assertSame(1, $instance['countExtensions']);

		$events = array_values(array_filter(
			$store->findObjects('dossiq', 'termijnGebeurtenis'),
			static fn (array $row): bool => ($row['type'] ?? '') === 'verleng'
		));
		$this->assertCount(1, $events);
		$this->assertSame('Zienswijzen van derden', $events[0]['rationale'] ?? null);

		// The case was saved to follow the term; the listener writes this date.
		$this->assertSame('2026-11-16', $mirror->take('case-woo')['deadline'] ?? null);
		$this->assertArrayNotHasKey('expectedResolution', (array)$store->get('case', 'case-woo'));
	}//end testTheExtensionMovesTheTermInstanceAndTheCase()

	/**
	 * REQ-WTR-003, "The cap holds when the case schema drops undeclared keys":
	 * the store drops every case key the real case schema does not declare,
	 * and the second extension is still refused, because the cap is read from
	 * the term instance.
	 *
	 * @return void
	 */
	public function testTheCapHoldsWhenTheCaseDropsUndeclaredKeys(): void {
		$store = new UndeclaredCaseKeyDroppingStore((new RealSchemaValidator())->schemas['case']);
		$service = $this->wooOverTheEngine($store, new CaseDeadlineMirror());

		$service->extendDeadline('case-woo', 'Zienswijzen van derden');

		try {
			$service->extendDeadline('case-woo', 'Nog meer tijd nodig');
			$this->fail('A second Woo extension has to be refused.');
		} catch (RefusedException $refusal) {
			$this->assertSame('woo-one-extension', $refusal->getRule());
			$this->assertSame(RefusedException::STATUS_REFUSED, $refusal->getStatus());
			$this->assertStringContainsString('Woo art. 4.4 lid 2', $refusal->getSentence());
		}

		$this->assertSame('2026-11-16', $store->get('deadlineInstance', 'ti-woo')['endDateCurrent'], 'unchanged after the refusal');
		foreach (['expectedResolution', 'deadlineVerlengd', 'verdagingReden'] as $undeclared) {
			$this->assertArrayNotHasKey($undeclared, (array)$store->get('case', 'case-woo'));
		}
	}//end testTheCapHoldsWhenTheCaseDropsUndeclaredKeys()

	/**
	 * Without the term engine an extension is refused, not written onto the case.
	 *
	 * @return void
	 */
	public function testWithoutTheTermEngineTheExtensionIsRefused(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/term engine is not available/');

		$this->service->extendDeadline('case-uuid-001', 'Complex request');
	}//end testWithoutTheTermEngineTheExtensionIsRefused()

	/**
	 * ExtendDeadline throws when reason is empty.
	 *
	 * @return void
	 */
	public function testExtendDeadlineThrowsForEmptyReason(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/reason is required/i');

		$this->service->extendDeadline('case-uuid-001', '');
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
			// The one declared deadline; the undeclared `expectedResolution` is no longer read.
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
