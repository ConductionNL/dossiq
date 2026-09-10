<?php

/**
 * EngineTaskGateway Unit Tests
 *
 * The dual-run seam onto OpenRegister's task engine. Three behaviours are
 * only observable here, and each one has already cost the fleet time in a
 * different app:
 *
 *  1. The payload map. `state` and `priority` take the SAME vocabularies
 *     dossiq uses, so a translation table would be wrong, not merely
 *     redundant. `case` becomes `objectUuid` because OpenRegister has no case
 *     entity. `checklist` is a JSON-encoded STRING here and real JSON there.
 *  2. A failed shadow write must NOT fail the caller. The register task
 *     already exists and the transition already succeeded.
 *  3. A namespace rename must be LOUD. A duck-typed `class_exists` lookup
 *     returns false when the class moves, and every task then silently stops
 *     reaching the engine. That is the exact failure the fleet hit when the
 *     iq rename moved namespaces and thirteen bindings went dark for two
 *     weeks.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskGateway
 */
class EngineTaskGatewayTest extends TestCase {

	/**
	 * A settings double with the flag and availability dialled in.
	 *
	 * @param string  $flag      The `task_engine_write` value.
	 * @param boolean $available Whether OpenRegister reports installed.
	 * @param object|null $service The service the container hands back.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settings(string $flag, bool $available = true, ?object $service = null): SettingsService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$settings->method('getConfigValue')->willReturn($flag);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('resolveOpenRegisterService')->willReturn($service);

		return $settings;
	}//end settings()

	/**
	 * A gateway whose service resolution is overridden, so the paths that
	 * need a LIVE engine are reachable in a runtime where OpenRegister is not
	 * autoloadable.
	 *
	 * Without this every assertion on `mirrorCreate()` passes vacuously:
	 * `class_exists()` is false here, `isEnabled()` short-circuits, and the
	 * method returns '' before it runs any of the code under test. That was
	 * not a hypothesis. The rethrow mutation below was applied and the suite
	 * stayed green until this seam existed.
	 *
	 * @param object|null $service The engine service double, or null.
	 * @param string      $flag    The `task_engine_write` value.
	 *
	 * @return EngineTaskGateway The gateway.
	 */
	private function gatewayWith(?object $service, string $flag = '1'): EngineTaskGateway {
		return new class ($this->settings($flag, true, $service), new NullLogger(), $service) extends EngineTaskGateway {
			/**
			 * @param SettingsService $settings The settings double.
			 * @param NullLogger      $logger   The logger.
			 * @param object|null     $service  The engine double.
			 */
			public function __construct(
				SettingsService $settings,
				NullLogger $logger,
				private readonly ?object $service,
			) {
				parent::__construct($settings, $logger);
			}

			/**
			 * @return object|null The injected double.
			 */
			protected function resolveService(): ?object {
				return $this->service;
			}
		};
	}//end gatewayWith()

	/**
	 * The payload map is the whole point of the class, and it is nearly 1:1.
	 *
	 * @return void
	 */
	public function testPayloadCarriesEveryMappedProperty(): void {
		$gateway = new EngineTaskGateway($this->settings('0'), new NullLogger());

		$payload = $gateway->toEnginePayload(
			task: [
				'title'          => 'Meetrapport opvragen',
				'description'    => 'Vraag het meetrapport op',
				'status'         => 'active',
				'assignee'       => 'k.dijkstra',
				'assigneeGroup'  => 'team-toezicht',
				'dueDate'        => '2026-11-02',
				'priority'       => 'high',
				'workflowStepId' => 'step-4',
				'flowRun'        => 'run-1',
				'flowNode'       => 'node-2',
			],
			caseId: 'case-9'
		);

		// The SAME words, not a translated pair. Task::STATES is the CMMN
		// vocabulary dossiq already uses and TaskPriority's canonical four are
		// dossiq's four, so any mapping table here would be a bug.
		$this->assertSame('active', $payload['state']);
		$this->assertSame('high', $payload['priority']);

		// The case IS the object. OpenRegister stores no typed case reference.
		$this->assertSame('case-9', $payload['objectUuid']);

		$this->assertSame('Meetrapport opvragen', $payload['title']);
		$this->assertSame('Vraag het meetrapport op', $payload['description']);
		$this->assertSame('k.dijkstra', $payload['assignee']);
		$this->assertSame('2026-11-02', $payload['dueAt']);
		$this->assertSame('step-4', $payload['workflowStepId']);
		$this->assertSame('run-1', $payload['runUuid']);
		$this->assertSame('node-2', $payload['nodeId']);

		// One value into a list.
		$this->assertSame(['team-toezicht'], $payload['candidateGroups']);

		// Stamped so the engine's inbox can tell whose task this is.
		$this->assertSame('dossiq', $payload['appId']);
	}//end testPayloadCarriesEveryMappedProperty()

