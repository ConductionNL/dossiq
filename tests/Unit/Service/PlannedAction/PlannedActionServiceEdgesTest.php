<?php

/**
 * Planned Action Service Edges Test
 *
 * Pins the edges of the planned-action service the main test leaves out: a
 * person's own queue (re-checked on owner, earliest first), the configured
 * schema overriding the default slug, action types without an identifier,
 * and reads and writes that fail answering nothing while logging why.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\PlannedAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\PlannedAction;

use DateTimeImmutable;
use OCA\Dossiq\Service\PlannedAction\PlannedActionChain;
use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ObjectSearch;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge tests for PlannedActionService.
 */
#[CoversClass(PlannedActionService::class)]
#[UsesClass(PlannedActionChain::class)]
#[UsesClass(WorkingDayCalculator::class)]
#[UsesClass(ObjectSearch::class)]
final class PlannedActionServiceEdgesTest extends TestCase {
	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The settings double.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Set up a register over rows per schema, with the given config values.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows per schema.
	 * @param array<string, string>                           $config   Config values by key.
	 *
	 * @return void
	 */
	private function givenRegister(array $bySchema, array $config = ['register' => 'dossiq']): void {
		$this->objects = new class($bySchema) {
			/**
			 * Every search call: schema and filters.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $searches = [];

			/**
			 * Every save call: schema, uuid and object.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Whether reads throw.
			 *
			 * @var boolean
			 */
			public bool $readsFail = false;

			/**
			 * Whether writes throw.
			 *
			 * @var boolean
			 */
			public bool $writesFail = false;

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows per schema.
			 */
			public function __construct(private readonly array $bySchema) {
			}//end __construct()

			/**
			 * The rows of a schema, unfiltered: the service must not trust the filter.
			 *
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->searches[] = ['schema' => $schema, 'filters' => $filters];
				if ($this->readsFail === true) {
					throw new RuntimeException('register down');
				}

				return ($this->bySchema[$schema] ?? []);
			}//end searchObjectsBySlug()

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options The target and uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				if ($this->writesFail === true) {
					throw new RuntimeException('disk full');
				}

				$this->saves[] = ['schema' => $options->target->schema, 'uuid' => $options->uuid, 'object' => $object];

				return $object + ['id' => ($options->uuid ?? 'new')];
			}//end saveObject()
		};

		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? '')
		);
	}//end givenRegister()

	/**
	 * The service under test.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PlannedActionService
	 */
	private function service(?LoggerInterface $logger = null): PlannedActionService {
		return new PlannedActionService(
			settingsService: $this->settings,
			chain: new PlannedActionChain(workingDays: new WorkingDayCalculator()),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end service()

	/**
	 * A person's queue holds only their planned actions, earliest first, even when
	 * the register ignores the owner filter.
	 *
	 * @return void
	 */
	public function testOwnedByRechecksTheOwnerAndSortsByDate(): void {
		$this->givenRegister(
			bySchema: [
				'plannedAction' => [
					['id' => 'a', 'owner' => 'anna', 'plannedFor' => '2026-09-10'],
					['id' => 'b', 'owner' => 'bram', 'plannedFor' => '2026-09-01'],
					['id' => 'c', 'owner' => 'anna', 'plannedFor' => '2026-09-02', 'state' => 'planned'],
					['id' => 'd', 'owner' => 'anna', 'plannedFor' => '2026-08-01', 'state' => 'completed'],
				],
			]
		);

		$rows = $this->service()->ownedBy(userId: 'anna');

		self::assertSame(['c', 'a'], array_column($rows, 'id'));
		self::assertSame(['owner' => 'anna', '_limit' => 100], $this->objects->searches[0]['filters']);
	}//end testOwnedByRechecksTheOwnerAndSortsByDate()

	/**
	 * An empty user or case id answers nothing without reading the register.
	 *
	 * @return void
	 */
	public function testEmptyIdsReadNothing(): void {
		$this->givenRegister(bySchema: ['plannedAction' => [['id' => 'a', 'owner' => '']]]);
		$service = $this->service();

		self::assertSame([], $service->ownedBy(userId: ''));
		self::assertSame([], $service->plannedOn(caseId: ''));
		self::assertSame([], $this->objects->searches);
	}//end testEmptyIdsReadNothing()

	/**
	 * A configured schema replaces the default slug, for reads and writes alike.
	 *
	 * @return void
	 */
	public function testConfiguredSchemasAreUsed(): void {
		$this->givenRegister(
			bySchema: ['customActions' => [['id' => 'x', 'owner' => 'anna']]],
			config: [
				'register' => 'dossiq',
				PlannedActionService::ACTION_SCHEMA_KEY => 'customActions',
				PlannedActionService::TYPE_SCHEMA_KEY => 'customTypes',
			]
		);
		$service = $this->service();

		self::assertSame(['x'], array_column($service->ownedBy(userId: 'anna'), 'id'));
		self::assertNull($service->complete(action: ['id' => 'x', 'actionType' => 'unknown'], completedOn: new DateTimeImmutable('2026-09-01')));

		self::assertSame('customTypes', $this->objects->searches[1]['schema']);
		self::assertSame('customActions', $this->objects->saves[0]['schema']);
		self::assertSame('x', $this->objects->saves[0]['uuid']);
		self::assertSame('completed', $this->objects->saves[0]['object']['state']);
		self::assertStringStartsWith('2026-09-01T00:00:00', $this->objects->saves[0]['object']['completedAt']);
	}//end testConfiguredSchemasAreUsed()

	/**
	 * Action types without an identifier are dropped; types of another case type too.
	 *
	 * @return void
	 */
	public function testTypesWithoutIdentifierAreDropped(): void {
		$this->givenRegister(
			bySchema: [
				'plannedActionType' => [
					['identifier' => '', 'label' => 'Nameless'],
					['label' => 'No key at all'],
					['identifier' => 'call', 'caseType' => 'ct-2'],
					['identifier' => 'confirm', 'caseType' => 'ct-1'],
				],
			]
		);

		self::assertSame(['confirm'], array_keys($this->service()->typesFor(caseTypeId: 'ct-1')));
		self::assertSame(['call', 'confirm'], array_keys($this->service()->typesFor(caseTypeId: '')));
	}//end testTypesWithoutIdentifierAreDropped()

	/**
	 * A read that throws answers nothing and logs which schema could not be read.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndAnswersNothing(): void {
		$this->givenRegister(bySchema: []);
		$this->objects->readsFail = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Dossiq PlannedActionService: could not read plannedAction: register down', ['app' => 'dossiq']);

		self::assertSame([], $this->service(logger: $logger)->plannedOn(caseId: 'c1'));
	}//end testAFailingReadIsLoggedAndAnswersNothing()

	/**
	 * A write that throws is logged and the successor is not reported as planned.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndAnswersNull(): void {
		$this->givenRegister(
			bySchema: [
				'plannedActionType' => [
					['identifier' => 'confirm', 'successor' => 'call', 'successorOffsetWorkingDays' => 1],
					['identifier' => 'call', 'successor' => ''],
				],
			]
		);
		$this->objects->writesFail = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))
			->method('warning')
			->with('Dossiq PlannedActionService: could not write a planned action: disk full', ['app' => 'dossiq']);

		$next = $this->service(logger: $logger)->complete(
			action: ['id' => 'a1', 'actionType' => 'confirm', 'case' => 'c1'],
			completedOn: new DateTimeImmutable('2026-09-01')
		);

		self::assertNull($next);
	}//end testAFailingWriteIsLoggedAndAnswersNull()

	/**
	 * Without a configured register nothing is written.
	 *
	 * @return void
	 */
	public function testNoRegisterWritesNothing(): void {
		$this->givenRegister(
			bySchema: ['plannedActionType' => [['identifier' => 'confirm', 'successor' => '']]],
			config: []
		);

		self::assertNull($this->service()->complete(action: ['id' => 'a1', 'actionType' => 'confirm']));
		self::assertSame([], $this->objects->saves);
	}//end testNoRegisterWritesNothing()
}//end class
