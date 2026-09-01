<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Parafeer
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Dossiq\Service\Parafeer\ParaferingFlowGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ParaferingFlowGateway.
 *
 * @covers \OCA\Dossiq\Service\Parafeer\ParaferingFlowGateway
 */
class ParaferingFlowGatewayTest extends TestCase {

	/**
	 * Runs the fake FlowService was asked to start.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $started = [];

	/**
	 * A flow double carrying a marker and an enabled flag.
	 *
	 * @param string  $notes   The provenance marker.
	 * @param boolean $enabled Whether the flow is enabled.
	 * @param string  $uuid    The flow uuid.
	 *
	 * @return object The flow.
	 */
	private function flow(string $notes, bool $enabled, string $uuid): object {
		return new class($notes, $enabled, $uuid) {

			/**
			 * @param string  $notes   The marker.
			 * @param boolean $enabled Enabled flag.
			 * @param string  $uuid    The uuid.
			 */
			public function __construct(
				private string $notes,
				private bool $enabled,
				private string $uuid,
			) {
			}

			/**
			 * @return string The notes.
			 */
			public function getNotes(): string {
				return $this->notes;
			}

			/**
			 * @return boolean Whether enabled.
			 */
			public function getEnabled(): bool {
				return $this->enabled;
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

		};
	}//end flow()

	/**
	 * Build the gateway over a fake FlowService holding these flows.
	 *
	 * The double's methods are copied from
	 * OCA\OpenRegister\Service\Flow\FlowService: `findAll(app, ?, ?, limit,
	 * offset)` and `run(uuid, subject, context, sync, trigger): FlowRun`.
	 *
	 * @param array<int, object> $flows The flows it holds.
	 *
	 * @return ParaferingFlowGateway The gateway.
	 */
	private function gateway(array $flows): ParaferingFlowGateway {
		$started = &$this->started;

		$flowService = new class($flows, $started) {

			/**
			 * @param array<int, object>               $flows   The flows.
			 * @param array<int, array<string, mixed>> $started Sink.
			 */
			public function __construct(private array $flows, private array &$started) {
			}

			/**
			 * @param string      $app    The app id.
			 * @param mixed       $unused One.
			 * @param mixed       $other  Two.
			 * @param integer     $limit  Page size.
			 * @param integer     $offset Page offset.
			 *
			 * @return array<int, object> The page.
			 */
			public function findAll(string $app, mixed $unused=null, mixed $other=null, int $limit=100, int $offset=0): array {
				return array_slice($this->flows, $offset, $limit);
			}

			/**
			 * @param string               $uuid    The flow uuid.
			 * @param array<string, mixed> $subject The subject.
			 * @param array<string, mixed> $context The context.
			 * @param boolean              $sync    Whether to run inline.
			 * @param string               $trigger The trigger.
			 *
			 * @return object The run.
			 */
			public function run(string $uuid, array $subject=[], array $context=[], bool $sync=false, string $trigger='manual'): object {
				$this->started[] = ['flow' => $uuid, 'subject' => $subject, 'context' => $context];

				return new class {

					/**
					 * @return string The run uuid.
					 */
					public function getUuid(): string {
						return 'run-1';
					}

				};
			}

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($flowService);

		return new ParaferingFlowGateway($container, $this->createMock(LoggerInterface::class));

	}//end gateway()

	/**
	 * 🔴 An ENABLED projected flow starts a run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnEnabledProjectedFlowStartsARun(): void {
		$gateway = $this->gateway([
			$this->flow('dossiq:endorsementRoute:route-1', true, 'flow-1'),
		]);

		$runId = $gateway->startForRoute(routeId: 'route-1', subjectId: 'voorstel-1');

		$this->assertSame('run-1', $runId);
		$this->assertCount(1, $this->started);
		$this->assertSame('flow-1', $this->started[0]['flow']);
		$this->assertSame(['id' => 'voorstel-1'], $this->started[0]['subject']);

	}//end testAnEnabledProjectedFlowStartsARun()

	/**
	 * 🔴 A DISABLED projection starts nothing.
	 *
	 * Every projection ships disabled, because the route still drives
	 * parafering and running both would ask every approver twice. This is the
	 * assertion that keeps that true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testADisabledProjectionStartsNothing(): void {
		$gateway = $this->gateway([
			$this->flow('dossiq:endorsementRoute:route-1', false, 'flow-1'),
		]);

		$runId = $gateway->startForRoute(routeId: 'route-1', subjectId: 'voorstel-1');

		$this->assertSame('', $runId);
		$this->assertSame([], $this->started);

	}//end testADisabledProjectionStartsNothing()

	/**
	 * Another route's flow is not this route's flow.
	 *
	 * Resolved by marker, so an enabled flow projected from a DIFFERENT route
	 * must not be started for this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnotherRoutesFlowIsNotStarted(): void {
		$gateway = $this->gateway([
			$this->flow('dossiq:endorsementRoute:route-2', true, 'flow-2'),
		]);

		$this->assertSame('', $gateway->startForRoute(routeId: 'route-1', subjectId: 'voorstel-1'));
		$this->assertSame([], $this->started);

	}//end testAnotherRoutesFlowIsNotStarted()

	/**
	 * A flow that cannot say whether it is enabled is treated as disabled.
	 *
	 * An unreadable flag is not permission to run. Treating "cannot tell" as
	 * "yes" would start runs alongside the route that still drives parafering.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAFlowThatCannotSayIsTreatedAsDisabled(): void {
		$mute = new class {

			/**
			 * @return string The notes.
			 */
			public function getNotes(): string {
				return 'dossiq:endorsementRoute:route-1';
			}

		};

		$this->assertSame('', $this->gateway([$mute])->startForRoute(routeId: 'route-1', subjectId: 'voorstel-1'));
		$this->assertSame([], $this->started);

	}//end testAFlowThatCannotSayIsTreatedAsDisabled()

	/**
	 * A route with no id starts nothing.
	 *
	 * @return void
	 */
	public function testARouteWithNoIdStartsNothing(): void {
		$gateway = $this->gateway([
			$this->flow('dossiq:endorsementRoute:route-1', true, 'flow-1'),
		]);

		$this->assertSame('', $gateway->startForRoute(routeId: '  ', subjectId: 'voorstel-1'));
		$this->assertSame([], $this->started);

	}//end testARouteWithNoIdStartsNothing()

	/**
	 * Without OpenRegister the gateway starts nothing and does not throw.
	 *
	 * Activation must not fail because the engine is absent: a voorstel that
	 * cannot start a run takes the route path, which is what the dual path is
	 * for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testWithoutOpenRegisterItStartsNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not found'));

		$gateway = new ParaferingFlowGateway($container, $this->createMock(LoggerInterface::class));

		$this->assertSame('', $gateway->startForRoute(routeId: 'route-1', subjectId: 'voorstel-1'));

	}//end testWithoutOpenRegisterItStartsNothing()

}//end class
