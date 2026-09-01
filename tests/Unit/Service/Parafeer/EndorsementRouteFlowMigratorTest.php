<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Workflow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Parafeer\EndorsementRouteFlowMigrator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the projection of a workflow definition onto an OpenRegister flow.
 *
 * Two properties carry this migration and both are easy to get quietly wrong.
 *
 * The flow must arrive DISABLED: the definition still drives cases through
 * StatusTransitionService, so an enabled projection would move the same case
 * twice on every status change — and would look like it was working.
 *
 * Statuses must travel by NAME. A statusType uuid is minted per installation,
 * so a projection carrying ids is portable nowhere; `dossiq.setStatus` takes a
 * name for exactly that reason.
 */
class EndorsementRouteFlowMigratorTest extends TestCase {

	/**
	 * Routes the fake register returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $routes = [];

	/**
	 * Flow documents the migrator wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Flows the fake FlowService already holds.
	 *
	 * @var array<int, object>
	 */
	private array $existingFlows = [];

	/**
	 * Whether the fake FlowService refuses to save.
	 *
	 * @var boolean
	 */
	private bool $saveThrows = false;

	/**
	 * Build the migrator.
	 *
	 * @param boolean $withFlowService Whether OpenRegister exposes FlowService.
	 * @param boolean $withStore       Whether OpenRegister is available at all.
	 *
	 * @return EndorsementRouteFlowMigrator The migrator.
	 */
	private function migrator(bool $withFlowService = true, bool $withStore = true): EndorsementRouteFlowMigrator {
		$routes = &$this->routes;
		$written = &$this->written;
		$existing = &$this->existingFlows;
		$throws = &$this->saveThrows;

		$objectService = new class($routes) {
			/**
			 * @param array<int, array<string, mixed>> $routes Routes.
			 */
			public function __construct(private array &$routes) {
			}

			/**
			 * @param IUser    $user      The acting user.
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->routes;
			}
		};

		$flowService = new class($written, $existing, $throws) {
			/**
			 * @param array<int, array<string, mixed>> $written  Writes.
			 * @param array<int, object>               $existing Existing flows.
			 * @param boolean                          $throws   Whether to refuse.
			 */
			public function __construct(private array &$written, private array &$existing, private bool &$throws) {
			}

			/**
			 * @param string      $app      The app id.
			 * @param string|null $a        Unused.
			 * @param string|null $b        Unused.
			 * @param integer     $limit    Page size.
			 * @param integer     $offset   Page offset.
			 *
			 * @return array<int, object> The page.
			 */
			public function findAll(string $app, ?string $a = null, ?string $b = null, int $limit = 100, int $offset = 0): array {
				if ($offset > 0) {
					return [];
				}

				return $this->existing;
			}

			/**
			 * @param array<string, mixed> $document The flow document.
			 * @param string|null          $uuid     The uuid, or null to create.
			 *
			 * @return object The stored flow.
			 */
			public function save(array $document, ?string $uuid = null): object {
				if ($this->throws === true) {
					throw new RuntimeException('flow refused');
				}

				$this->written[] = ($document + ['_uuid' => $uuid]);

				return new class($uuid) {
					/**
					 * @param string|null $uuid The uuid.
					 */
					public function __construct(private ?string $uuid) {
					}

					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return ($this->uuid ?? 'flow-new');
					}
				};
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withStore === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturn('configured');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($flowService, $withFlowService): object {
				if ($withFlowService === false) {
					throw new RuntimeException('not registered');
				}

				return $flowService;
			}
		);

		return new EndorsementRouteFlowMigrator($settings, $container, $this->createMock(LoggerInterface::class));

	}//end migrator()


