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
		?FakeMenuCaseTypeObjectService $objectService=null
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
		);
	}//end service()

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
	 * Save keeps only offered ids: a case type the team does not handle is not stored.
	 *
	 * @return void
	 */
	public function testSaveKeepsOnlyOfferedCaseTypes(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('setUserValue')
			->with('u', 'dossiq', 'menu_case_types', '["omv"]');

		$service = $this->service(rows: $this->teamRows(), config: $config, groups: ['vergunningen']);

		$service->save('u', [self::WOO, 'omv'], $service->offeredCaseTypes('u'));
	}//end testSaveKeepsOnlyOfferedCaseTypes()

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
	 * Save keeps visible ids, first occurrence only, in the order given.
	 *
	 * @return void
	 */
	public function testSaveCleansAndKeepsOrder(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('setUserValue')
			->with('u', 'dossiq', 'menu_case_types', '["b","a"]');

		$service = $this->service([['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']], '', $config);

		$kept = $service->save('u', ['b', 'c', 'a', 'b', 42], $service->visibleCaseTypes());
		$this->assertSame([['id' => 'b', 'title' => 'B'], ['id' => 'a', 'title' => 'A']], $kept);
	}//end testSaveCleansAndKeepsOrder()

	/**
	 * Save keeps at most thirty entries.
	 *
	 * @return void
	 */
	public function testSaveCapsTheList(): void {
		$rows = [];
		$ids = [];
		for ($i = 0; $i < 40; $i++) {
			$rows[] = ['id' => 'ct' . $i, 'title' => 'Type ' . $i];
			$ids[] = 'ct' . $i;
		}

		$config = $this->createMock(IConfig::class);
		$service = $this->service($rows, '', $config);

		$this->assertCount(MenuCaseTypesService::MAX_ENTRIES, $service->save('u', $ids, $service->visibleCaseTypes()));
	}//end testSaveCapsTheList()
	/**
	 * Each offered case type carries its open cases, from ONE facet query.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testEachCaseTypeShowsItsOpenCasesFromOneQuery(): void {
		$store = new FakeMenuCaseTypeObjectService();
		$store->facetAnswer = [
			'facets' => [
				'caseType' => [
					'type' => 'terms',
					'data' => ['buckets' => [['key' => 'w', 'results' => 19], ['key' => 'b', 'results' => 7]]],
				],
			],
		];
		$service = $this->service(
			rows: [['id' => 'w', 'title' => 'Woo-verzoek'], ['id' => 'b', 'title' => 'Bezwaar'], ['id' => 'k', 'title' => 'Klacht']],
			objectService: $store
		);

		$counted = $service->withOpenCaseCounts(caseTypes: $service->offeredCaseTypes(userId: 'u'));

		$this->assertSame(
			['b' => 7, 'k' => 0, 'w' => 19],
			array_column($counted, 'openCases', 'id'),
			'A case type the facet does not name has no open cases.'
		);
		$this->assertCount(1, $store->facetQueries, 'One aggregate query, not one per case type.');
		$this->assertSame(0, $store->countCalls);

		$query = $store->facetQueries[0];
		$this->assertSame(['caseType' => ['type' => 'terms']], $query['_facets']);
		$this->assertSame(0, $query['isFinalStatus']);
		$this->assertSame(0, $query['statusHiddenInLists']);
		$this->assertSame(0, $query['isDraft']);
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case'], $query['@self']);
	}//end testEachCaseTypeShowsItsOpenCasesFromOneQuery()

	/**
	 * A case on an older version counts for the version in use.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testACaseOnAnOlderVersionCountsForTheVersionInUse(): void {
		$store = new FakeMenuCaseTypeObjectService();
		$store->facetAnswer = [
			'facets' => [
				'caseType' => ['buckets' => [['key' => 'v1', 'results' => 3], ['key' => 'v2', 'results' => 2], ['value' => 'v0', 'count' => 1]]],
			],
		];
		$service = $this->service(
			rows: [
				['id' => 'v0', 'title' => 'Aanvraag', 'supersededBy' => 'v1'],
				['id' => 'v1', 'title' => 'Aanvraag', 'supersededBy' => 'v2'],
				['id' => 'v2', 'title' => 'Aanvraag'],
			],
			objectService: $store
		);

		$counted = $service->withOpenCaseCounts(caseTypes: $service->visibleCaseTypes());

		$this->assertSame([['id' => 'v2', 'title' => 'Aanvraag', 'openCases' => 6]], $counted);
	}//end testACaseOnAnOlderVersionCountsForTheVersionInUse()

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

		$counted = $service->withOpenCaseCounts(caseTypes: $service->visibleCaseTypes());

		$this->assertSame([['id' => 'c', 'title' => 'C', 'openCases' => 0]], $counted);
	}//end testASupersededCycleEnds()

	/**
	 * An unknown count is null, never 0, and a failing facet is passed on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testAnUnreadableCountIsNullNotZero(): void {
		$noFacet = new FakeMenuCaseTypeObjectService();
		$noFacet->facetAnswer = ['facets' => []];
		$service = $this->service(rows: [['id' => 'w', 'title' => 'Woo-verzoek']], objectService: $noFacet);
		$this->assertNull(
			$service->withOpenCaseCounts(caseTypes: $service->visibleCaseTypes())[0]['openCases'],
			'An answer without the caseType facet is not an answer of zero.'
		);
		$this->assertSame(
			[['id' => 'w', 'title' => 'Woo-verzoek', 'openCases' => null]],
			$service->withUnknownOpenCaseCounts(caseTypes: [['id' => 'w', 'title' => 'Woo-verzoek']])
		);

		$throwing = new FakeMenuCaseTypeObjectService();
		$throwing->facetAnswer = null;
		$service = $this->service(rows: [['id' => 'w', 'title' => 'Woo-verzoek']], objectService: $throwing);
		$this->expectException(\RuntimeException::class);
		$service->withOpenCaseCounts(caseTypes: $service->visibleCaseTypes());
	}//end testAnUnreadableCountIsNullNotZero()
}//end class
