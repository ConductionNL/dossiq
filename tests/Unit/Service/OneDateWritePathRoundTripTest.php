<?php

/**
 * Round trip: one date in, one stored value out, across the write paths.
 *
 * A structural test proves one code path exists. It does not prove the path
 * is correct. This submits 2028-01-31 through the write paths that take a
 * date, in four shapes that name one instant, and asserts every stored value
 * is the same string.
 *
 * Every driver below calls the production method the controller reaches. None
 * of them calls `CaseDateNormaliser` itself: a driver that did would pass even
 * if the write path bypassed the normaliser entirely, which is the defect this
 * change exists to catch.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Controller\TermijnController;
use OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard;
use OCA\Dossiq\Service\Advice\AdviceNotifier;
use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ComplaintService;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\Kcc\ContactMomentService as KccContactMomentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\PhpInputStream;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A capture-only object store for the write paths that persist a payload.
 */
interface RoundTripObjectStoreStub {
	/**
	 * Find a single object.
	 *
	 * @param int|string $id The object id.
	 * @param mixed $register The register slug.
	 * @param mixed $schema The schema slug.
	 *
	 * @return mixed
	 */
	public function find(int|string $id, mixed $register = null, mixed $schema = null): mixed;

	/**
	 * Persist an object and hand the payload back to the test.
	 *
	 * @param array<string,mixed> $object The payload.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string|null $uuid The object id on update.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array;
}//end interface

/**
 * @covers \OCA\Dossiq\Controller\TermijnController
 * @covers \OCA\Dossiq\Service\AdviceService
 * @covers \OCA\Dossiq\Service\ComplaintService
 * @covers \OCA\Dossiq\Service\Kcc\ContactMomentService
 * @covers \OCA\Dossiq\Service\WOODeadlineService
 */
class OneDateWritePathRoundTripTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The day every path is asked to store.
	 *
	 * @var string
	 */
	private const DAY = '2028-01-31';

	/**
	 * Four spellings of one instant: a bare date, a local time, a value
	 * carrying its own offset, and the same instant written in UTC.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function shapes(): array {
		return [
			'a bare date' => [self::DAY],
			'a local time with no offset' => [self::DAY . 'T00:00:00'],
			'a value carrying its own offset' => [self::DAY . 'T00:00:00+01:00'],
			'the same instant written in UTC' => ['2028-01-30T23:00:00+00:00'],
		];
	}//end shapes()

	/**
	 * Every write path that takes a submitted date stores the same string.
	 *
	 * @param string $submitted One spelling of 2028-01-31.
	 *
	 * @return void
	 *
	 * @dataProvider shapes
	 */
	public function testEveryWritePathStoresTheSameString(string $submitted): void {
		$stored = [];
		foreach ($this->writePaths() as $label => $write) {
			$stored[$label] = $write($submitted);
		}

		self::assertSame(
			expected: ['2028-01-31'],
			actual: array_values(array_unique($stored)),
			message: "The write paths disagree about what 2028-01-31 is:\n" . print_r($stored, true)
		);
	}//end testEveryWritePathStoresTheSameString()

	/**
	 * All four spellings agree, path by path.
	 *
	 * @return void
	 */
	public function testAllFourSpellingsAgreeOnEveryPath(): void {
		foreach ($this->writePaths() as $label => $write) {
			$values = [];
			foreach (self::shapes() as $shape => $arguments) {
				$values[$shape] = $write($arguments[0]);
			}

			self::assertSame(
				expected: ['2028-01-31'],
				actual: array_values(array_unique($values)),
				message: $label . ' reads the four spellings of one instant differently: ' . print_r($values, true)
			);
		}
	}//end testAllFourSpellingsAgreeOnEveryPath()

