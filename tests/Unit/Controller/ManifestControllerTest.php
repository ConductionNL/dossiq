<?php

/**
 * ManifestController Unit Tests
 *
 * Tests the backend case-type navigation delta: the caption "My case types"
 * and one entry per case type the user chose, in their order, and the no-op
 * (`['menu' => []]`) fallbacks for the anonymous, no-ObjectService and
 * empty-list paths.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ManifestController;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
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
 * @covers \OCA\Dossiq\Controller\ManifestController
 * @uses \OCA\Dossiq\Service\MenuCaseTypesService
 * @uses \OCA\Dossiq\Service\Archival\ReadsConfiguredRows
 */
class ManifestControllerTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var IConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IConfig $config;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * Build the controller for a user (or anonymous) whose stored choice is given.
	 *
	 * @param string|null $userId The user id, or null for anonymous.
	 * @param string $stored The stored `menu_case_types` user value.
	 *
	 * @return ManifestController
	 */
	private function controller(?string $userId='test-user', string $stored=''): ManifestController {
		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$userSession->method('getUser')->willReturn($user);
		$this->config->method('getUserValue')->willReturn($stored);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $args): string {
				return '/index.php/settings/user/' . $args['section'] . '#' . $route;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ManifestController(
			appName: 'dossiq',
			request: $this->request,
			menuCaseTypes: new MenuCaseTypesService(settingsService: $this->settingsService, config: $this->config),
			userSession: $userSession,
			urlGenerator: $urlGenerator,
			l10n: $l10n,
		);
	}//end controller()

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
					'register' => 'dossiq',
					'case_type_schema' => 'caseType',
					default => '',
				};
			}
		);
	}//end withCaseTypes()

	/**
	 * manifest: the caption and the chosen case types, in the user's order.
	 *
	 * @return void
	 */
	public function testManifestReturnsTheChosenCaseTypesInOrder(): void {
		$this->withCaseTypes(
			[
				['id' => 'uuid-w', 'title' => 'Woo-verzoek'],
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
				['id' => 'uuid-k', 'title' => 'Klacht'],
			]
		);

		$response = $this->controller(stored: '["uuid-b","uuid-w"]')->manifest();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$menu = $response->getData()['menu'];
		$this->assertCount(3, $menu);

		$this->assertSame('MyCaseTypesCaption', $menu[0]['id']);
		$this->assertSame('caption', $menu[0]['type']);
		$this->assertSame('My case types', $menu[0]['label']);
		$this->assertSame(30, $menu[0]['order']);
		$this->assertSame('/index.php/settings/user/dossiq#settings.PersonalSettings.index', $menu[0]['href']);

		$this->assertSame('ct-uuid-b', $menu[1]['id']);
		$this->assertSame('Bezwaar', $menu[1]['label']);
		$this->assertSame('Cases', $menu[1]['route']);
		$this->assertSame(['caseType' => 'uuid-b'], $menu[1]['query']);
		$this->assertSame(31, $menu[1]['order']);

		$this->assertSame('ct-uuid-w', $menu[2]['id']);
		$this->assertSame(32, $menu[2]['order']);
	}//end testManifestReturnsTheChosenCaseTypesInOrder()

	/**
	 * manifest: no case type becomes a child of CasesGroup or any group.
	 *
	 * @return void
	 */
	public function testManifestAddsNoGroupChildren(): void {
		$this->withCaseTypes(
			[
				['id' => 'uuid-a', 'title' => 'Aanvraag'],
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
			]
		);

		$menu = $this->controller(stored: '[]')->manifest()->getData()['menu'];

		$this->assertSame(['MyCaseTypesCaption'], array_column($menu, 'id'));
		foreach ($menu as $entry) {
			$this->assertArrayNotHasKey('children', $entry);
		}
	}//end testManifestAddsNoGroupChildren()

	/**
	 * manifest: an unauthenticated caller is refused (401), never leaking data.
	 *
	 * @return void
	 */
	public function testManifestAnonymousReturnsUnauthorized(): void {
		$response = $this->controller(userId: null)->manifest();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testManifestAnonymousReturnsUnauthorized()

	/**
	 * manifest: no ObjectService yields a no-op delta without throwing.
	 *
	 * @return void
	 */
	public function testManifestReturnsEmptyWhenNoObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller()->manifest();
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

		$response = $this->controller()->manifest();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['menu' => []], $response->getData());
	}//end testManifestReturnsEmptyWhenNoCaseTypes()
}//end class
