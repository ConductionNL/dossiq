<?php

/**
 * BesluitvormingParafeerService Unit Tests
 *
 * Tests for the BesluitvormingParafeerService that orchestrates the parafering
 * chain within the besluitvorming workflow.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BesluitvormingParafeerService;
use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService shape for BesluitvormingParafeerService tests.
 */
interface BvwParafeerObjectServiceStub {
	public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): ?object;

	/**
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Field filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;

	/**
	 * Search objects (real ObjectService::searchObjects()).
	 *
	 * @param array<string,mixed> $query Query with @self block and field filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjects(array $query = []): array|int;

}//end interface

/**
 * Unit tests for BesluitvormingParafeerService.
 *
 * @covers \OCA\Dossiq\Service\BesluitvormingParafeerService
 */
class BesluitvormingParafeerServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var BesluitvormingParafeerService
	 */
	private BesluitvormingParafeerService $service;

	/**
	 * Resolves the sign-off route for a case type.
	 *
	 * @var ParafeerrouteDirectory&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $routes;

	/**
	 * Holds the route in the decision app.
	 *
	 * @var ParaferingDelegationService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $delegation;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->routes = $this->createMock(ParafeerrouteDirectory::class);
		$this->delegation = $this->createMock(ParaferingDelegationService::class);

		$this->service = new BesluitvormingParafeerService(
			$this->settingsService,
			$this->routes,
			$this->delegation,
			$this->logger,
		);

	}//end setUp()

	/**
	 * Test that activate() throws when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testActivateThrowsWhenOpenRegisterUnavailable(): void {
		$this->settingsService
			->method('getObjectService')
			->willReturn(null);

		$this->expectException(\RuntimeException::class);

		$this->service->activate(proposalId: 'test-voorstel-id');

	}//end testActivateThrowsWhenOpenRegisterUnavailable()

	/**
	 * Test that activate() returns array when voorstel is found.
	 *
	 * @return void
	 */
	public function testActivateReturnsArrayWhenVoorstelFound(): void {
		$proposalObj = new \stdClass();
		$proposalObj->id = 'voorstel-uuid-1';
		$proposalObj->status = 'draft';

		$updatedObj = new \stdClass();
		$updatedObj->id = 'voorstel-uuid-1';
		$updatedObj->status = 'in_parafering';

		$objectServiceMock = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectServiceMock
			->method('searchObjectsBySlug')
			->willReturnCallback(
				static function (string $register, string $schema, array $params) use ($proposalObj): array {
					return [$proposalObj];
				}
			);

		$objectServiceMock
			->method('saveObject')
			->willReturn($updatedObj);

		$this->settingsService
			->method('getObjectService')
			->willReturn($objectServiceMock);

		$this->settingsService
			->method('getConfigValue')
			->willReturn('test-value');

		// A route must resolve, or activation is refused. Before this change the
		// directory did not exist and activate() carried on with an EMPTY
		// snapshot; this test passed on exactly that path, which is why it had
		// to change with the behaviour rather than be made to keep passing.
		$this->routes->method('localRoute')->willReturn(['id' => 'pr-1', 'name' => 'Route']);
		$this->routes->method('stepsForCaseType')->willReturn(
			[['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]
		);
		$this->delegation->method('isAvailable')->willReturn(false);

		$result = $this->service->activate(proposalId: 'voorstel-uuid-1');

		$this->assertIsArray($result);

	}//end testActivateReturnsArrayWhenVoorstelFound()

	/**
	 * A voorstel whose case type has no route is NOT put into parafering.
	 *
	 * Before this change activate() wrote `currentStep: 1, status:
	 * in_parafering, routeSnapshot: []` and returned happily. Every action after
	 * it then failed `Current step not found in route snapshot` — a message
	 * about the snapshot when the fault is a route nobody configured — and the
	 * voorstel was parked with no way forward and no way back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function testActivateIsRefusedWhenNoRouteIsConfigured(): void {
		$proposalObj = new \stdClass();
		$proposalObj->id = 'voorstel-uuid-1';
		$proposalObj->status = 'draft';

		$objectServiceMock = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([$proposalObj]);
		// The refusal must land BEFORE any write. A save here means the voorstel
		// was already parked in parafering by the time we refused.
		$objectServiceMock->expects($this->never())->method('saveObject');

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('test-value');
		$this->routes->method('localRoute')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/No parafeerroute is configured/');

		$this->service->activate(proposalId: 'voorstel-uuid-1');

	}//end testActivateIsRefusedWhenNoRouteIsConfigured()

	/**
	 * A route that resolves to no steps is refused for the same reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function testActivateIsRefusedWhenTheRouteHasNoSteps(): void {
		$proposalObj = new \stdClass();
		$proposalObj->id = 'voorstel-uuid-1';

		$objectServiceMock = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([$proposalObj]);
		$objectServiceMock->expects($this->never())->method('saveObject');

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('test-value');
		$this->routes->method('localRoute')->willReturn(['id' => 'pr-1', 'name' => 'Leeg']);
		$this->routes->method('stepsForCaseType')->willReturn([]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/No parafeerroute is configured/');

		$this->service->activate(proposalId: 'voorstel-uuid-1');

	}//end testActivateIsRefusedWhenTheRouteHasNoSteps()

	/**
	 * The decision app refusing does not stop a voorstel entering parafering.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function testDecisionAppRefusalDoesNotBlockActivation(): void {
		$proposalObj = new \stdClass();
		$proposalObj->id = 'voorstel-uuid-1';

		$saved = [];
		$objectServiceMock = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([$proposalObj]);
		$objectServiceMock->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): \stdClass {
				$saved = $object;
				$out = new \stdClass();
				$out->id = 'voorstel-uuid-1';

				return $out;
			}
		);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturn('test-value');
		$this->routes->method('localRoute')->willReturn(['id' => 'pr-1', 'name' => 'Route']);
		$this->routes->method('stepsForCaseType')->willReturn(
            [['order' => 1, 'type' => 'parafering', 'actor' => 'alice']]
        );
		$this->delegation->method('isAvailable')->willReturn(true);
		$this->delegation->method('holdRoute')->willThrowException(new \RuntimeException('decision app down'));

		$result = $this->service->activate(proposalId: 'voorstel-uuid-1');

		$this->assertIsArray($result);
		$this->assertSame('in_parafering', $saved['status']);
		$this->assertArrayNotHasKey(
			'approvalRouteId',
			$saved,
			'an unmirrored voorstel is found by its EMPTY approvalRouteId, not by reading the log'
		);

	}//end testDecisionAppRefusalDoesNotBlockActivation()

	/**
	 * Test that allParafenCollected() returns false when no parafen exist.
	 *
	 * @return void
	 */
	public function testCheckAllParafenCollectedReturnsFalseWhenEmpty(): void {
		$objectServiceMock = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectServiceMock
			->method('searchObjectsBySlug')
			->willReturn([]);

		$this->settingsService
			->method('getObjectService')
			->willReturn($objectServiceMock);

		$this->settingsService
			->method('getConfigValue')
			->willReturn('test-schema');

		$result = $this->service->allParafenCollected(proposalId: 'voorstel-uuid-1');

		$this->assertFalse($result);

	}//end testCheckAllParafenCollectedReturnsFalseWhenEmpty()

	/**
	 * Test that allParafenCollected() returns false when OpenRegister unavailable.
	 *
	 * @return void
	 */
	public function testCheckAllParafenCollectedReturnsFalseWhenNoObjectService(): void {
		$this->settingsService
			->method('getObjectService')
			->willReturn(null);

		$result = $this->service->allParafenCollected(proposalId: 'voorstel-uuid-1');

		$this->assertFalse($result);

	}//end testCheckAllParafenCollectedReturnsFalseWhenNoObjectService()

	/**
	 * Test that handleParaafAction() returns array with status key.
	 *
	 * @return void
	 */
	public function testHandleParaafActionReturnsArrayWithStatus(): void {
		$proposalObj = new \stdClass();
		$proposalObj->id = 'voorstel-uuid-1';
		$proposalObj->status = 'in_parafering';

		$actionObj = new \stdClass();
		$actionObj->id = 'actie-uuid-1';
		$actionObj->action = 'approved';

		$updatedObj = new \stdClass();
		$updatedObj->id = 'voorstel-uuid-1';
		$updatedObj->status = 'gereed_voor_agendering';

		$objectServiceMock = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectServiceMock
			->method('searchObjectsBySlug')
			->willReturnCallback(
				static function (string $register, string $schema, array $params) use ($proposalObj, $actionObj): array {
					if (isset($params['id']) === true && $params['id'] === 'actie-uuid-1') {
						return [$actionObj];
					}

					return [$proposalObj];
				}
			);

		$objectServiceMock
			->method('saveObject')
			->willReturn($updatedObj);

		$this->settingsService
			->method('getObjectService')
			->willReturn($objectServiceMock);

		$this->settingsService
			->method('getConfigValue')
			->willReturn('test-value');

		$result = $this->service->handleParaafAction(
			proposalId: 'voorstel-uuid-1',
			parafeeractieId: 'actie-uuid-1',
		);

		$this->assertIsArray($result);

	}//end testHandleParaafActionReturnsArrayWithStatus()

}//end class