	/**
	 * A d-m-Y value is refused by every path that takes a date.
	 *
	 * @return void
	 */
	public function testAnUnreadableValueIsRefusedByEveryPath(): void {
		foreach ($this->writePaths() as $label => $write) {
			try {
				$stored = $write('31-01-2028');
				self::fail(message: $label . ' accepted a d-m-Y value and stored ' . $stored . '.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString(needle: 'ISO 8601', haystack: $exception->getMessage(), message: $label);
			}
		}
	}//end testAnUnreadableValueIsRefusedByEveryPath()

	/**
	 * A Belgian tenant reads the Belgian offset, and a UTC tenant does not.
	 *
	 * The WOO deadline is driven here because it is the shortest path from a
	 * submitted date to a stored one; `WOODeadlineService::calculate()` adds
	 * 28 days, which lands on a different calendar day under a zone that
	 * reads the submitted instant as the previous evening.
	 *
	 * @return void
	 */
	public function testTheTenantZoneDecidesTheStoredDay(): void {
		$submitted = '2028-01-31T00:00:00+01:00';

		self::assertSame(expected: '2028-02-28', actual: $this->woo(zone: 'Europe/Amsterdam')->calculate($submitted)['expectedResolution']);
		self::assertSame(expected: '2028-02-28', actual: $this->woo(zone: 'Europe/Brussels')->calculate($submitted)['expectedResolution']);
		self::assertSame(expected: '2028-02-27', actual: $this->woo(zone: 'UTC')->calculate($submitted)['expectedResolution']);
	}//end testTheTenantZoneDecidesTheStoredDay()

	/**
	 * A stamping path carries the administered offset, not a floating local time.
	 *
	 * `Kcc\ContactMomentService::create()` stamps `startedAt` when the caller
	 * sends none. It used to be `date('Y-m-d\TH:i:s')` shaped: a local time
	 * with no offset and no zone.
	 *
	 * @return void
	 */
	public function testAStampCarriesTheAdministeredOffset(): void {
		foreach (['Europe/Amsterdam', 'Europe/Brussels', 'UTC'] as $zone) {
			$expected = (new DateTimeImmutable('now', new \DateTimeZone($zone)))->format('P');
			$payload = $this->contactMomentPayload(zone: $zone, data: []);
			$stamp = (string)$payload['startedAt'];

			self::assertMatchesRegularExpression(
				pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
				string: $stamp,
				message: 'a stamp without an offset is the defect this change removes'
			);
			self::assertSame(
				expected: $expected,
				actual: substr($stamp, -6),
				message: $zone . ' stamped an offset it does not have right now'
			);
		}
	}//end testAStampCarriesTheAdministeredOffset()

	/**
	 * The write paths that take a submitted date, each driven at its own seam.
	 *
	 * `ZrcController` is absent on purpose: its date write sits inside the
	 * private eindstatus effect behind the whole ZGW mapping stack, and a
	 * driver that reached around it would be a copy of the rule rather than
	 * the rule. It is held by
	 * OneDateWritePathTest::testEveryWritePathReachesTheNormaliser() and by
	 * the "the same date through every write path stores one value" scenario
	 * in tests/e2e/one-date-write-path.spec.ts.
	 *
	 * @return array<string, callable(string): string>
	 */
	private function writePaths(): array {
		return [
			'termijn voltooiDatum (TermijnController::voltooi)' => fn (string $v): string => $this->termijnVoltooiDatum(submitted: $v),
			'termijn newEinddatum (TermijnController::verleng)' => fn (string $v): string => $this->termijnNewEinddatum(submitted: $v),
			'klacht afhandelDeadline (ComplaintService::addCalendarWeeks)' => fn (string $v): string => $this->complaints()->addCalendarWeeks(startDate: $v, weeks: 0),
			'klacht ontvangstbevestiging (ComplaintService::addWorkingDays)' => fn (string $v): string => $this->complaints()->addWorkingDays(startDate: $v, days: 0),
			'advies deadline (AdviceService::requestAdvice)' => fn (string $v): string => $this->adviceDeadline(submitted: $v),
			'woo expectedResolution (WOODeadlineService::calculate)' => fn (string $v): string => $this->wooReceiptDay(submitted: $v),
			'contactmoment startedAt (Kcc\ContactMomentService::create)' => fn (string $v): string => $this->contactMomentStartDay(submitted: $v),
		];
	}//end writePaths()

	/**
	 * Drive TermijnController::voltooi() and read the moment it hands on.
	 *
	 * @param string $submitted The submitted value.
	 *
	 * @return string The stored calendar date.
	 */
	private function termijnVoltooiDatum(string $submitted): string {
		$captured = null;
		$term = $this->createMock(originalClassName: TermijnService::class);
		$term->method('markTermijnCompleted')->willReturnCallback(
			function (string $id, ?DateTimeImmutable $voltooiDatum = null, string $link = '') use (&$captured): ?array {
				$captured = $voltooiDatum;
				return ['id' => $id];
			}
		);

		$controller = $this->termijnController(term: $term, extension: $this->createMock(originalClassName: DeadlineExtensionService::class));
		$response = PhpInputStream::with(
			(string)json_encode(['voltooiDatum' => $submitted]),
			static fn () => $controller->voltooi('instance-1')
		);

		if ($captured === null) {
			throw new InvalidArgumentException((string)(((array)$response->getData())['message'] ?? 'refused'));
		}

		return $captured->format('Y-m-d');
	}//end termijnVoltooiDatum()

	/**
	 * Drive TermijnController::verleng() and read the string it hands on.
	 *
	 * @param string $submitted The submitted value.
	 *
	 * @return string The stored calendar date.
	 */
	private function termijnNewEinddatum(string $submitted): string {
		$captured = null;
		$extension = $this->createMock(originalClassName: DeadlineExtensionService::class);
		$extension->method('requestExtension')->willReturnCallback(
			function (string $id, string $rationale, string $newEndDate, string $link = '') use (&$captured): array {
				$captured = $newEndDate;
				return ['id' => $id];
			}
		);

		$controller = $this->termijnController(term: $this->createMock(originalClassName: TermijnService::class), extension: $extension);
		$response = PhpInputStream::with(
			(string)json_encode(['rationale' => 'Awb 4:14 lid 1', 'newEinddatum' => $submitted]),
			static fn () => $controller->verleng('instance-1')
		);

		if ($captured === null) {
			throw new InvalidArgumentException((string)(((array)$response->getData())['message'] ?? 'refused'));
		}

		return $captured;
	}//end termijnNewEinddatum()

	/**
	 * A TermijnController with a signed-in caller and a body the test controls.
	 *
	 * @param TermijnService $term The termijn service.
	 * @param DeadlineExtensionService $extension The extension service.
	 *
	 * @return TermijnController
	 */
	private function termijnController(TermijnService $term, DeadlineExtensionService $extension): TermijnController {
		$request = $this->createMock(originalClassName: IRequest::class);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));

