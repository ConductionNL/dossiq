<?php

/**
 * CaseFlowActions behaviour against a flow store.
 *
 * Pins which flows a case may start (those its case type declares and that
 * resolve), and the four writes that plan a follow-up: the flow, the series
 * source, publishing, and switching it on — plus what a failure answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of CaseFlowActions with OpenRegister's flow services present.
 *
 * @covers \OCA\Dossiq\Service\Flow\CaseFlowActions
 * @uses \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 * @uses \OCA\Dossiq\Service\Flow\PlannedSeriesCalendar
 * @uses \OCA\Dossiq\Service\Flow\PlannedSeriesLedger
 */
class CaseFlowActionsBehaviourTest extends TestCase {

	/**
	 * Calls the flow store received, as [method, args].
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	public array $calls = [];

	/**
	 * Flow uuids the flow store does not know.
	 *
	 * @var string[]
	 */
	public array $unknownFlows = [];

	/**
	 * Whether the flow store fails on save.
	 *
	 * @var bool
	 */
	public bool $saveFails = false;

	/**
	 * Whether publishing fails.
	 *
	 * @var bool
	 */
	public bool $publishFails = false;

	/**
	 * Messages logged, as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array}>
	 */
	private array $logged = [];

	/**
	 * A flow row.
	 *
	 * @param string      $uuid        The uuid.
	 * @param string      $name        The name.
	 * @param string|null $description The description.
	 *
	 * @return object
	 */
	public static function flow(string $uuid, string $name = 'Flow', ?string $description = null): object {
		return new class ($uuid, $name, $description) {
			public function __construct(private string $uuid, private string $name, private ?string $description) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}

			public function getName(): string {
				return $this->name;
			}

			public function getDescription(): ?string {
				return $this->description;
			}
		};
	}//end flow()

	/**
	 * The service under test.
	 *
	 * @param array<string,array|null> $objects     Objects by id the object service finds.
	 * @param bool                     $withFlows   Whether the flow store is present.
	 * @param bool                     $withVersion Whether the version service is present.
	 *
	 * @return CaseFlowActions
	 */
	private function service(array $objects = [], bool $withFlows = true, bool $withVersion = true): CaseFlowActions {
		$test = $this;
		$flowStore = new class ($test) {
			public function __construct(private CaseFlowActionsBehaviourTest $test) {
			}

			public function find(string $uuid): object {
				$this->test->calls[] = ['find', [$uuid]];
				if (in_array($uuid, $this->test->unknownFlows, true) === true) {
					throw new RuntimeException('no flow ' . $uuid);
				}

				return CaseFlowActionsBehaviourTest::flow($uuid, 'Flow ' . $uuid, ($uuid === 'f-1' ? 'Eerste' : null));
			}

			public function save(array $data, ?string $uuid = null): object {
				$this->test->calls[] = ['save', [array_keys($data), $uuid]];
				if ($this->test->saveFails === true) {
					throw new RuntimeException('disk full');
				}

				return CaseFlowActionsBehaviourTest::flow($uuid ?? 'flow-new');
			}
		};
		$versions = new class ($test) {
			public function __construct(private CaseFlowActionsBehaviourTest $test) {
			}

			public function publish(object $flow): void {
				$this->test->calls[] = ['publish', [$flow->getUuid()]];
				if ($this->test->publishFails === true) {
					throw new RuntimeException('no version');
				}
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($flowStore, $versions, $withFlows, $withVersion): object {
				if ($name === 'OCA\OpenRegister\Service\Flow\FlowService' && $withFlows === true) {
					return $flowStore;
				}

				if ($name === 'OCA\OpenRegister\Service\Flow\FlowVersionService' && $withVersion === true) {
					return $versions;
				}

				throw new RuntimeException('not registered: ' . $name);
			}
		);

		$objectService = new class ($objects) {
			public function __construct(private array $objects) {
			}

			public function find(string $id, $register = null, $schema = null): ?array {
				if (array_key_exists($id, $this->objects) === false) {
					throw new RuntimeException('not found');
				}

				return $this->objects[$id];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => '4', 'case_schema' => '10', 'case_type_schema' => '11'][$key] ?? '')
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$document = new PlannedFollowUpDocument();

		return new CaseFlowActions(
			$container,
			$settings,
			$document,
			new PlannedSeriesLedger($container, $settings, $document, $this->createMock(IAppConfig::class), $logger),
			$logger
		);
	}//end service()

	/**
	 * The flows the case type declares (as ids or {id}) are listed, blank and
	 * unresolvable ones skipped.
	 *
	 * @return void
	 */
	public function testStartableFlowsAreThoseTheCaseTypeDeclares(): void {
		$this->unknownFlows = ['f-gone'];
		$service = $this->service(
			[
				'case-1' => ['caseType' => 'type-1'],
				'type-1' => ['startableFlows' => ['f-1', ['id' => 'f-2'], '  ', ['name' => 'no id'], 'f-gone']],
			]
		);

		$result = $service->startableFlows('case-1');

		$this->assertSame(
			[
				'results' => [
					['id' => 'f-1', 'title' => 'Flow f-1', 'description' => 'Eerste'],
					['id' => 'f-2', 'title' => 'Flow f-2', 'description' => ''],
				],
				'total' => 2,
			],
			$result
		);
		$this->assertSame('CaseFlowActions: a startable flow did not resolve', $this->logged[0][1]);
		$this->assertSame('f-gone', $this->logged[0][2]['flow']);
	}//end testStartableFlowsAreThoseTheCaseTypeDeclares()

	/**
	 * A case type without a usable declaration, or one that cannot be read,
	 * offers nothing and does not touch the flow store.
	 *
	 * @return void
	 */
	public function testNoDeclarationOffersNothing(): void {
		$cases = [
			['case-1' => ['caseType' => 'type-1'], 'type-1' => ['startableFlows' => 'f-1']],
			['case-1' => ['caseType' => 'type-1'], 'type-1' => []],
			['case-1' => ['caseType' => 'type-missing']],
			['case-1' => []],
		];

		foreach ($cases as $objects) {
			$this->assertSame(['results' => [], 'total' => 0], $this->service($objects)->startableFlows('case-1'));
		}

		$this->assertSame([], $this->calls);
		$this->assertSame(
			['results' => [], 'total' => 0],
			$this->service(['case-1' => ['caseType' => 'type-1'], 'type-1' => ['startableFlows' => ['f-1']]], withFlows: false)->startableFlows('case-1')
		);
	}//end testNoDeclarationOffersNothing()

	/**
	 * An unknown case is refused as case_not_found.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_found');

		$this->service([])->startableFlows('case-x');
	}//end testAnUnknownCaseIsRefused()

	/**
	 * Planning writes the flow, then its series source, publishes it, and only
	 * then switches it on; the answer describes what was planned.
	 *
	 * @return void
	 */
	public function testPlanWritesPublishesAndEnables(): void {
		$result = $this->service()->plan('case-1', 'type-1', '2026-10-15', 'Controle', 'behandelaar');

		$this->assertSame('flow-new', $result['id']);
		$this->assertSame('Controle', $result['title']);
		$this->assertSame('2026-10-15', $result['date']);
		$this->assertSame('type-1', $result['caseType']);
		$this->assertSame(PlannedFollowUpDocument::RECURRENCE_NONE, $result['recurrence']);
		$this->assertSame(
			[
				'save',
				['save', [['nodes'], 'flow-new']],
				['find', ['flow-new']],
				['publish', ['flow-new']],
				['save', [['enabled'], 'flow-new']],
			],
			[$this->calls[0][0], $this->calls[1], $this->calls[2], $this->calls[3], $this->calls[4]]
		);
		$this->assertNull($this->calls[0][1][1]);
		$this->assertSame([], $this->logged);
	}//end testPlanWritesPublishesAndEnables()

	/**
	 * A failed publish is logged and the flow is still switched on; without a
	 * version service publishing is skipped.
	 *
	 * @return void
	 */
	public function testAPublishFailureDoesNotStopThePlan(): void {
		$this->publishFails = true;
		$this->service()->plan('case-1', 'type-1', '2026-10-15', 'Controle', 'behandelaar');

		$this->assertSame(['save', [['enabled'], 'flow-new']], end($this->calls));
		$this->assertSame('warning', $this->logged[0][0]);
		$this->assertSame('CaseFlowActions: could not publish the planned follow-up', $this->logged[0][1]);

		$this->calls = [];
		$this->service(withVersion: false)->plan('case-1', 'type-1', '2026-10-15', 'Controle', 'behandelaar');
		$this->assertNotContains('publish', array_column($this->calls, 0));
	}//end testAPublishFailureDoesNotStopThePlan()

	/**
	 * A failed write is logged with the case and refused as plan_failed.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsPlanFailed(): void {
		$this->saveFails = true;

		try {
			$this->service()->plan('case-1', 'type-1', '2026-10-15', 'Controle', 'behandelaar');
			$this->fail('plan_failed expected');
		} catch (RuntimeException $e) {
			$this->assertSame('plan_failed', $e->getMessage());
		}

		$this->assertSame(
			['error', 'CaseFlowActions: could not write the planned follow-up', ['caseId' => 'case-1', 'exception' => 'disk full']],
			end($this->logged)
		);
	}//end testAFailedWriteIsPlanFailed()
}//end class
