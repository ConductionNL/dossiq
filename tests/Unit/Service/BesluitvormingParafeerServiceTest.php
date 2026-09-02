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
use OCA\Dossiq\Service\Parafeer\ParaferingFlowGateway;
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
	 * Starts the projected flow when one is enabled.
	 *
	 * @var ParaferingFlowGateway&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $flows;

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

		$this->flows = $this->createMock(ParaferingFlowGateway::class);
		// No enabled projected flow by default, which is every route today:
		// the projections ship disabled, so activation takes the route path.
		$this->flows->method('startForRoute')->willReturn('');

		$this->service = new BesluitvormingParafeerService(
			$this->settingsService,
			$this->routes,
			$this->delegation,
			$this->flows,
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

	/**
	 * Build the service over an object service that RECORDS what it is asked
	 * to save.
	 *
	 * The older tests mock `saveObject` with `willReturn($canned)` and then
	 * assert on the canned value, so they pass whatever the service writes.
	 * These assert the argument instead, which is the only way a wrong status
	 * can fail a test.
	 *
	 * @param array<string, mixed> $proposal The stored voorstel.
	 * @param string               $action   The action on the parafeeractie.
	 * @param array<int, mixed>    $saved    Sink for saved objects.
	 *
	 * @return BesluitvormingParafeerService The service.
	 */
	private function serviceRecording(array $proposal, string $action, array &$saved): BesluitvormingParafeerService {
		$proposalObj = (object)$proposal;
		$actionObj = (object)['id' => 'actie-uuid-1', 'action' => $action];

		$objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectService
			->method('searchObjectsBySlug')
			->willReturnCallback(
				static function (string $register, string $schema, array $params) use ($proposalObj, $actionObj): array {
					if (isset($params['id']) === true && $params['id'] === 'actie-uuid-1') {
						return [$actionObj];
					}

					return [$proposalObj];
				}
			);
		$objectService
			->method('saveObject')
			->willReturnCallback(
				static function (array $object) use (&$saved): object {
					$saved[] = $object;

					return (object)$object;
				}
			);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturn('test-value');

		return $this->service;

	}//end serviceRecording()

	/**
	 * 🔴 A returned paraaf sends the voorstel back, it does not advance it.
	 *
	 * The action enum is (parafered, returned, advised, skipped, accorded).
	 * The service compared against 'retour', which is not in it, so a returned
	 * voorstel fell through to the advance below and moved FORWARD to the next
	 * approver. A rejection was read as an approval.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function testAReturnedParaafSendsTheVoorstelBack(): void {
		$saved = [];
		$service = $this->serviceRecording(
			[
				'id' => 'voorstel-uuid-1',
				'status' => 'in_parafering',
				'currentStep' => 1,
				'routeSnapshot' => [['order' => 1], ['order' => 2]],
			],
			'returned',
			$saved
		);

		$service->handleParaafAction(proposalId: 'voorstel-uuid-1', parafeeractieId: 'actie-uuid-1');

		$this->assertCount(1, $saved);
		$this->assertSame('teruggestuurd', $saved[0]['status']);
		// The step must NOT have moved: the voorstel goes back to its steller,
		// not on to approver two.
		$this->assertSame(1, $saved[0]['currentStep']);

	}//end testAReturnedParaafSendsTheVoorstelBack()

	/**
	 * 🔴 The last paraaf accords the voorstel.
	 *
	 * The service wrote 'gereed_voor_agendering', which is not a voorstel
	 * status and never was, so the moment every paraaf was collected wrote a
	 * value the schema rejects and the UI cannot render.
	 * `getStatusAfterAdvance()` in src/utils/parafeerEngine.js returns
	 * 'geaccordeerd' for this same transition.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function testTheLastParaafAccordsTheVoorstel(): void {
		$saved = [];
		$service = $this->serviceRecording(
			[
				'id' => 'voorstel-uuid-1',
				'status' => 'in_parafering',
				'currentStep' => 2,
				'routeSnapshot' => [['order' => 1], ['order' => 2]],
			],
			'parafered',
			$saved
		);

		$service->handleParaafAction(proposalId: 'voorstel-uuid-1', parafeeractieId: 'actie-uuid-1');

		$this->assertCount(1, $saved);
		$this->assertSame('geaccordeerd', $saved[0]['status']);
		$this->assertSame(0, $saved[0]['currentStep']);

	}//end testTheLastParaafAccordsTheVoorstel()

	/**
	 * A paraaf that is not a return advances to the next step.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function testAParaafedVoorstelAdvancesToTheNextStep(): void {
		$saved = [];
		$service = $this->serviceRecording(
			[
				'id' => 'voorstel-uuid-1',
				'status' => 'in_parafering',
				'currentStep' => 1,
				'routeSnapshot' => [['order' => 1], ['order' => 2]],
			],
			'parafered',
			$saved
		);

		$service->handleParaafAction(proposalId: 'voorstel-uuid-1', parafeeractieId: 'actie-uuid-1');

		$this->assertCount(1, $saved);
		$this->assertSame(2, $saved[0]['currentStep']);
		$this->assertSame('in_parafering', $saved[0]['status']);

	}//end testAParaafedVoorstelAdvancesToTheNextStep()

	/**
	 * 🔴 Every status literal the service writes is one the schema allows.
	 *
	 * This is the test that would have caught the two above as a class rather
	 * than one at a time. The service and the register JSON are edited by
	 * different hands at different times, and nothing else compares them: a
	 * status the schema does not declare is rejected on save, and the failure
	 * surfaces far from the line that wrote it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function testEveryStatusItWritesIsOneTheSchemaAllows(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$allowed = $register['components']['schemas']['proposal']['properties']['status']['enum'];

		$source = file_get_contents(__DIR__ . '/../../../lib/Service/BesluitvormingParafeerService.php');
		preg_match_all("/'status' => '([a-z_]+)'/", $source, $matches);
		$written = array_unique($matches[1]);

		$this->assertNotEmpty($written, 'the service must write at least one status');
		foreach ($written as $status) {
			$this->assertContains(
				$status,
				$allowed,
				sprintf('BesluitvormingParafeerService writes status %s, which the proposal schema does not allow', $status)
			);
		}

	}//end testEveryStatusItWritesIsOneTheSchemaAllows()

	/**
	 * 🔴 A voorstel records the run when its route's flow is enabled.
	 *
	 * This is the switch. EndorsementRouteFlowMigrator ships every projected
	 * flow disabled, so enabling one is the act that moves one route onto the
	 * engine, and `flowRunId` is how the voorstel says which run drives it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnEnabledFlowIsRecordedOnTheVoorstel(): void {
		$saved = [];
		$service = $this->activatingService($saved, 'run-uuid-1');

		$service->activate(proposalId: 'voorstel-uuid-1');

		$this->assertNotSame([], $saved);
		$this->assertSame('run-uuid-1', $saved[0]['flowRunId']);

	}//end testAnEnabledFlowIsRecordedOnTheVoorstel()

	/**
	 * 🔴 With no enabled flow the voorstel carries no run, and takes the route.
	 *
	 * The other half of the dual path, and the one every voorstel takes today.
	 * A voorstel that started before its route was enabled has to finish the
	 * way it started; a hard cutover would strand whatever is mid-parafering.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testWithoutAnEnabledFlowTheVoorstelTakesTheRoute(): void {
		$saved = [];
		$service = $this->activatingService($saved, '');

		$service->activate(proposalId: 'voorstel-uuid-1');

		$this->assertNotSame([], $saved);
		$this->assertArrayNotHasKey('flowRunId', $saved[0]);
		// The route snapshot is what drives it instead.
		$this->assertSame(1, $saved[0]['currentStep']);
		$this->assertSame('in_parafering', $saved[0]['status']);

	}//end testWithoutAnEnabledFlowTheVoorstelTakesTheRoute()

	/**
	 * `flowRunId` is a property the schema declares.
	 *
	 * OpenRegister runs hard validation by default, so a field the service
	 * writes and the schema does not declare is one the save rejects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testTheSchemaDeclaresTheFlowRunField(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$this->assertArrayHasKey(
			'flowRunId',
			$register['components']['schemas']['proposal']['properties']
		);

	}//end testTheSchemaDeclaresTheFlowRunField()

	/**
	 * Build a service whose activate() reaches the save, recording what it wrote.
	 *
	 * @param array<int, mixed> $saved     Sink for saved objects.
	 * @param string            $flowRunId What the gateway reports.
	 *
	 * @return BesluitvormingParafeerService The service.
	 */
	private function activatingService(array &$saved, string $flowRunId): BesluitvormingParafeerService {
		$proposalObj = (object)[
			'id' => 'voorstel-uuid-1',
			'status' => 'draft',
			'caseType' => 'casetype-1',
		];

		$objectService = $this->createMock(BvwParafeerObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$proposalObj]);
		$objectService
			->method('saveObject')
			->willReturnCallback(
				static function (array $object) use (&$saved): object {
					$saved[] = $object;

					return (object)$object;
				}
			);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('test-value');

		$routes = $this->createMock(ParafeerrouteDirectory::class);
		$routes->method('localRoute')->willReturn(['id' => 'route-1', 'name' => 'Standaard']);
		$routes->method('stepsForCaseType')->willReturn([['order' => 1, 'actor' => 'behandelaar']]);

		$flows = $this->createMock(ParaferingFlowGateway::class);
		$flows->method('startForRoute')->willReturn($flowRunId);

		return new BesluitvormingParafeerService(
			$settings,
			$routes,
			$this->createMock(ParaferingDelegationService::class),
			$flows,
			$this->logger,
		);

	}//end activatingService()

	/**
	 * 🔴 A flow-driven voorstel is left alone by the route snapshot.
	 *
	 * The paraaf the approver gave is picked up by ParaafResumeListener, which
	 * signals the run; the run's own nodes ask the next approver and write the
	 * status. Advancing the snapshot here as well would ask every approver
	 * twice and race the flow to the final status — which is exactly why the
	 * projections could not be enabled before this guard existed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAFlowDrivenVoorstelIsNotAdvancedHere(): void {
		$saved = [];
		$service = $this->serviceRecording(
			[
				'id' => 'voorstel-uuid-1',
				'status' => 'in_parafering',
				'currentStep' => 1,
				'flowRunId' => 'run-1',
				'routeSnapshot' => [['order' => 1], ['order' => 2]],
			],
			'parafered',
			$saved
		);

		$service->handleParaafAction(proposalId: 'voorstel-uuid-1', parafeeractieId: 'actie-uuid-1');

		$this->assertSame([], $saved, 'the flow owns this voorstel; nothing may be written here');

	}//end testAFlowDrivenVoorstelIsNotAdvancedHere()

	/**
	 * A voorstel on the route snapshot is still advanced, as it always was.
	 *
	 * The other arm of the same guard: every voorstel already mid-parafering
	 * when a route is enabled carries no run, and must finish the way it
	 * started rather than stall waiting for a flow nobody started for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAVoorstelWithNoRunIsStillAdvancedHere(): void {
		$saved = [];
		$service = $this->serviceRecording(
			[
				'id' => 'voorstel-uuid-1',
				'status' => 'in_parafering',
				'currentStep' => 1,
				'routeSnapshot' => [['order' => 1], ['order' => 2]],
			],
			'parafered',
			$saved
		);

		$service->handleParaafAction(proposalId: 'voorstel-uuid-1', parafeeractieId: 'actie-uuid-1');

		$this->assertCount(1, $saved);
		$this->assertSame(2, $saved[0]['currentStep']);

	}//end testAVoorstelWithNoRunIsStillAdvancedHere()

}//end class