	/**
	 * The acting user.
	 *
	 * @return IUser The user.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		return $user;

	}//end user()

	/**
	 * A route with two ordered steps.
	 *
	 * @param string $id The route id.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(string $id = 'r-1'): array {
		return [
			'id' => $id,
			'name' => 'Parafeerroute vergunning',
			'steps' => json_encode([
				['order' => 2, 'type' => 'accordering', 'actor' => 'teamlead', 'label' => 'Akkoord teamlead'],
				['order' => 1, 'type' => 'parafering', 'actor' => 'behandelaar', 'label' => 'Paraaf behandelaar'],
			]),
		];

	}//end route()

	/**
	 * Each step becomes an askPerson node, in `order`, ending in a decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-are-flows/specs/approval-routes-are-flows/spec.md
	 */
	public function testStepsBecomeAskPersonNodesInOrder(): void {
		$this->routes = [$this->route()];

		$this->migrator()->migrate($this->user(), false);

		$this->assertCount(1, $this->written);
		$nodes = $this->written[0]['nodes'];

		$this->assertSame(
			['dossiq.askParaaf', 'dossiq.askParaaf', 'dossiq.requestDecision'],
			array_column($nodes, 'type'),
			'a route is paraaf steps followed by the decision they gate'
		);

		// Declared out of order in the fixture on purpose: the flow must follow
		// `order`, not the order the steps happen to be stored in.
		$this->assertSame('Paraaf behandelaar', $nodes[0]['config']['question']);
		$this->assertSame('behandelaar', $nodes[0]['config']['actor']);
		$this->assertSame('Akkoord teamlead', $nodes[1]['config']['question']);
		$this->assertSame('teamlead', $nodes[1]['config']['actor']);

	}//end testStepsBecomeAskPersonNodesInOrder()

	/**
	 * The steps are chained, so step two waits for step one.
	 *
	 * @return void
	 */
	public function testTheStepsAreChainedInSequence(): void {
		$this->routes = [$this->route()];

		$this->migrator()->migrate($this->user(), false);

		$edges = $this->written[0]['edges'];
		$this->assertSame([['step-1'], ['step-2']], array_column($edges, 'from'));
		$this->assertSame([['step-2'], ['decision']], array_column($edges, 'to'));

	}//end testTheStepsAreChainedInSequence()

	/**
	 * The projection arrives disabled.
	 *
	 * The route still drives parafering through BesluitvormingParafeerService.
	 * An enabled projection would ask every approver a second time.
	 *
	 * @return void
	 */
	public function testTheProjectionArrivesDisabled(): void {
		$this->routes = [$this->route()];

		$this->migrator()->migrate($this->user(), false);

		$this->assertFalse($this->written[0]['enabled']);

	}//end testTheProjectionArrivesDisabled()

	/**
	 * A step with no actor refuses the whole route.
	 *
	 * `dossiq.askPerson` rejects an empty assignee, so projecting the rest
	 * would quietly drop a sign-off somebody is expecting.
	 *
	 * @return void
	 */
	public function testAStepWithNoActorRefusesTheRoute(): void {
		$this->routes = [
			[
				'id' => 'r-2',
				'name' => 'Kapotte route',
				'steps' => json_encode([
					['order' => 1, 'type' => 'parafering', 'actor' => 'behandelaar'],
					['order' => 2, 'type' => 'accordering', 'actor' => ''],
				]),
			],
		];

		$summary = $this->migrator()->migrate($this->user(), false);

		$this->assertSame([], $this->written, 'nothing may be written for a route that cannot be honoured');
		$this->assertSame(1, $summary['skipped']);

	}//end testAStepWithNoActorRefusesTheRoute()

	/**
	 * A re-run updates the flow it already made, by marker.
	 *
	 * @return void
	 */
	public function testARerunUpdatesRatherThanDuplicating(): void {
		$this->routes = [$this->route()];
		$this->existingFlows = [
			new class {
				/**
				 * @return string The notes.
				 */
				public function getNotes(): string {
					return (EndorsementRouteFlowMigrator::MARKER_PREFIX . 'r-1');
				}

				/**
				 * @return string The uuid.
				 */
				public function getUuid(): string {
					return 'flow-existing';
				}
			},
		];

		$summary = $this->migrator()->migrate($this->user(), false);

		$this->assertSame(1, $summary['updated']);
		$this->assertSame(0, $summary['created']);
		$this->assertSame('flow-existing', $this->written[0]['_uuid']);

	}//end testARerunUpdatesRatherThanDuplicating()

	/**
	 * A dry run reports without writing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->routes = [$this->route()];

		$summary = $this->migrator()->migrate($this->user(), true);

		$this->assertSame([], $this->written);
		$this->assertSame(1, $summary['created']);

	}//end testADryRunWritesNothing()

	/**
	 * Without OpenRegister's FlowService, nothing is projected and it says so.
	 *
	 * @return void
	 */
	public function testWithoutFlowServiceNothingIsProjected(): void {
		$this->routes = [$this->route()];

		$summary = $this->migrator(false)->migrate($this->user(), false);

		$this->assertSame([], $this->written);
		$this->assertArrayHasKey('note', $summary);

	}//end testWithoutFlowServiceNothingIsProjected()

