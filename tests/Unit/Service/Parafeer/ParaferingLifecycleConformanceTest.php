<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Dossiq\Service\Parafeer\ParafeerVoorstelRepository;
use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\Parafeer\ParaferingConclusionService;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\Parafeer\ParaferingRaiseService;
use OCA\Dossiq\Service\ParaferingNotificationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every status the parafering writers write must be a transition the SHIPPED
 * proposal lifecycle declares.
 *
 * The defect class is "writer vs its own declared lifecycle": the conclusion
 * recorder wrote `geaccordeerd` on a voorstel in `in_parafering` while the
 * shipped lifecycle only declared `in_parafering → ter_accordering → accord`.
 * OpenRegister's lifecycle validation refused the write, the recorder's catch
 * swallowed the refusal as a warning, and every concluded voorstel stayed in
 * `in_parafering` forever — under a green suite, because no test ever opened
 * the register JSON.
 *
 * These tests DRIVE the writers over fakes, collect every (from → to) status
 * pair they actually produce, and check each pair against the lifecycle edges
 * parsed from the shipped register configuration (base + fragments). Lifecycle
 * drift on either side — a writer growing a new status, or the register losing
 * an edge — reds this file.
 *
 * @covers \OCA\Dossiq\Service\Parafeer\ParaferingConclusionService
 * @covers \OCA\Dossiq\Service\Parafeer\ParaferingRaiseService
 * @uses \OCA\Dossiq\Service\Support\ObjectArrayNormalizer
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses \OCA\Dossiq\Event\ParafeerTransitionEvent
 */
class ParaferingLifecycleConformanceTest extends TestCase {

