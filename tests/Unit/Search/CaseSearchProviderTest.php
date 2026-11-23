<?php

/**
 * Cases in the unified search.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Search;

use OCA\Dossiq\Search\CaseSearchProvider;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaseSearchProvider.
 *
 * @covers \OCA\Dossiq\Search\CaseSearchProvider
 */
class CaseSearchProviderTest extends TestCase {

	/**
	 * The searches the fake store answered.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The provider over a fake store with one case.
	 *
	 * @return CaseSearchProvider
	 */
	private function provider(): CaseSearchProvider {
		$queries = &$this->queries;
		$store = new class($queries) {
			/**
			 * @param array $queries The searches made, by reference.
			 */
			public function __construct(private array &$queries) {
			}

			/**
			 * The one case.
			 *
			 * @param array $query The query.
			 *
			 * @return array
			 */
			public function searchObjects(array $query): array {
				$this->queries[] = $query;
				return [['id' => 'case-1', 'identifier' => '2026-4833', 'title' => 'Street lighting Lindelaan']];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '5', 'case_schema' => '7'][$key] ?? ''
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc' . $path);

		return new CaseSearchProvider(settingsService: $settings, l10n: $l10n, urls: $urls);
	}//end provider()

	/**
	 * A query for the given term.
	 *
	 * @param string $term The term.
	 *
	 * @return ISearchQuery
	 */
	private function query(string $term): ISearchQuery {
		$query = $this->createMock(originalClassName: ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn(5);
		$query->method('getCursor')->willReturn(null);

		return $query;
	}//end query()

	/**
	 * A term finds the case by number or title, as the caller reads it, and links to its page.
	 *
	 * @return void
	 */
	public function testATermFindsTheCaseAndLinksToItsPage(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$result = $this->provider()->search(user: $user, query: $this->query(term: '#2026-4833'))->jsonSerialize();

		self::assertSame(expected: 'Cases', actual: $result['name']);
		self::assertSame(expected: '2026-4833', actual: $this->queries[0]['_search']);
		$entry = $result['entries'][0]->jsonSerialize();
		self::assertSame(expected: 'Street lighting Lindelaan', actual: $entry['title']);
		self::assertSame(expected: 'https://nc/index.php/apps/dossiq/cases/case-1', actual: $entry['resourceUrl']);
	}//end testATermFindsTheCaseAndLinksToItsPage()

	/**
	 * An empty term asks nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyTermAsksNothing(): void {
		$result = $this->provider()->search(user: $this->createMock(originalClassName: IUser::class), query: $this->query(term: ' '))->jsonSerialize();

		self::assertSame(expected: [], actual: $result['entries']);
		self::assertSame(expected: [], actual: $this->queries);
	}//end testAnEmptyTermAsksNothing()
}//end class
