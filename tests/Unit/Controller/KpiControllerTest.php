<?php

/**
 * KpiController Unit Tests
 *
 * Tests for the Procest KpiController that exposes pre-aggregated
 * dashboard KPI data via a cached JSON endpoint.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\KpiController;
use OCA\Procest\Service\KpiAggregationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the KpiController class.
 *
 * @covers \OCA\Procest\Controller\KpiController
 */
class KpiControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The mocked KPI aggregation service.
	 *
	 * @var KpiAggregationService|MockObject
	 */
	private KpiAggregationService $kpiAggregation;

	/**
	 * The mocked cache factory.
	 *
	 * @var ICacheFactory|MockObject
	 */
	private ICacheFactory $cacheFactory;

	/**
	 * The mocked local cache.
	 *
	 * @var ICache|MockObject
	 */
	private ICache $cache;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var KpiController
	 */
	private KpiController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->kpiAggregation = $this->createMock(KpiAggregationService::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->cacheFactory->method('createLocal')->willReturn($this->cache);

		$this->controller = new KpiController(
			$this->request,
			$this->userSession,
			$this->kpiAggregation,
			$this->cacheFactory,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Build a mock authenticated user with the given UID.
	 *
	 * @param string $uid The user ID
	 *
	 * @return IUser|MockObject
	 */
	private function mockUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end mockUser()

	/**
	 * Get the expected KPI data fixture.
	 *
	 * @return array<string, mixed> KPI fixture data
	 */
	private function kpiFixture(): array {
		return [
			'openCount' => 10,
			'newToday' => 2,
			'overdueCount' => 1,
			'completedCount' => 5,
			'taskCount' => 3,
			'tasksDueToday' => 1,
			'statusBreakdown' => [['status' => 'open', 'count' => 10]],
			'typeBreakdown' => [['type' => 'aanvraag', 'count' => 8]],
			'avgProcessingDays' => 6.0,
		];
	}//end kpiFixture()

	/**
	 * Test that index() returns 401 when user is not authenticated.
	 *
	 * @return void
	 */
	public function testIndexReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->index();

		$this->assertSame(401, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
	}//end testIndexReturns401WhenNotAuthenticated()

	/**
	 * Test that index() returns 200 with JSON data on cache miss.
	 *
	 * @return void
	 */
	public function testIndexReturnsFreshDataOnCacheMiss(): void {
		$user = $this->mockUser('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// Cache miss: version returns null, data returns null.
		$this->cache->method('get')->willReturn(null);

		$this->kpiAggregation->method('computeKpis')->willReturn($this->kpiFixture());

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();

		$this->assertArrayHasKey('openCount', $data);
		$this->assertArrayHasKey('computedAt', $data);
		$this->assertFalse($data['cacheHit']);
	}//end testIndexReturnsFreshDataOnCacheMiss()

	/**
	 * Test that index() returns cacheHit: true on cache hit.
	 *
	 * @return void
	 */
	public function testIndexReturnsCacheHitOnSecondRequest(): void {
		$user = $this->mockUser('bob');
		$this->userSession->method('getUser')->willReturn($user);

		$cachedData = array_merge(
			$this->kpiFixture(),
			['computedAt' => '2026-05-09T10:00:00+00:00', 'cacheHit' => false]
		);

		// version key returns 1, data key returns cached payload.
		$this->cache->method('get')->willReturnCallback(
			function (string $key) use ($cachedData): mixed {
				if (str_ends_with($key, '_ver')) {
					return 1;
				}

				return $cachedData;
			}
		);

		$this->kpiAggregation->expects($this->never())->method('computeKpis');

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['cacheHit']);
	}//end testIndexReturnsCacheHitOnSecondRequest()

	/**
	 * Test that index() response contains all required JSON fields.
	 *
	 * @return void
	 */
	public function testIndexResponseContainsAllRequiredFields(): void {
		$user = $this->mockUser('carol');
		$this->userSession->method('getUser')->willReturn($user);
		$this->cache->method('get')->willReturn(null);
		$this->kpiAggregation->method('computeKpis')->willReturn($this->kpiFixture());

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertArrayHasKey('openCount', $data);
		$this->assertArrayHasKey('newToday', $data);
		$this->assertArrayHasKey('overdueCount', $data);
		$this->assertArrayHasKey('completedCount', $data);
		$this->assertArrayHasKey('taskCount', $data);
		$this->assertArrayHasKey('tasksDueToday', $data);
		$this->assertArrayHasKey('statusBreakdown', $data);
		$this->assertArrayHasKey('typeBreakdown', $data);
		$this->assertArrayHasKey('avgProcessingDays', $data);
		$this->assertArrayHasKey('computedAt', $data);
		$this->assertArrayHasKey('cacheHit', $data);
	}//end testIndexResponseContainsAllRequiredFields()

	/**
	 * Test that computeKpis is called with the authenticated user ID.
	 *
	 * @return void
	 */
	public function testComputeKpisCalledWithCorrectUserId(): void {
		$user = $this->mockUser('diana');
		$this->userSession->method('getUser')->willReturn($user);
		$this->cache->method('get')->willReturn(null);

		$this->kpiAggregation->expects($this->once())
			->method('computeKpis')
			->with('diana')
			->willReturn($this->kpiFixture());

		$this->controller->index();
	}//end testComputeKpisCalledWithCorrectUserId()

	/**
	 * Test that data is stored in cache after a cache miss.
	 *
	 * @return void
	 */
	public function testDataIsStoredInCacheAfterMiss(): void {
		$user = $this->mockUser('eve');
		$this->userSession->method('getUser')->willReturn($user);
		$this->cache->method('get')->willReturn(null);
		$this->kpiAggregation->method('computeKpis')->willReturn($this->kpiFixture());

		// The cache set method should be called once to store data.
		$this->cache->expects($this->atLeastOnce())->method('set');

		$this->controller->index();
	}//end testDataIsStoredInCacheAfterMiss()

	/**
	 * Test that a cache store exception does not break the response.
	 *
	 * @return void
	 */
	public function testCacheStoreFailureDoesNotBreakResponse(): void {
		$user = $this->mockUser('frank');
		$this->userSession->method('getUser')->willReturn($user);
		$this->cache->method('get')->willReturn(null);
		$this->cache->method('set')->willThrowException(new \Exception('Cache full'));
		$this->kpiAggregation->method('computeKpis')->willReturn($this->kpiFixture());

		$response = $this->controller->index();

		// Should still return 200 even when cache store fails.
		$this->assertSame(200, $response->getStatus());
	}//end testCacheStoreFailureDoesNotBreakResponse()

}//end class
