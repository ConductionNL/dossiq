<?php

/**
 * ZGW OpenAPI Conformance Test
 *
 * The anti-drift mechanism for zgw-openapi-publication: parses the ZGW route
 * entries out of appinfo/routes.php (source-text regex, NOT a `require` —
 * routes.php calls `\OCA\OpenRegister\AppHost\Routes::standard()`, which is
 * unavailable in the standalone PHPUnit environment) and asserts that every
 * `/api/zgw/...` route (path + verb) is documented in exactly one of the six
 * `docs/openapi/zgw/*.yaml` OpenAPI documents, and vice versa. Path
 * parameters are normalized (`{anyName}` -> `{param}`) before comparison so
 * the test does not depend on both sides using identical placeholder names.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/zgw-openapi-publication/specs/zgw-openapi-publication/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Route <-> OpenAPI document conformance test.
 */
class ZgwOpenApiConformanceTest extends TestCase {

	/**
	 * Route names for the discovery endpoints themselves — excluded from the
	 * comparison since they are not part of the documented ZGW resource
	 * surface.
	 *
	 * @var array<int, string>
	 */
	private const DISCOVERY_ROUTE_NAMES = ['zgwOpenApi#index', 'zgwOpenApi#spec'];

	/**
	 * The six ZGW API ids, matching docs/openapi/zgw/<id>.yaml.
	 *
	 * @var array<int, string>
	 */
	private const APIS = ['zaken', 'documenten', 'catalogi', 'besluiten', 'autorisaties', 'notificaties'];

	/**
	 * Every `/api/zgw/...` route (path + verb), normalized and deduplicated,
	 * appears in exactly one OpenAPI document.
	 *
	 * @return void
	 */
	public function testEveryZgwRouteIsDocumentedExactlyOnce(): void {
		$routePairs = $this->parseZgwRoutePairsFromRoutesPhp();
		self::assertNotEmpty($routePairs, 'Expected to find /api/zgw/... routes in appinfo/routes.php');

		$yamlPairs = $this->collectYamlPathVerbPairs();

		$missingFromYaml = array_diff_key($routePairs, $yamlPairs);
		self::assertSame(
			[],
			array_keys($missingFromYaml),
			'Routes present in appinfo/routes.php but not documented in any docs/openapi/zgw/*.yaml file: '
			. implode(', ', array_keys($missingFromYaml))
		);
	}//end testEveryZgwRouteIsDocumentedExactlyOnce()

	/**
	 * Every documented path + verb in the six OpenAPI documents has a
	 * backing route in appinfo/routes.php.
	 *
	 * @return void
	 */
	public function testEveryDocumentedPathHasABackingRoute(): void {
		$routePairs = $this->parseZgwRoutePairsFromRoutesPhp();
		$yamlPairs = $this->collectYamlPathVerbPairs();

		$extraInYaml = array_diff_key($yamlPairs, $routePairs);
		self::assertSame(
			[],
			array_keys($extraInYaml),
			'OpenAPI documents describe paths with no backing route in appinfo/routes.php: '
			. implode(', ', array_keys($extraInYaml))
		);
	}//end testEveryDocumentedPathHasABackingRoute()

	/**
	 * No path + verb pair is documented in more than one of the six YAML
	 * files (each route must appear in exactly one document).
	 *
	 * @return void
	 */
	public function testNoPathVerbPairIsDocumentedInMoreThanOneFile(): void {
		$seenIn = [];
		foreach (self::APIS as $api) {
			$file = $this->specPath($api);
			$doc = Yaml::parseFile($file);
			foreach (($doc['paths'] ?? []) as $path => $operations) {
				$normalizedPath = $this->normalizePath((string)$path);
				foreach (array_keys($operations) as $verb) {
					$key = $normalizedPath . ' ' . strtoupper((string)$verb);
					$previous = $seenIn[$key] ?? null;
					self::assertArrayNotHasKey(
						$key,
						$seenIn,
						'Path+verb ' . $key . ' is documented in both ' . $previous . ' and ' . $file
					);
					$seenIn[$key] = $file;
				}
			}
		}

		self::assertNotEmpty($seenIn);
	}//end testNoPathVerbPairIsDocumentedInMoreThanOneFile()

	/**
	 * Parse `/api/zgw/...` route entries out of appinfo/routes.php via
	 * source-text regex (the file cannot be `require`d standalone — it
	 * calls into OpenRegister's AppHost\Routes, which is not present in the
	 * bare PHPUnit environment).
	 *
	 * @return array<string, true> Set of "normalizedPath VERB" keys.
	 */
	private function parseZgwRoutePairsFromRoutesPhp(): array {
		$source = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		self::assertIsString($source, 'Could not read appinfo/routes.php');

		$pattern = '/\'name\'\s*=>\s*\'([^\']+)\'\s*,\s*\'url\'\s*=>\s*\'([^\']+)\'\s*,\s*\'verb\'\s*=>\s*\'([A-Z]+)\'/';
		preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

		$pairs = [];
		foreach ($matches as $match) {
			[, $name, $url, $verb] = $match;

			if (str_starts_with($url, '/api/zgw/') === false) {
				continue;
			}

			if (in_array($name, self::DISCOVERY_ROUTE_NAMES, true) === true) {
				continue;
			}

			$key = $this->normalizePath($url) . ' ' . $verb;
			$pairs[$key] = true;
		}

		return $pairs;
	}//end parseZgwRoutePairsFromRoutesPhp()

	/**
	 * Collect all path + verb pairs documented across the six OpenAPI
	 * documents.
	 *
	 * @return array<string, true> Set of "normalizedPath VERB" keys.
	 */
	private function collectYamlPathVerbPairs(): array {
		$pairs = [];
		foreach (self::APIS as $api) {
			$doc = Yaml::parseFile($this->specPath($api));
			foreach (($doc['paths'] ?? []) as $path => $operations) {
				$normalizedPath = $this->normalizePath((string)$path);
				foreach (array_keys($operations) as $verb) {
					$key = $normalizedPath . ' ' . strtoupper((string)$verb);
					$pairs[$key] = true;
				}
			}
		}

		return $pairs;
	}//end collectYamlPathVerbPairs()

	/**
	 * Normalize every `{paramName}` path segment to `{param}` so that
	 * comparisons do not depend on both sides using identical placeholder
	 * names.
	 *
	 * @param string $path The raw path.
	 *
	 * @return string The normalized path.
	 */
	private function normalizePath(string $path): string {
		return (string)preg_replace('/\{[a-zA-Z0-9_]+\}/', '{param}', $path);
	}//end normalizePath()

	/**
	 * Resolve the filesystem path of an API's OpenAPI document.
	 *
	 * @param string $api The ZGW API id.
	 *
	 * @return string
	 */
	private function specPath(string $api): string {
		return dirname(__DIR__, 3) . '/docs/openapi/zgw/' . $api . '.yaml';
	}//end specPath()
}//end class
