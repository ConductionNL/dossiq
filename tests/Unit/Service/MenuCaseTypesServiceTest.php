<?php

/**
 * MenuCaseTypesService Unit Tests
 *
 * The per-user list of case types in the menu: the Woo default for a user who
 * never chose, a saved empty list kept empty, and a saved list cleaned against
 * the case types the user may see.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\MenuCaseTypesService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Minimal OpenRegister ObjectService double: answers every slug search with $rows.
 */
class FakeMenuCaseTypeObjectService {

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
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters=[]): array {
		return $this->rows;
	}//end searchObjectsBySlug()

	/**
	 * What the facet query answers, or null to throw.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $facetAnswer = ['facets' => []];

	/**
	 * Every facet query asked, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $facetQueries = [];

	/**
	 * Count calls, which the counts must never make.
	 *
	 * @var int
	 */
	public int $countCalls = 0;

	/**
	 * Mimic OpenRegister ObjectService::getFacetsForObjects().
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @throws \RuntimeException When the answer is null.
	 */
	public function getFacetsForObjects(array $query=[]): array {
		$this->facetQueries[] = $query;
		if ($this->facetAnswer === null) {
			throw new \RuntimeException('facet store down');
		}

		return $this->facetAnswer;
	}//end getFacetsForObjects()

	/**
	 * Mimic OpenRegister ObjectService::count(), and record that it was asked.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return int Always 0.
	 */
	public function count(array $query=[]): int {
		$this->countCalls++;

		return 0;
	}//end count()
}//end class

/**
 * Unit tests for MenuCaseTypesService.
 *
 * @covers \OCA\Dossiq\Service\MenuCaseTypesService
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\CaseType\SupersededCaseTypes
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 */
class MenuCaseTypesServiceTest extends TestCase {

	private const WOO = MenuCaseTypesService::DEFAULT_CASE_TYPE;