	/**
	 * The projection runs as the named user.
	 *
	 * A flow inherits its owner and organisation from whoever created it, and
	 * keeps them. Projecting outside `runAs` would hand every migrated route to
	 * whichever identity the CLI happened to carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-are-flows/specs/approval-routes-are-flows/spec.md
	 */
	public function testWithoutRunAsNothingIsProjected(): void {
		$this->routes = [$this->route()];

		$settings = $this->createMock(SettingsService::class);
		// An object service that cannot scope to a user: no runAs().
		$settings->method('getObjectService')->willReturn(
			new class {
				/**
				 * @param string               $register The register.
				 * @param string               $schema   The schema.
				 * @param array<string, mixed> $filters  The filters.
				 *
				 * @return array<int, array<string, mixed>> The rows.
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
					return [];
				}
			}
		);
		$settings->method('getConfigValue')->willReturn('configured');

		$migrator = new EndorsementRouteFlowMigrator(
			$settings,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$summary = $migrator->migrate($this->user(), false);

		$this->assertSame([], $this->written);
		$this->assertArrayHasKey('note', $summary, 'it must say why it did nothing rather than report a clean run');

	}//end testWithoutRunAsNothingIsProjected()

	/**
	 * The flow service is resolved by its real, fully-qualified id.
	 *
	 * The container mock in every other test here answers to ANY id, so it
	 * cannot tell a correct lookup from a wrong one. Extracting the shared
	 * projection trait dropped the `\Flow\` segment from this string and no
	 * test went red; the migration simply reported "OpenRegister exposes no
	 * FlowService" and exited 0, which reads as "nothing to do".
	 *
	 * This asserts the id itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-are-flows/specs/approval-routes-are-flows/spec.md
	 */
	public function testItAsksTheContainerForTheRealFlowServiceId(): void {
		$asked = [];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(
			new class {
				/**
				 * @param IUser    $user      The user.
				 * @param callable $operation The operation.
				 *
				 * @return mixed The result.
				 */
				public function runAs(IUser $user, callable $operation): mixed {
					return $operation();
				}

				/**
				 * @param string               $register The register.
				 * @param string               $schema   The schema.
				 * @param array<string, mixed> $filters  The filters.
				 *
				 * @return array<int, array<string, mixed>> The rows.
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
					return [];
				}
			}
		);
		$settings->method('getConfigValue')->willReturn('configured');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$asked): object {
				$asked[] = $id;

				throw new RuntimeException('not registered');
			}
		);

		$migrator = new EndorsementRouteFlowMigrator($settings, $container, $this->createMock(LoggerInterface::class));
		$migrator->migrate($this->user(), true);

		$this->assertSame(
			['OCA\\OpenRegister\\Service\\Flow\\FlowService'],
			$asked,
			'the id must carry the Flow namespace segment; without it the lookup fails silently'
		);

	}//end testItAsksTheContainerForTheRealFlowServiceId()

	/**
	 * The paraaf carries the ROUTE's step number, and the actor's type.
	 *
	 * A parafeeractie's `step` is read back by the parafering screens, so it
	 * has to mean what the route meant rather than where the node happens to
	 * sit in the chain. The two differ the moment a route numbers its steps
	 * from anything but one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testTheParaafCarriesTheRoutesOwnStepNumber(): void {
		$this->routes = [
			[
				'id' => 'r-9',
				'name' => 'Route met eigen nummering',
				'steps' => json_encode([
					['order' => 10, 'type' => 'parafering', 'actor' => 'behandelaar', 'actorType' => 'user'],
					['order' => 20, 'type' => 'accordering', 'actor' => 'directie', 'actorType' => 'group'],
				]),
			],
		];

		$this->migrator()->migrate($this->user(), false);

		$nodes = $this->written[0]['nodes'];
		$this->assertSame([10, 20], [$nodes[0]['config']['step'], $nodes[1]['config']['step']]);
		$this->assertSame(['user', 'group'], [$nodes[0]['config']['actorType'], $nodes[1]['config']['actorType']]);

	}//end testTheParaafCarriesTheRoutesOwnStepNumber()

}//end class
