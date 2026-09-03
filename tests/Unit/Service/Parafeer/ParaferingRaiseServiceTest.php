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

use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\Parafeer\ParaferingRaiseService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the raise that replaced the local activation.
 *
 * The two assertions that matter are the REFUSALS. A voorstel that cannot be
 * routed, and an install whose decision app will not hold the route, both used
 * to be survivable — the old activation carried on with an empty snapshot, or
 * treated the decision app as optional. With no local runtime left, either
 * would park a voorstel in parafering with no engine anywhere to move it. The
 * raise fails closed on both.
 */
class ParaferingRaiseServiceTest extends TestCase {

	/**
	 * Rows the fake register holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Objects saved back.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over canned collaborators.
	 *
	 * @param array<string, mixed>|null $route The route the directory resolves, or null.
	 * @param bool $available Whether the decision app is available.
	 * @param bool $handles Whether holdRoute returns an id or throws.
	 *
	 * @return ParaferingRaiseService The service.
	 */
	private function service(?array $route, bool $available = true, bool $handles = true): ParaferingRaiseService {
		$objectService = new class ($this->rows, $this->saved) {
			/**
			 * @param array<string, array<string, mixed>> $rows  The stored rows.
			 * @param array<int, array<string, mixed>>    $saved The save log.
			 */
			public function __construct(private array $rows, private array &$saved) {
			}

			/**
			 * The slug-path read the SearchesObjects trait uses for slug ids.
			 *
			 * Pinned to the REAL OpenRegister contract: a top-level
			 * `'id' => …` / `'uuid' => …` filter addresses a schema
			 * property no schema declares and silently matches ZERO rows.
			 * The old fake resolved it, so the dead voorstel lookup in
			 * ParaferingRaiseService stayed green for months.
			 *
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				unset($filters['@self'], $filters['_limit'], $filters['_offset']);
				if (array_key_exists('id', $filters) === true || array_key_exists('uuid', $filters) === true) {
					return [];
				}

				return array_values($this->rows);
			}

			/**
			 * The real get-by-id path — ObjectService::find() argument
			 * order, DoesNotExistException on a miss, entity-shaped return.
			 *
			 * @param int|string $id The object id.
			 * @param array|null $_extend Relations to expand (ignored).
			 * @param bool $files Include file metadata (ignored).
			 * @param string|int|null $register The register slug (ignored).
			 * @param string|int|null $schema The schema slug (ignored).
			 *
			 * @return object The stored row, entity-shaped.
			 *
			 * @throws \OCP\AppFramework\Db\DoesNotExistException When the id is unknown.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			): object {
				foreach ($this->rows as $row) {
					if (($row['id'] ?? null) === (string)$id) {
						// The store returns ONLY declared properties, and the
						// declared set is read from the SHIPPED register JSON:
						// a fake feeding a `caseType` the voorstel schema does
						// not declare kept a dead read green for months.
						$row = ShippedRegisterSchema::asStored(row: $row, slug: 'proposal');

						return new class ($row) implements \JsonSerializable {
							/**
							 * @param array<string, mixed> $row The row.
							 */
							public function __construct(private readonly array $row) {
							}

							/**
							 * @return array<string, mixed> The row.
							 */
							public function jsonSerialize(): array {
								return $this->row;
							}
						};
					}
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('Object ' . $id . ' does not exist');
			}

			/**
			 * Real ObjectService::saveObject() signature: `$object` FIRST.
			 * A caller still using the retired `($register, $schema,
			 * $object)` order fatals here as it does live.
			 *
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
				$this->saved[] = $object;

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
		// The case type comes from the voorstel's linked case — the voorstel
		// schema declares no caseType, so a regression back to reading one off
		// the voorstel derives '' and the ct-1-keyed route lookups below miss.
		$routes->method('caseTypeOfVoorstel')->willReturnCallback(
			function (array $voorstel): string {
				$this->assertArrayNotHasKey(
					'caseType',
					$voorstel,
					'The stored voorstel carries no caseType; the schema does not declare one.'
				);

				return ((string)($voorstel['case'] ?? '') === 'c-1') ? 'ct-1' : '';
			}
		);
		$routes->method('localRoute')->willReturnCallback(
			static fn (string $caseTypeId): ?array => ($caseTypeId === 'ct-1') ? $route : null
		);
		$routes->method('stepsForCaseType')->willReturnCallback(
			static fn (string $caseTypeId): array => ($caseTypeId === 'ct-1' && $route !== null) ? ($route['steps'] ?? []) : []
		);

		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('isAvailable')->willReturn($available);
		if ($handles === true) {
			$delegation->method('holdRoute')->willReturn('ar-1');
		} else {
			$delegation->method('holdRoute')->willThrowException(new RuntimeException('the decision app did not handle the command'));
		}

		return new ParaferingRaiseService(
			$settings,
			$routes,
			$delegation,
			new ObjectArrayNormalizer(),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A route with steps: the voorstel enters parafering and records the id.
	 *
	 * @return void
	 */
	public function testARoutedVoorstelEntersParaferingAndRecordsTheRouteId(): void {
		$this->rows = ['v-1' => ['id' => 'v-1', 'case' => 'c-1', 'status' => 'draft']];
		$route = ['id' => 'route-1', 'steps' => [['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]];

		$result = $this->service(route: $route)->activate('v-1');

		$this->assertSame('in_parafering', $result['status']);
		$this->assertSame(1, $result['currentStep']);
		$this->assertSame('ar-1', $result['approvalRouteId']);
		$this->assertIsString(
			$result['routeSnapshot'],
			'The schema declares routeSnapshot as a JSON-encoded string, so that is what is written.'
		);
		$this->assertSame($route['steps'], json_decode($result['routeSnapshot'], true));
	}

	/**
	 * A voorstel with no linked case cannot resolve a case type and is refused.
	 *
	 * @return void
	 */
	public function testAVoorstelWithoutACaseIsRefused(): void {
		$this->rows = ['v-1' => ['id' => 'v-1', 'status' => 'draft']];
		$route = ['id' => 'route-1', 'steps' => [['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]];

		try {
			$this->service(route: $route)->activate('v-1');
			$this->fail('A voorstel without a derivable case type must be refused.');
		} catch (RuntimeException) {
			$this->assertSame([], $this->saved, 'A refused raise writes nothing.');
		}
	}

	/**
	 * A voorstel whose case type has no route is refused, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnroutableVoorstelIsRefused(): void {
		$this->rows = ['v-1' => ['id' => 'v-1', 'case' => 'c-1', 'status' => 'draft']];

		try {
			$this->service(route: null)->activate('v-1');
			$this->fail('An unroutable voorstel must be refused.');
		} catch (RuntimeException) {
			$this->assertSame([], $this->saved, 'A refused raise writes nothing.');
		}
	}

	/**
	 * The raise fails closed when the decision app cannot hold the route.
	 *
	 * There is no local chain to fall back to, so a voorstel is never parked
	 * in parafering with no engine to finish it.
	 *
	 * @return void
	 */
	public function testTheRaiseFailsClosedWhenTheDecisionAppRefuses(): void {
		$this->rows = ['v-1' => ['id' => 'v-1', 'case' => 'c-1', 'status' => 'draft']];
		$route = ['id' => 'route-1', 'steps' => [['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]];

		try {
			$this->service(route: $route, handles: false)->activate('v-1');
			$this->fail('The raise must fail closed when the decision app refuses.');
		} catch (RuntimeException) {
			$this->assertSame([], $this->saved, 'A voorstel is not put into parafering with no engine to move it.');
		}
	}
}