	/**
	 * Build the service over the given case type rows and stored value.
	 *
	 * @param array<int, array<string, mixed>> $rows The case type rows.
	 * @param string $stored The stored user value.
	 * @param IConfig|null $config A config mock to use instead of the default.
	 * @param array<int, string> $groups The Nextcloud groups user `u` is in.
	 *
	 * @return MenuCaseTypesService
	 */
	private function service(
		array $rows,
		string $stored='',
		?IConfig $config=null,
		array $groups=[],
		?FakeMenuCaseTypeObjectService $objectService=null,
		string $language='nl'
	): MenuCaseTypesService {
		$objectService ??= new FakeMenuCaseTypeObjectService();
		$objectService->rows = $rows;

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_type_schema' => 'caseType',
					'case_schema' => 'case',
					default => '',
				};
			}
		);

		if ($config === null) {
			$config = $this->createMock(IConfig::class);
			$config->method('getUserValue')->willReturn($stored);
		}

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === 'u' ? $user : null)
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->with($user)->willReturn($groups);

		return new MenuCaseTypesService(
			settingsService: $settings,
			config: $config,
			groupManager: $groupManager,
			userManager: $userManager,
			text: $this->text(language: $language),
		);
	}//end service()

	/**
	 * The title resolver, reading in the given language.
	 *
	 * @param string $language The reader's language code.
	 *
	 * @return TranslatedText
	 */
	private function text(string $language): TranslatedText {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);

		return new TranslatedText(l10n: $l10n);
	}//end text()

	/**
	 * A translatable title reaches the menu in the reader's language, not as
	 * "Array": the title is stored as a language map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md
	 */
	public function testATranslatableTitleIsReadInTheReadersLanguage(): void {
		$rows = [
			['id' => 'kap', 'title' => ['nl' => 'Kapvergunning', 'en' => 'Tree felling permit']],
			['id' => 'woo', 'title' => ['nl' => 'Woo-verzoek']],
			['id' => 'bare', 'title' => []],
		];

		$this->assertSame(
			[
				['id' => 'bare', 'title' => 'bare'],
				['id' => 'kap', 'title' => 'Kapvergunning'],
				['id' => 'woo', 'title' => 'Woo-verzoek'],
			],
			$this->service($rows)->visibleCaseTypes()
		);

		$english = $this->service(rows: $rows, language: 'en')->visibleCaseTypes();
		$this->assertSame('Tree felling permit', $english[1]['title']);
		$this->assertSame('Woo-verzoek', $english[2]['title'], 'a map without the reader\'s language falls back to Dutch');
	}//end testATranslatableTitleIsReadInTheReadersLanguage()

	/**
	 * Visible case types: current versions only, one per uuid, sorted by title.
	 *
	 * @return void
	 */
	public function testVisibleCaseTypesSkipsSupersededVersionsAndSorts(): void {
		$service = $this->service(
			[
				['id' => 'b', 'title' => 'Bezwaar'],
				['id' => 'old', 'title' => 'Aanvraag', 'supersededBy' => 'a'],
				['id' => 'a', 'title' => 'Aanvraag'],
				['@self' => ['id' => 'k'], 'title' => 'Klacht'],
			]
		);

		$this->assertSame(
			[
				['id' => 'a', 'title' => 'Aanvraag'],
				['id' => 'b', 'title' => 'Bezwaar'],
				['id' => 'k', 'title' => 'Klacht'],
			],
			$service->visibleCaseTypes()
		);
	}//end testVisibleCaseTypesSkipsSupersededVersionsAndSorts()

	/**
	 * The rows of the board scenario: three visible case types, two handled by `vergunningen`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function teamRows(): array {
		return [
			['id' => self::WOO, 'title' => 'Woo-verzoek', 'handling' => ['defaultGroup' => 'woo']],
			['id' => 'omv', 'title' => 'Omgevingsvergunning', 'handling' => ['defaultGroup' => 'vergunningen']],
			['id' => 'mor', 'title' => 'Melding openbare ruimte', 'handling' => ['defaultGroup' => 'buitenruimte', 'teams' => ['vergunningen']]],
		];
	}//end teamRows()

	/**
	 * Offered: the case types a team the user is in handles, default group or extra team.
	 *
	 * @return void
	 */
	public function testOfferedAreTheCaseTypesTheUsersTeamHandles(): void {
		$service = $this->service(rows: $this->teamRows(), groups: ['vergunningen', 'everyone']);

		$this->assertSame(
			[
				['id' => 'mor', 'title' => 'Melding openbare ruimte'],
				['id' => 'omv', 'title' => 'Omgevingsvergunning'],
			],
			$service->offeredCaseTypes('u')
		);
	}//end testOfferedAreTheCaseTypesTheUsersTeamHandles()

	/**
	 * Offered: a user in no handling team gets every case type they may see.
	 *
	 * @return void
	 */
	public function testOfferedFallsBackToVisibleWhenTheUserIsInNoHandlingTeam(): void {
		$service = $this->service(rows: $this->teamRows(), groups: ['everyone']);

		$this->assertSame($service->visibleCaseTypes(), $service->offeredCaseTypes('u'));
		$this->assertCount(3, $service->offeredCaseTypes('u'));
	}//end testOfferedFallsBackToVisibleWhenTheUserIsInNoHandlingTeam()

	/**
	 * Offered: an unknown user is in no team and falls back the same way.
	 *
	 * @return void
	 */
	public function testOfferedForAnUnknownUserFallsBackToVisible(): void {
		$service = $this->service(rows: $this->teamRows(), groups: ['vergunningen']);

		$this->assertCount(3, $service->offeredCaseTypes('nobody'));
	}//end testOfferedForAnUnknownUserFallsBackToVisible()

	/**
	 * A user who left the team loses its case types from the menu on the next read.
	 *
	 * @return void
	 */
	public function testAChosenCaseTypeNoLongerHandledDropsOut(): void {
		$service = $this->service(rows: $this->teamRows(), stored: '["omv","' . self::WOO . '"]', groups: ['woo']);

		$this->assertSame(
			[['id' => self::WOO, 'title' => 'Woo-verzoek']],
			$service->chosen('u', $service->offeredCaseTypes('u'))
		);
	}//end testAChosenCaseTypeNoLongerHandledDropsOut()

	/**
	 * A user who never chose gets the Woo request case type when they may see it.
	 *
	 * @return void
	 */
	public function testNeverChoseDefaultsToWoo(): void {
		$service = $this->service([['id' => 'b', 'title' => 'Bezwaar'], ['id' => self::WOO, 'title' => 'Woo-verzoek']]);

		$this->assertSame([['id' => self::WOO, 'title' => 'Woo-verzoek']], $service->chosen('u', $service->visibleCaseTypes()));
	}//end testNeverChoseDefaultsToWoo()

	/**
	 * The default and a saved choice name a version that was superseded since:
	 * the menu lists the version in use, once.
	 *
	 * @return void
	 */
	public function testAChosenSupersededVersionFoldsToTheVersionInUse(): void {
		$rows = [
			['id' => 'b', 'title' => 'Bezwaar'],
			['id' => self::WOO, 'title' => 'Woo-verzoek', 'supersededBy' => 'woo-v2'],
			['id' => 'woo-v2', 'title' => 'Woo-verzoek'],
		];

		$service = $this->service(rows: $rows);
		$this->assertSame(expected: [['id' => 'woo-v2', 'title' => 'Woo-verzoek']], actual: $service->chosen('u', $service->visibleCaseTypes()));

		$saved = $this->service(rows: $rows, stored: (string)json_encode([self::WOO, 'woo-v2', 'b']));
		$this->assertSame(
			expected: [['id' => 'woo-v2', 'title' => 'Woo-verzoek'], ['id' => 'b', 'title' => 'Bezwaar']],
			actual: $saved->chosen('u', $saved->visibleCaseTypes())
		);
	}//end testAChosenSupersededVersionFoldsToTheVersionInUse()

	/**
	 * A user who never chose and may not see Woo gets nothing.
	 *
	 * @return void
	 */
	public function testNeverChoseWithoutWooIsEmpty(): void {
		$service = $this->service([['id' => 'b', 'title' => 'Bezwaar']]);

		$this->assertSame([], $service->chosen('u', $service->visibleCaseTypes()));
	}//end testNeverChoseWithoutWooIsEmpty()

	/**
	 * A saved empty list stays empty: the default does not come back.
	 *
	 * @return void
	 */
	public function testSavedEmptyListIsKept(): void {
		$service = $this->service([['id' => self::WOO, 'title' => 'Woo-verzoek']], '[]');

		$this->assertSame([], $service->chosen('u', $service->visibleCaseTypes()));
	}//end testSavedEmptyListIsKept()

	/**
	 * A stored value that is not JSON reads as an empty choice.
	 *
	 * @return void
	 */
	public function testGarbageStoredValueIsEmpty(): void {
		$service = $this->service([['id' => self::WOO, 'title' => 'Woo-verzoek']], 'not json');

		$this->assertSame([], $service->chosen('u', $service->visibleCaseTypes()));
	}//end testGarbageStoredValueIsEmpty()


	/**
	 * A cycle in supersededBy cannot hang the request.
	 *
	 * @return void
	 */
	public function testASupersededCycleEnds(): void {
		$store = new FakeMenuCaseTypeObjectService();
		$store->facetAnswer = ['facets' => ['caseType' => ['buckets' => [['key' => 'x', 'results' => 1]]]]];
		$service = $this->service(
			rows: [
				['id' => 'x', 'title' => 'X', 'supersededBy' => 'y'],
				['id' => 'y', 'title' => 'Y', 'supersededBy' => 'x'],
				['id' => 'c', 'title' => 'C'],
			],
			objectService: $store
		);

		$this->assertSame(expected: [['id' => 'c', 'title' => 'C']], actual: $service->visibleCaseTypes());
	}//end testASupersededCycleEnds()

	/**
	 * A superseded row whose replacement is not a plain id is skipped, not folded.
	 *
	 * @return void
	 */
	public function testAnExpandedSupersededByIsNotFollowed(): void {
		$store = new FakeMenuCaseTypeObjectService();
		$store->facetAnswer = ['facets' => ['caseType' => ['buckets' => [['key' => 'old', 'results' => 2]]]]];
		$service = $this->service(
			rows: [
				['id' => 'old', 'title' => 'Aanvraag', 'supersededBy' => ['id' => 'new']],
				['id' => 'new', 'title' => 'Aanvraag'],
			],
			objectService: $store
		);

		$this->assertSame(expected: [['id' => 'new', 'title' => 'Aanvraag']], actual: $service->visibleCaseTypes());
	}//end testAnExpandedSupersededByIsNotFollowed()
}//end class