		return new TermijnController(
			appName: 'dossiq',
			request: $request,
			term: $term,
			pause: $this->createMock(originalClassName: DeadlinePauseService::class),
			extension: $extension,
			dates: $this->caseDates(),
			caseTypeSlugs: $this->createMock(originalClassName: CaseTypeSlugResolver::class),
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end termijnController()

	/**
	 * Drive AdviceService::requestAdvice() and read the stored deadline.
	 *
	 * @param string $submitted The submitted value.
	 *
	 * @return string The stored calendar date.
	 */
	private function adviceDeadline(string $submitted): string {
		$captured = [];
		$store = $this->createMock(originalClassName: RoundTripObjectStoreStub::class);
		$store->method('find')->willReturn(['id' => 'case-1']);
		$store->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null) use (&$captured): array {
				if ($schema === 'adviceRequest') {
					$captured = $object;
				}

				return $object + ['id' => 'advice-1'];
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$session = $this->createMock(originalClassName: IUserSession::class);

		$service = new AdviceService(
			settingsService: $settings,
			userSession: $session,
			logger: $logger,
			adviceDelegation: $this->createMock(originalClassName: AdviceDelegationService::class),
			repository: new AdviceRepository(settingsService: $settings, logger: $logger),
			guard: new AdviceAuthorizationGuard(
				settingsService: $settings,
				userSession: $session,
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
			),
			notifier: new AdviceNotifier(
				notificationManager: $this->createMock(originalClassName: INotificationManager::class),
				logger: $logger,
			),
			dates: $this->caseDates(),
		);

		$service->requestAdvice(
			caseId: 'case-1',
			data: ['advisor' => 'alice', 'deadline' => $submitted, 'question' => 'Mag dit?'],
			requestedBy: 'bob'
		);

		return (string)($captured['deadline'] ?? '');
	}//end adviceDeadline()

	/**
	 * Drive WOODeadlineService::calculate() and roll its 28 days back off.
	 *
	 * @param string $submitted The submitted value.
	 *
	 * @return string The receipt day the service read.
	 */
	private function wooReceiptDay(string $submitted): string {
		$resolution = $this->woo(zone: 'Europe/Amsterdam')->calculate(receiptDate: $submitted)['expectedResolution'];
		return (new DateTimeImmutable($resolution))->modify('-28 days')->format('Y-m-d');
	}//end wooReceiptDay()

	/**
	 * Drive Kcc\ContactMomentService::create() and read the stored startedAt.
	 *
	 * @param string $submitted The submitted value.
	 *
	 * @return string The stored calendar date.
	 */
	private function contactMomentStartDay(string $submitted): string {
		$payload = $this->contactMomentPayload(zone: 'Europe/Amsterdam', data: ['startedAt' => $submitted]);
		return (new DateTimeImmutable((string)$payload['startedAt']))
			->setTimezone(new \DateTimeZone('Europe/Amsterdam'))
			->format('Y-m-d');
	}//end contactMomentStartDay()

	/**
	 * The payload Kcc\ContactMomentService::create() would have persisted.
	 *
	 * @param string $zone The administered zone.
	 * @param array<string, mixed> $data The submitted fields.
	 *
	 * @return array<string, mixed>
	 */
	private function contactMomentPayload(string $zone, array $data): array {
		$captured = [];
		$store = $this->createMock(originalClassName: RoundTripObjectStoreStub::class);
		$store->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null) use (&$captured): array {
				$captured = $object;
				return $object;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'customer_contact_schema' => 'customerContact'][$key] ?? ''
		);

		$service = new KccContactMomentService(
			$settings,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->caseDates(zone: $zone),
		);

		$service->create(
			data: array_merge(['channel' => 'phone', 'direction' => 'inbound'], $data),
			agentId: 'agent-1'
		);

		return $captured;
	}//end contactMomentPayload()

	/**
	 * A ComplaintService bound to the Amsterdam tenant.
	 *
	 * @return ComplaintService
	 */
	private function complaints(): ComplaintService {
		return new ComplaintService(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			workingDays: new WorkingDayCalculator(),
			dates: $this->caseDates(),
		);
	}//end complaints()

	/**
	 * A WOODeadlineService bound to a stated tenant zone.
	 *
	 * @param string $zone The administered zone.
	 *
	 * @return WOODeadlineService
	 */
	private function woo(string $zone): WOODeadlineService {
		return new WOODeadlineService(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			notificationManager: $this->createMock(originalClassName: INotificationManager::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(zone: $zone),
		);
	}//end woo()
}//end class
