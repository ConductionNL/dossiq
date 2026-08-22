<?php

/**
 * RaadsinformatieFeedController Unit Tests
 *
 * Tests for the public Atom feed endpoints for ORI entity types.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\RaadsinformatieFeedController;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService stub for the feed controller.
 *
 * Declares named-argument signatures matching production calls in
 * RaadsinformatieFeedController so createMock() generates a proper stub.
 */
interface FeedObjectServiceStub {
	/**
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string $registerSlug The register slug
	 * @param string $schemaSlug The schema slug
	 * @param array<string,mixed> $filters Query parameters
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
 * Unit tests for RaadsinformatieFeedController.
 *
 * @covers \OCA\Dossiq\Controller\RaadsinformatieFeedController
 */
class RaadsinformatieFeedControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

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
	 * The controller under test.
	 *
	 * @var RaadsinformatieFeedController
	 */
	private RaadsinformatieFeedController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new RaadsinformatieFeedController(
			request: $this->request,
			settingsService: $this->settingsService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Test vergaderingen() returns a DataDisplayResponse.
	 *
	 * @return void
	 */
	public function testVergaderingenReturnsDataDisplayResponse(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->vergaderingen();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);

	}//end testVergaderingenReturnsDataDisplayResponse()

	/**
	 * Test agendapunten() returns a DataDisplayResponse.
	 *
	 * @return void
	 */
	public function testAgendapuntenReturnsDataDisplayResponse(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->agendapunten();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);

	}//end testAgendapuntenReturnsDataDisplayResponse()

	/**
	 * Test documenten() returns a DataDisplayResponse.
	 *
	 * @return void
	 */
	public function testDocumentenReturnsDataDisplayResponse(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->documenten();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);

	}//end testDocumentenReturnsDataDisplayResponse()

	/**
	 * Test feed response contains valid Atom XML elements.
	 *
	 * @return void
	 */
	public function testFeedResponseContainsAtomXml(): void {
		$objectService = $this->createMock(FeedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([
			[
				'@self' => ['slug' => 'raadsvergadering-2026-06-15'],
				'name' => 'Raadsvergadering 15 juni 2026',
				'startDate' => '2026-06-15T19:00:00+02:00',
				'location' => 'Raadzaal',
				'status' => 'planned',
			],
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$response = $this->controller->vergaderingen();
		$body = $response->render();

		$this->assertStringContainsString('<feed', $body);
		$this->assertStringContainsString('<entry>', $body);
		$this->assertStringContainsString('Raadsvergadering 15 juni 2026', $body);

	}//end testFeedResponseContainsAtomXml()

	/**
	 * Test feed response sets Atom Content-Type header.
	 *
	 * @return void
	 */
	public function testFeedResponseSetsAtomContentType(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->vergaderingen();
		$headers = $response->getHeaders();

		$this->assertArrayHasKey('Content-Type', $headers);
		$this->assertStringContainsString('atom+xml', $headers['Content-Type']);

	}//end testFeedResponseSetsAtomContentType()

	/**
	 * Test feed response includes Cache-Control header.
	 *
	 * @return void
	 */
	public function testFeedResponseIncludesCacheControlHeader(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->vergaderingen();
		$headers = $response->getHeaders();

		$this->assertArrayHasKey('Cache-Control', $headers);

	}//end testFeedResponseIncludesCacheControlHeader()

	/**
	 * Test feed returns valid XML even when no objects are available.
	 *
	 * @return void
	 */
	public function testFeedReturnsValidXmlWhenNoObjects(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->vergaderingen();
		$body = $response->render();

		$this->assertStringContainsString('<feed', $body);
		$this->assertStringContainsString('</feed>', $body);

	}//end testFeedReturnsValidXmlWhenNoObjects()

}//end class
