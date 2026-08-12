<?php

/**
 * ManifestController Unit Tests
 *
 * Tests the backend case-type navigation delta: one menu child per visible
 * caseType under `CasesGroup`, and the no-op (`['menu' => []]`) fallbacks for
 * the anonymous, no-ObjectService, unconfigured and empty-list paths.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-navigation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ManifestController;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * In-memory ObjectService fake exposing only the slug-search entry point used
 * by the SearchesObjects trait for non-numeric register/schema identifiers.
 */
class FakeCaseTypeObjectService {

	/**
	 * Rows returned for any slug search.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Mimic OpenRegister ObjectService::searchObjectsBySlug().
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Equality filters + pagination keys.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		return $this->rows;
	}//end searchObjectsBySlug()
}//end class

/**
 * Unit tests for ManifestController.
 *
 * @covers \OCA\Procest\Controller\ManifestController
 */
class ManifestControllerTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var ManifestController
	 */
	private ManifestController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new ManifestController(
			appName: 'procest',
			request: $this->request,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Wire the settings service to return a fake object service + config values.
	 *
	 * @param array<int, array<string, mixed>> $rows Case-type rows to return.
	 *
	 * @return void
	 */
	private function withCaseTypes(array $rows): void {
		$objectService = new FakeCaseTypeObjectService();
		$objectService->rows = $rows;

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'procest',
					'case_type_schema' => 'caseType',
					default => '',
				};
			}
		);
	}//end withCaseTypes()

	/**
	 * manifest: returns one CasesGroup child per case type, each routed to Cases.
	 *
	 * @return void
	 */
	public function testManifestReturnsChildPerCaseType(): void {
		$this->withCaseTypes(
			[
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
				['id' => 'uuid-a', 'title' => 'Aanvraag'],
			]
		);

		$response = $this->controller->manifest();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('menu', $data);
		$this->assertCount(1, $data['menu']);
		$this->assertSame('CasesGroup', $data['menu'][0]['id']);

		$children = $data['menu'][0]['children'];
		$this->assertCount(2, $children);

		// Deterministic sort by name: "Aanvraag" precedes "Bezwaar".
		$this->assertSame('ct-uuid-a', $children[0]['id']);
		$this->assertSame('Aanvraag', $children[0]['label']);
		$this->assertSame('Cases', $children[0]['route']);
		$this->assertSame('uuid-a', $children[0]['query']['caseType']);

		$this->assertSame('ct-uuid-b', $children[1]['id']);
		$this->assertSame('Cases', $children[1]['route']);
		$this->assertSame('uuid-b', $children[1]['query']['caseType']);
	}//end testManifestReturnsChildPerCaseType()

	/**
	 * manifest: an unauthenticated caller is refused (401), never leaking data.
	 *
	 * @return void
	 */
	public function testManifestAnonymousReturnsUnauthorized(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new ManifestController(
			appName: 'procest',
			request: $this->request,
			settingsService: $this->settingsService,
			userSession: $userSession,
		);

		$response = $controller->manifest();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testManifestAnonymousReturnsEmptyDelta()

	/**
	 * manifest: no ObjectService yields a no-op delta without throwing.
	 *
	 * @return void
	 */
	public function testManifestReturnsEmptyWhenNoObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->manifest();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['menu' => []], $response->getData());
	}//end testManifestReturnsEmptyWhenNoObjectService()

	/**
	 * manifest: an empty case-type list yields a no-op delta.
	 *
	 * @return void
	 */
	public function testManifestReturnsEmptyWhenNoCaseTypes(): void {
		$this->withCaseTypes([]);

		$response = $this->controller->manifest();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['menu' => []], $response->getData());
	}//end testManifestReturnsEmptyWhenNoCaseTypes()
}//end class