	/**
	 * Everything saved back, as [schema, object] entries.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * A store fake that logs saves and holds no prior parafeeracties.
	 *
	 * @return object The fake.
	 */
	private function objectService(): object {
		return new class ($this->saved) {
			/**
			 * @param array<int, array{schema: string, object: array<string, mixed>}> $saved The save log.
			 */
			public function __construct(private array &$saved) {
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> No stored rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param array|null $extend Relations to expand (ignored).
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid to update.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			): array {
				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];

				return $object;
			}
		};
	}

	/**
	 * The conclusion recorder over fakes, answering the given voorstel.
	 *
	 * @param array<string, mixed> $voorstel The voorstel the repository resolves.
	 *
	 * @return ParaferingConclusionService The recorder.
	 */
	private function recorder(array $voorstel): ParaferingConclusionService {
		$repository = $this->createMock(ParafeerVoorstelRepository::class);
		$repository->method('resolveSchemas')->willReturn(['dossiq', 'proposal', 'parafeeractie']);
		$repository->method('requireObjectService')->willReturn($this->objectService());
		$repository->method('findVoorstel')->willReturn($voorstel);

		return new ParaferingConclusionService(
			$repository,
			$this->createMock(ParaferingNotificationService::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IEventDispatcher::class),
			new ObjectArrayNormalizer(),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The proposal statuses written to the store, in save order.
	 *
	 * @return array<int, string> The statuses.
	 */
	private function writtenProposalStatuses(): array {
		$statuses = [];
		foreach ($this->saved as $entry) {
			if ($entry['schema'] === 'proposal' && isset($entry['object']['status']) === true) {
				$statuses[] = (string)$entry['object']['status'];
			}
		}

		return $statuses;
	}

	/**
	 * Assert one written transition is an edge the shipped lifecycle declares.
	 *
	 * @param string $from The status the voorstel held.
	 * @param string $to The status the writer wrote.
	 *
	 * @return void
	 */
	private function assertDeclaredEdge(string $from, string $to): void {
		$this->assertContains(
			$to,
			ShippedRegisterSchema::enumValues(slug: 'proposal', property: 'status'),
			'The writer wrote a status the proposal schema does not enumerate: ' . $to
		);
		$this->assertContains(
			$from . '>' . $to,
			ShippedRegisterSchema::lifecycleEdges(slug: 'proposal'),
			sprintf(
				'The writer moves a voorstel %s -> %s, but the SHIPPED proposal lifecycle declares no such '
				. 'transition. OpenRegister refuses the write, the refusal is swallowed as a warning, and the '
				. 'voorstel is stuck in %s forever.',
				$from,
				$to,
				$from
			)
		);
	}

	/**
	 * Every final status the conclusion writes, from every status a voorstel
	 * can hold when a conclusion arrives, is a declared lifecycle edge.
	 *
	 * `in_parafering` is where the raise puts a voorstel; `ter_accordering` is
	 * where the retired local runtime left the in-flight ones the upgrade
	 * re-raises. Both outcomes are driven for both.
	 *
	 * @return void
	 */
	public function testEveryConcludedStatusWriteIsADeclaredLifecycleEdge(): void {
		$conclusions = [
			['outcome' => 'concluded', 'actions' => [['step' => 1, 'actor' => 'alice', 'action' => 'approved']]],
			['outcome' => 'returned', 'actions' => [['step' => 1, 'actor' => 'alice', 'action' => 'returned', 'comment' => 'nee']]],
		];

		foreach (['in_parafering', 'ter_accordering'] as $from) {
			foreach ($conclusions as $conclusion) {
				$this->saved = [];
				$this->recorder(voorstel: ['id' => 'v-1', 'status' => $from])->recordConclusion(
					'v-1',
					$conclusion['outcome'],
					'alice',
					$conclusion['actions'],
				);

				$statuses = $this->writtenProposalStatuses();
				$this->assertNotSame([], $statuses, 'The conclusion must write a final status.');
				foreach ($statuses as $to) {
					$this->assertDeclaredEdge(from: $from, to: $to);
				}
			}
		}
	}

	/**
	 * The direct conclude edge is declared: a concluded chain IS the accord
	 * decision, so `in_parafering → geaccordeerd` must ship. This is the exact
	 * edge whose absence stranded every concluded voorstel.
	 *
	 * @return void
	 */
	public function testTheDirectConcludeEdgeIsDeclared(): void {
		$this->assertContains(
			'in_parafering>geaccordeerd',
			ShippedRegisterSchema::lifecycleEdges(slug: 'proposal'),
			'The shipped proposal lifecycle must declare the concludeParafering edge; without it every '
			. 'conclusion write is refused and swallowed.'
		);
	}

	/**
	 * The raise's status write is a declared lifecycle edge too.
	 *
	 * @return void
	 */
	public function testTheRaisedStatusWriteIsADeclaredLifecycleEdge(): void {
		$objectService = new class ($this->saved) {
			/**
			 * @param array<int, array{schema: string, object: array<string, mixed>}> $saved The save log.
			 */
			public function __construct(private array &$saved) {
			}

			/**
			 * @param int|string $id The object id.
			 * @param array|null $_extend Relations to expand (ignored).
			 * @param bool $files Include file metadata (ignored).
			 * @param string|int|null $register The register slug (ignored).
			 * @param string|int|null $schema The schema slug (ignored).
			 *
			 * @return array<string, mixed> The stored voorstel.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			): array {
				return ShippedRegisterSchema::asStored(
					row: ['id' => (string)$id, 'case' => 'c-1', 'status' => 'draft'],
					slug: 'proposal'
				);
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param array|null $extend Relations to expand (ignored).
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid to update.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			): array {
				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'voorstel_schema' => 'proposal',
				default => $default,
			}
		);

		$routes = $this->createMock(ParafeerrouteDirectory::class);
		$routes->method('caseTypeOfVoorstel')->willReturn('ct-1');
		$route = ['id' => 'route-1', 'steps' => [['order' => 1, 'actor' => 'alice']]];
		$routes->method('localRoute')->willReturn($route);
		$routes->method('stepsForCaseType')->willReturn($route['steps']);

		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('holdRoute')->willReturn('ar-1');

		$service = new ParaferingRaiseService(
			$settings,
			$routes,
			$delegation,
			new ObjectArrayNormalizer(),
			$this->createMock(LoggerInterface::class),
		);

		$this->saved = [];
		$service->activate('v-1');

		$statuses = $this->writtenProposalStatuses();
		$this->assertNotSame([], $statuses, 'The raise must write the parafering status.');
		foreach ($statuses as $to) {
			$this->assertDeclaredEdge(from: 'draft', to: $to);
		}
	}
}