	/**
	 * An absent property is omitted, never sent as an empty string.
	 *
	 * OpenRegister refuses an empty object property and its own message
	 * advises the null that also fails, so sending `assignee: ''` would make
	 * every unassigned task's create 400 rather than simply be unassigned.
	 *
	 * @return void
	 */
	public function testOmitsAbsentPropertiesRatherThanSendingEmptyStrings(): void {
		$gateway = new EngineTaskGateway($this->settings('0'), new NullLogger());

		$payload = $gateway->toEnginePayload(task: ['title' => 'Bare'], caseId: 'case-9');

		foreach (['assignee', 'dueAt', 'priority', 'candidateGroups', 'checklist', 'description'] as $key) {
			$this->assertArrayNotHasKey($key, $payload, sprintf('%s must be omitted, not empty', $key));
		}

		// The two that always ship.
		$this->assertSame('Bare', $payload['title']);
		$this->assertSame('available', $payload['state']);
	}//end testOmitsAbsentPropertiesRatherThanSendingEmptyStrings()

	/**
	 * The checklist widens from a JSON string to real JSON.
	 *
	 * @return void
	 */
	public function testDecodesTheJsonStringChecklist(): void {
		$gateway = new EngineTaskGateway($this->settings('0'), new NullLogger());

		$payload = $gateway->toEnginePayload(
			task: ['title' => 'T', 'checklist' => '[{"id":"a","label":"Check","checked":false}]'],
			caseId: 'case-9'
		);

		$this->assertSame([['id' => 'a', 'label' => 'Check', 'checked' => false]], $payload['checklist']);
	}//end testDecodesTheJsonStringChecklist()

	/**
	 * A checklist that cannot be decoded is dropped, not passed through.
	 *
	 * The engine validates the checklist as a typed array and refuses the
	 * whole create over a bad one, so passing a broken string through would
	 * lose the entire task rather than one field.
	 *
	 * @return void
	 */
	public function testDropsAnUndecodableChecklistRatherThanFailingTheCreate(): void {
		$gateway = new EngineTaskGateway($this->settings('0'), new NullLogger());

		foreach (['not json at all', '', '[]', '{}'] as $bad) {
			$payload = $gateway->toEnginePayload(task: ['title' => 'T', 'checklist' => $bad], caseId: 'c');
			$this->assertArrayNotHasKey('checklist', $payload, sprintf('checklist %s must be dropped', var_export($bad, true)));
		}
	}//end testDropsAnUndecodableChecklistRatherThanFailingTheCreate()

	/**
	 * The flag off means nothing is written and nothing is resolved.
	 *
	 * @return void
	 */
	public function testWritesNothingWhileTheFlagIsOff(): void {
		$service = new class {
			public bool $called = false;

			/**
			 * @param array<string, mixed> $data  Payload.
			 * @param string|null          $actor Actor.
			 *
			 * @return object
			 */
			public function create(array $data, ?string $actor): object {
				$this->called = true;
				return new class {
					/** @return string */
					public function getUuid(): string {
						return 'should-not-happen';
					}
				};
			}
		};

		$gateway = $this->gatewayWith($service, '0');

		$this->assertSame('', $gateway->mirrorCreate(task: ['title' => 'T'], caseId: 'c', actor: null));
		$this->assertFalse($service->called, 'the engine must not be touched while the flag is off');
	}//end testWritesNothingWhileTheFlagIsOff()

