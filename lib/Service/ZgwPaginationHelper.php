<?php

/**
 * Dossiq ZGW Pagination Helper
 *
 * Wraps OpenRegister's pagination into ZGW HAL-style format.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * ZGW pagination helper
 *
 * Wraps standard pagination results into the ZGW HAL-style format:
 * { "count": N, "next": url|null, "previous": url|null, "results": [...] }
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZgwPaginationHelper {
	/**
	 * Pagination and framework parameters that never go into a page link.
	 *
	 * @var array<string, int>
	 */
	private const FRAMEWORK_PARAMS = [
		'page' => 1,
		'_page' => 1,
		'_route' => 1,
		'zgwApi' => 1,
		'resource' => 1,
		'uuid' => 1,
	];

	/**
	 * Wrap paginated results in ZGW format.
	 *
	 * @param array $mappedObjects The mapped objects for the current page
	 * @param int $totalCount The total number of matching objects
	 * @param int $page The current page number (1-based)
	 * @param int $pageSize The page size
	 * @param string $baseUrl The base URL for pagination links
	 * @param array $queryParams The original query parameters
	 *
	 * @return array ZGW-formatted paginated response
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function wrapResults(array $mappedObjects, int $totalCount, int $page, int $pageSize, string $baseUrl, array $queryParams): array {
		$prefix = self::linkPrefix(baseUrl: $baseUrl, queryParams: $queryParams);
		$totalPages = self::totalPages(totalCount: $totalCount, pageSize: $pageSize);

		return [
			'count' => $totalCount,
			'next' => self::linkIf(url: $prefix . 'page=' . ($page + 1), exists: ($page < $totalPages)),
			'previous' => self::linkIf(url: $prefix . 'page=' . ($page - 1), exists: ($page > 1)),
			'results' => $mappedObjects,
		];
	}//end wrapResults()

	/**
	 * The number of pages; one when the page size is not positive.
	 *
	 * @param int $totalCount The total number of matching objects.
	 * @param int $pageSize   The page size.
	 *
	 * @return int The number of pages.
	 */
	private static function totalPages(int $totalCount, int $pageSize): int {
		if ($pageSize > 0) {
			return (int)ceil($totalCount / $pageSize);
		}

		return 1;
	}//end totalPages()

	/**
	 * The base URL with the caller's own query parameters, ready for `page=N`.
	 *
	 * Pagination and framework parameters are left out.
	 *
	 * @param string $baseUrl     The base URL for pagination links.
	 * @param array  $queryParams The original query parameters.
	 *
	 * @return string The link up to `page=`.
	 */
	private static function linkPrefix(string $baseUrl, array $queryParams): string {
		$queryString = http_build_query(data: array_diff_key($queryParams, self::FRAMEWORK_PARAMS));
		if ($queryString !== '') {
			return $baseUrl . '?' . $queryString . '&';
		}

		return $baseUrl . '?';
	}//end linkPrefix()

	/**
	 * The link when that page exists, else null.
	 *
	 * @param string $url    The link.
	 * @param bool   $exists Whether the page exists.
	 *
	 * @return string|null The link or null.
	 */
	private static function linkIf(string $url, bool $exists): ?string {
		if ($exists === true) {
			return $url;
		}

		return null;
	}//end linkIf()
}//end class
