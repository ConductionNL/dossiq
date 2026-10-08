<?php

/**
 * The dashboard endpoint answers a reader only what their widgets may show.
 *
 * 🔴 THE CHECK IS ON THE READ, WHICH IS WHY THIS TEST EXISTS BESIDE THE ONES
 * ABOUT THE DECLARATION. A manifest that declares an audience and an endpoint
 * that answers everything to everyone is the ADR-004 defect one layer down:
 * the tile is gone from the page and the number is in the network tab.
 *
 * 🔴 THE CACHED PATH IS ASSERTED SEPARATELY, because it is the one that gets
 * forgotten. A payload narrowed before it was cached would be one group change
 * away from serving a reader yesterday's entitlement; a cached payload
 * returned unnarrowed hands them everything. Only narrowing on the way OUT is
 * both correct and cacheable, and both branches are driven here.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\KpiController;
use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCA\Dossiq\Service\KpiAggregationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The role check on the dashboard read.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */
class WidgetDataRoleCheckTest extends TestCase {
	/**
	 * A controller whose cache answers what the test says.
	 *
	 * @param array<string, mixed>|null $cached What the cache holds, or null.
	 * @param array<string, mixed> $computed What the aggregation computes.
	 * @param DashboardWidgetScope $scope The scope double.
	 *
	 * @return KpiController The controller.
	 */
	private function controller(?array $cached, array $computed, DashboardWidgetScope $scope): KpiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bea');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use ($cached) {
				// The version counter is asked for first and is not the payload.
				if (str_ends_with($key, '_ver') === true) {
					return 1;
				}

				return $cached;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);

		$aggregation = $this->createMock(KpiAggregationService::class);
		$aggregation->method('computeKpis')->willReturn($computed);

		return new KpiController(
			$this->createMock(IRequest::class),
			$session,
			$aggregation,
			$factory,
			$scope,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A scope that withholds one field.
	 *
	 * @return DashboardWidgetScope The double.
	 */
	private function scopeWithholdingSla(): DashboardWidgetScope {
		$scope = $this->createMock(DashboardWidgetScope::class);
		$scope->method('narrowFor')->willReturnCallback(
			static function (array $payload, string $userId): array {
				unset($payload['slaCompliance']);

				return $payload;
			}
		);

		return $scope;
	}//end scopeWithholdingSla()

	/**
	 * A freshly computed payload is narrowed before it leaves.
	 *
	 * @return void
	 */
	public function testAComputedPayloadIsNarrowedBeforeItLeaves(): void {
		$controller = $this->controller(
			cached: null,
			computed: ['openCount' => 12, 'slaCompliance' => 0.82],
			scope: $this->scopeWithholdingSla(),
		);

		$data = $controller->index()->getData();

		$this->assertSame(12, $data['openCount']);
		$this->assertArrayNotHasKey('slaCompliance', $data);
	}//end testAComputedPayloadIsNarrowedBeforeItLeaves()

	/**
	 * And so is a cached one, which is the branch that gets forgotten.
	 *
	 * @return void
	 */
	public function testACachedPayloadIsNarrowedToo(): void {
		$controller = $this->controller(
			cached: ['openCount' => 12, 'slaCompliance' => 0.82],
			computed: [],
			scope: $this->scopeWithholdingSla(),
		);

		$data = $controller->index()->getData();

		$this->assertTrue($data['cacheHit']);
		$this->assertArrayNotHasKey('slaCompliance', $data);
	}//end testACachedPayloadIsNarrowedToo()

	/**
	 * A reader entitled to everything receives everything, so the check is a
	 * narrowing and not a blanket.
	 *
	 * @return void
	 */
	public function testAnEntitledReaderReceivesTheWholePayload(): void {
		$scope = $this->createMock(DashboardWidgetScope::class);
		$scope->method('narrowFor')->willReturnArgument(0);

		$data = $this->controller(
			cached: null,
			computed: ['openCount' => 12, 'slaCompliance' => 0.82],
			scope: $scope,
		)->index()->getData();

		$this->assertSame(0.82, $data['slaCompliance']);
	}//end testAnEntitledReaderReceivesTheWholePayload()

	/**
	 * The scope is asked about THIS reader, not about a session it looks up
	 * for itself.
	 *
	 * @return void
	 */
	public function testTheScopeIsAskedAboutTheReaderThatAsked(): void {
		$scope = $this->createMock(DashboardWidgetScope::class);
		$scope->expects($this->once())
			->method('narrowFor')
			->with($this->anything(), 'bea')
			->willReturnArgument(0);

		$this->controller(cached: null, computed: ['openCount' => 1], scope: $scope)->index();
	}//end testTheScopeIsAskedAboutTheReaderThatAsked()
}//end class
