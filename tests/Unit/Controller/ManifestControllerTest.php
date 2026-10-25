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
use OCA\Dossiq\Service\CaseTypeViews;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
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
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
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
	 * @param array<int, string> $groups The Nextcloud groups the user is in.
	 *
	 * @return ManifestController
	 */
	private function controller(?string $userId='test-user', string $stored='', array $groups=[]): ManifestController {
		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$userSession->method('getUser')->willReturn($user);
		$this->config->method('getUserValue')->willReturn($stored);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new ManifestController(
			appName: 'dossiq',
			request: $this->request,
			menuCaseTypes: new MenuCaseTypesService(
				settingsService: $this->settingsService,
				config: $this->config,
				groupManager: $groupManager,
				userManager: $userManager,
				text: new \OCA\Dossiq\Service\Support\TranslatedText(l10n: $l10n),
			),
			userSession: $userSession,
			caseTypeViews: new CaseTypeViews(),
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
	 * The default views the delta adds to the Cases page.
	 *
	 * @param array<string, mixed> $data The response data.
	 *
	 * @return array<int, array<string, mixed>> The views.
	 */
	private static function views(array $data): array {
		$pages = ($data['pages'] ?? []);
		self::assertSame(expected: ['Cases'], actual: array_column($pages, 'id'));
		return $pages[0]['config'][CaseTypeViews::DEFAULT_VIEWS_KEY];
	}//end views()

	/**
	 * manifest: one view of the case list per pinned case type, in the user's
	 * order, and no menu entries of dossiq's own; the Woo request case type
	 * keeps the view the page ships.
	 *
	 * @return void
	 */
	public function testManifestReturnsAViewPerPinnedCaseTypeAndNoMenuEntries(): void {
		$this->withCaseTypes(
			rows: [
				['id' => MenuCaseTypesService::DEFAULT_CASE_TYPE, 'title' => 'Woo-verzoek'],
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
				['id' => 'uuid-k', 'title' => 'Klacht'],
			]
		);

		$response = $this->controller(stored: '["uuid-k","' . MenuCaseTypesService::DEFAULT_CASE_TYPE . '","uuid-b"]')->manifest();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $response->getData()['menu']);
		$views = self::views(data: $response->getData());
		$this->assertSame(expected: ['case-type-uuid-k', 'case-type-uuid-b'], actual: array_column($views, 'slug'));
		$this->assertSame(expected: 'Klacht', actual: $views[0]['name']);
		$this->assertSame(
			expected: ['caseType' => 'uuid-k', 'statusHiddenInLists' => false, 'isDraft' => false],
			actual: $views[0]['filters']
		);
	}//end testManifestReturnsAViewPerPinnedCaseTypeAndNoMenuEntries()

	/**
	 * manifest: a list the instance names by case type identifier (Complaints)
	 * is a view for every user, ahead of the user's own pinned case types.
	 *
	 * @return void
	 */
	public function testManifestAddsTheComplaintsViewWhenTheInstanceHasThatCaseType(): void {
		$this->withCaseTypes(
			rows: [
				['id' => 'uuid-k', 'title' => 'Klacht behandeling', 'identifier' => 'klacht-behandeling'],
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
			]
		);

		$views = self::views(data: $this->controller(stored: '["uuid-b"]')->manifest()->getData());

		$this->assertSame(expected: ['complaints', 'case-type-uuid-b'], actual: array_column($views, 'slug'));
		$this->assertSame(
			expected: ['caseType' => 'uuid-k', 'statusHiddenInLists' => false, 'isDraft' => false],
			actual: $views[0]['filters']
		);
	}//end testManifestAddsTheComplaintsViewWhenTheInstanceHasThatCaseType()

	/**
	 * manifest: the case-type views sit under "More default views" (Ruben, 23
	 * October 2026: the board's three default views first, the rest behind
	 * that disclosure), each with a description of its own so the panel never
	 * shows the case type's uuid while it resolves the filter.
	 *
	 * @return void
	 */
	public function testManifestCaseTypeViewsAreSecondaryWithADescription(): void {
		$this->withCaseTypes(
			rows: [
				['id' => 'uuid-k', 'title' => 'Klacht behandeling', 'identifier' => 'klacht-behandeling'],
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
			]
		);

		$views = self::views(data: $this->controller(stored: '["uuid-b"]')->manifest()->getData());

		$this->assertSame(expected: [true, true], actual: array_column($views, 'secondary'));
		$this->assertSame(expected: ['Case type complaint handling', 'Bezwaar'], actual: array_column($views, 'description'));
	}//end testManifestCaseTypeViewsAreSecondaryWithADescription()

	/**
	 * manifest: a pinned case type the user's team does not handle gets no view.
	 *
	 * @return void
	 */
	public function testManifestLeavesOutAPinnedCaseTypeTheTeamDoesNotHandle(): void {
		$this->withCaseTypes(
			rows: [
				['id' => 'uuid-o', 'title' => 'Omgevingsvergunning', 'handling' => ['defaultGroup' => 'vergunningen']],
				['id' => 'uuid-x', 'title' => 'Klacht', 'handling' => ['defaultGroup' => 'klachten']],
			]
		);

		$data = $this->controller(stored: '["uuid-x","uuid-o"]', groups: ['vergunningen'])->manifest()->getData();

		$this->assertSame(expected: ['case-type-uuid-o'], actual: array_column(self::views(data: $data), 'slug'));
	}//end testManifestLeavesOutAPinnedCaseTypeTheTeamDoesNotHandle()

	/**
	 * manifest: a user who pinned nothing, or only the Woo request case type,
	 * gets a no-op delta.
	 *
	 * @return void
	 */
	public function testManifestAddsNothingWhenNoViewIsNeeded(): void {
		$this->withCaseTypes(
			rows: [
				['id' => MenuCaseTypesService::DEFAULT_CASE_TYPE, 'title' => 'Woo-verzoek'],
				['id' => 'uuid-b', 'title' => 'Bezwaar'],
			]
		);

		$this->assertSame(expected: ['menu' => []], actual: $this->controller(stored: '[]')->manifest()->getData());
		$this->assertSame(expected: ['menu' => []], actual: $this->controller(stored: '')->manifest()->getData());
	}//end testManifestAddsNothingWhenNoViewIsNeeded()

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
