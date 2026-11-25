<?php

/**
 * Cases in Nextcloud's unified search.
 *
 * The top bar finds the cases the reader may see by number or by words from
 * their title, from any app, the way it finds files and mail. It reads as the
 * caller, so a case outside their rights is never offered.
 *
 * @category Search
 * @package  OCA\Dossiq\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Search;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsConfiguredSource;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Finds cases by number or title for the unified search.
 *
 * @spec openspec/specs/case-management/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — `SearchResult::complete()` and `::paginated()` are the only way Nextcloud's search API builds a result.
 */
class CaseSearchProvider implements IProvider {
	use SearchesObjects;
	use ReadsConfiguredSource;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param IL10N           $l10n            The reader's language.
	 * @param IURLGenerator   $urls            Links to the case page.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The provider's id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getId(): string {
		return 'dossiq_cases';
	}//end getId()

	/**
	 * The heading over its results.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('Cases');
	}//end getName()

	/**
	 * First on dossiq's own pages, after the core providers elsewhere.
	 *
	 * @param string               $route           The route the search runs from.
	 * @param array<string, mixed> $routeParameters Its parameters.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/case-management/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — the parameters are the interface's; only the route decides.
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (str_starts_with($route, AppIdentity::APP_ID . '.') === true) {
			return -1;
		}

		return 35;
	}//end getOrder()

	/**
	 * The cases whose number or title holds the term.
	 *
	 * @param IUser        $user  The reader.
	 * @param ISearchQuery $query The term, limit and cursor.
	 *
	 * @return SearchResult
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$source = $this->configuredSource(schemaKey: 'case_schema');
		$term = trim($query->getTerm());
		if ($source === null || $term === '') {
			return SearchResult::complete(name: $this->getName(), entries: []);
		}

		[$objectService, $register, $schema] = $source;
		$offset = (int)($query->getCursor() ?? 0);
		$cases = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_search' => ltrim($term, '#'), '_limit' => $query->getLimit(), '_offset' => $offset]
		);

		$entries = [];
		foreach ($cases as $case) {
			$entries[] = $this->entry(case: $case);
		}

		return SearchResult::paginated(name: $this->getName(), entries: $entries, cursor: ($offset + count($entries)));
	}//end search()

	/**
	 * One case as a search result: its title, its number, and a link to its page.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return SearchResultEntry
	 */
	private function entry(array $case): SearchResultEntry {
		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));

		return new SearchResultEntry(
			thumbnailUrl: '',
			title: (string)($case['title'] ?? ''),
			subline: (string)($case['identifier'] ?? ''),
			resourceUrl: $this->urls->getAbsoluteURL('/index.php/apps/dossiq/cases/' . $caseId),
			icon: 'icon-folder',
		);
	}//end entry()
}//end class
