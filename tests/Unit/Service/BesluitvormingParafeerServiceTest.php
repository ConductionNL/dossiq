<?php

/**
 * BesluitvormingParafeerService Unit Tests
 *
 * Tests for the BesluitvormingParafeerService that orchestrates the parafering
 * chain within the besluitvorming workflow.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BesluitvormingParafeerService;
use OCA\Procest\Service\SettingsService;
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
 * @covers \OCA\Procest\Service\BesluitvormingParafeerService
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
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new BesluitvormingParafeerService(
			$this->settingsService,
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
		$proposalObj->status = 'concept';

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

		$result = $this->service->activate(proposalId: 'voorstel-uuid-1');

		$this->assertIsArray($result);

	}//end testActivateReturnsArrayWhenVoorstelFound()

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
		$actionObj->action = 'goedgekeurd';

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