	/**
	 * A failing engine write returns '' and does NOT throw.
	 *
	 * This is the dual-run rule: the register task already exists and the
	 * transition already succeeded. Letting this throw would turn a migration
	 * into an outage.
	 *
	 * @return void
	 */
	public function testSwallowsAnEngineFailureSoTheCallerSurvives(): void {
		$service = new class {
			/**
			 * @param array<string, mixed> $data  Payload.
			 * @param string|null          $actor Actor.
			 *
			 * @return object
			 */
			public function create(array $data, ?string $actor): object {
				throw new RuntimeException('engine exploded');
			}
		};

		$gateway = $this->gatewayWith($service);

		// Reached the engine, the engine threw, and the caller still gets a
		// value rather than an exception.
		$this->assertSame('', $gateway->mirrorCreate(task: ['title' => 'T'], caseId: 'c', actor: null));
	}//end testSwallowsAnEngineFailureSoTheCallerSurvives()

	/**
	 * An absent OpenRegister is reported as configuration, not as a defect.
	 *
	 * @return void
	 */
	public function testReportsAnAbsentOpenRegisterAsNotInstalled(): void {
		$gateway = new EngineTaskGateway($this->settings('1', false), new NullLogger());

		$this->assertFalse($gateway->isEnabled());
		$this->assertStringContainsString('not installed', $gateway->unavailableReason());
	}//end testReportsAnAbsentOpenRegisterAsNotInstalled()

	/**
	 * 🔴 A namespace rename must be LOUD, and this is the assertion that makes
	 * it so.
	 *
	 * `class_exists` on a moved class returns false, `isEnabled()` returns
	 * false, and every task silently stops reaching the engine. On an
	 * instance where OpenRegister IS installed, that can only mean the class
	 * moved, and the reason must say so rather than blaming configuration.
	 *
	 * The fleet has already paid for this once: the iq rename moved
	 * namespaces and thirteen cross-app bindings went dark for two weeks
	 * because `class_exists` returning false never raises.
	 *
	 * @return void
	 */
	public function testNamesTheClassWhenOpenRegisterIsPresentButTheServiceIsNot(): void {
		// Installed, but the container hands back nothing and the class is
		// absent from this test runtime (OpenRegister is not autoloadable in
		// dossiq's unit suite), which is exactly the rename shape.
		$gateway = new EngineTaskGateway($this->settings('1', true, null), new NullLogger());

		$this->assertFalse($gateway->isEnabled());

		$reason = $gateway->unavailableReason();
		$this->assertStringContainsString('OCA\OpenRegister\Service\Task\TaskService', $reason);
		$this->assertStringContainsString('NOT reaching the engine', $reason);
	}//end testNamesTheClassWhenOpenRegisterIsPresentButTheServiceIsNot()

	/**
	 * With the flag on and the service present, the uuid comes back.
	 *
	 * @return void
	 */
	public function testReturnsTheEngineUuidOnSuccess(): void {
		$service = new class {
			/** @var array<string, mixed> */
			public array $seen = [];

			/**
			 * @param array<string, mixed> $data  Payload.
			 * @param string|null          $actor Actor.
			 *
			 * @return object
			 */
			public function create(array $data, ?string $actor): object {
				$this->seen = $data;
				return new class {
					/** @return string */
					public function getUuid(): string {
						return 'engine-uuid-1';
					}
				};
			}
		};

		$gateway = $this->gatewayWith($service);

		$this->assertSame(
			'engine-uuid-1',
			$gateway->mirrorCreate(task: ['title' => 'T', 'status' => 'available'], caseId: 'case-1', actor: 'admin')
		);

		// And the payload the engine actually received is the mapped one.
		$this->assertSame('T', $service->seen['title']);
		$this->assertSame('case-1', $service->seen['objectUuid']);
		$this->assertSame('available', $service->seen['state']);
		$this->assertSame('dossiq', $service->seen['appId']);
	}//end testReturnsTheEngineUuidOnSuccess()
}//end class
