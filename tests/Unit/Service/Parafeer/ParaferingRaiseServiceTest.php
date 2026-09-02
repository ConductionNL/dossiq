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
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$id = ($filters['id'] ?? null);

				return array_values(array_filter(
					$this->rows,
					static fn (array $r): bool => ($id === null || ($r['id'] ?? null) === $id)
				));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
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
		$routes->method('localRoute')->willReturn($route);
		$routes->method('stepsForCaseType')->willReturn($route === null ? [] : ($route['steps'] ?? []));

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
		$this->rows = ['v-1' => ['id' => 'v-1', 'caseType' => 'ct-1', 'status' => 'draft']];
		$route = ['id' => 'route-1', 'steps' => [['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]];

		$result = $this->service(route: $route)->activate('v-1');

		$this->assertSame('in_parafering', $result['status']);
		$this->assertSame(1, $result['currentStep']);
		$this->assertSame('ar-1', $result['approvalRouteId']);
	}

	/**
	 * A voorstel whose case type has no route is refused, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnroutableVoorstelIsRefused(): void {
		$this->rows = ['v-1' => ['id' => 'v-1', 'caseType' => 'ct-none', 'status' => 'draft']];

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
		$this->rows = ['v-1' => ['id' => 'v-1', 'caseType' => 'ct-1', 'status' => 'draft']];
		$route = ['id' => 'route-1', 'steps' => [['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]];

		try {
			$this->service(route: $route, handles: false)->activate('v-1');
			$this->fail('The raise must fail closed when the decision app refuses.');
		} catch (RuntimeException) {
			$this->assertSame([], $this->saved, 'A voorstel is not put into parafering with no engine to move it.');
		}
	}
}
