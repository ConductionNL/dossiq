<?php

/**
 * Finds the schemas dossiq registers and nothing shows.
 *
 * A registered schema is storage. Storage nobody can reach is storage ahead of
 * the feature, and a privacy question the moment the schema holds personal
 * data: an instance creates the table, the descriptor promises the capability,
 * and no page, no controller and no service ever touches it.
 *
 * 🔑 IT RESOLVES THE SLUG, NOT THE KEY. The register keys a schema by an
 * identifier and the schema carries its own `slug`, and the two are not
 * always the same word. Matching on the key alone reports schemas that are
 * shown under their slug, which is the mirror of the mistake
 * `DeclaredDisplayFlagHasReaderTest` records: a scanner matching names opens
 * defects against controls that work.
 *
 * 🔑 A CHILD OF A SHOWN PARENT COUNTS, ONE HOP. A schema reached by `$ref`
 * from one that IS on a page is rendered through its parent, so it has a
 * surface even though no page names it. One hop and not transitive closure,
 * deliberately: two hops is where "reachable" stops meaning "a person can see
 * it" and starts meaning "the graph is connected".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

/**
 * Reads the effective register and answers which slugs have no surface.
 *
 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
 */
class SchemaSurfaceScanner {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $registerFiles The register descriptors, base first.
	 * @param string             $manifestFile  The app manifest.
	 * @param array<int, string> $sourceDirs    Directories a reference may live in.
	 * @param array<int, string> $excludeDirs   Directories that do not count as a
	 *                                          reference, notably `lib/Settings`:
	 *                                          a schema mentioned only by the
	 *                                          descriptor that declares it is
	 *                                          exactly the case under test.
	 */
	public function __construct(
		private readonly array $registerFiles,
		private readonly string $manifestFile,
		private readonly array $sourceDirs,
		private readonly array $excludeDirs = [],
	) {

	}//end __construct()

	/**
	 * Every schema slug the register declares.
	 *
	 * @return array<int, string> The slugs, sorted, without duplicates.
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function declaredSlugs(): array {
		$slugs = array_keys($this->schemas());
		sort($slugs);

		return $slugs;
	}//end declaredSlugs()

	/**
	 * The slugs with no surface of any kind.
	 *
	 * @return array<int, string> The orphans, sorted.
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function orphanSlugs(): array {
		$schemas = $this->schemas();
		$shown = $this->shownSlugs();
		$referenced = $this->referencedSlugs();

		// Direct surfaces first: on a page, or named in code outside the
		// descriptors. Only then the one hop, so a child counts through a
		// parent that itself has a surface rather than through another
		// orphan.
		$direct = [];
		foreach (array_keys($schemas) as $slug) {
			if (in_array($slug, $shown, true) === true || in_array($slug, $referenced, true) === true) {
				$direct[] = $slug;
			}
		}

		$orphans = [];
		foreach (array_keys($schemas) as $slug) {
			if (in_array($slug, $direct, true) === true) {
				continue;
			}

			if ($this->hasParentWithSurface(slug: $slug, schemas: $schemas, direct: $direct) === true) {
				continue;
			}

			$orphans[] = $slug;
		}

		sort($orphans);

		return $orphans;
	}//end orphanSlugs()

	/**
	 * Whether any schema with a surface points at this one.
	 *
	 * @param string                              $slug    The slug under test.
	 * @param array<string, array<string, mixed>> $schemas Every schema, by slug.
	 * @param array<int, string>                  $direct  Slugs with a direct surface.
	 *
	 * @return boolean True when a shown parent refers to it.
	 */
	private function hasParentWithSurface(string $slug, array $schemas, array $direct): bool {
		foreach ($direct as $parent) {
			if (in_array($slug, $this->refsOf(schema: ($schemas[$parent] ?? [])), true) === true) {
				return true;
			}
		}

		return false;
	}//end hasParentWithSurface()

	/**
	 * The slugs a schema points at.
	 *
	 * Reads `$ref` values wherever they appear, and the `objectConfiguration`
	 * / `inversedBy` shapes OpenRegister uses for a relation, because a child
	 * collection is declared with those rather than with a bare `$ref`.
	 *
	 * @param array<string, mixed> $schema One schema.
	 *
	 * @return array<int, string> The slugs it refers to.
	 */
	private function refsOf(array $schema): array {
		$found = [];
		array_walk_recursive(
			$schema,
			static function (mixed $value, string|int $key) use (&$found): void {
				if (is_string($value) === false) {
					return;
				}

				if (in_array($key, ['$ref', 'schema', 'inversedBy', 'targetSchema'], true) === false) {
					return;
				}

				// A `$ref` is written either as a bare slug or as a pointer
				// ending in one. The last segment is the slug either way.
				$parts = explode('/', $value);
				$last = trim((string)end($parts));
				if ($last !== '') {
					$found[] = $last;
				}
			}
		);

		return array_values(array_unique($found));
	}//end refsOf()

	/**
	 * Every schema in the effective register, keyed by slug.
	 *
	 * The fragments are merged in the order given, so a later fragment
	 * redeclaring a schema wins, which is how the app loads them.
	 *
	 * @return array<string, array<string, mixed>> The schemas, by slug.
	 */
	private function schemas(): array {
		$schemas = [];
		foreach ($this->registerFiles as $file) {
			if (is_file($file) === false) {
				continue;
			}

			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			foreach (($data['components']['schemas'] ?? []) as $key => $schema) {
				if (is_array($schema) === false) {
					continue;
				}

				// The slug is the name everything else uses; the key is only
				// where it sits in this file.
				$slug = trim((string)($schema['slug'] ?? $key));
				if ($slug === '') {
					$slug = (string)$key;
				}

				$schemas[$slug] = $schema;
			}
		}

		return $schemas;
	}//end schemas()

	/**
	 * The slugs the manifest puts on a page.
	 *
	 * @return array<int, string> The slugs, without duplicates.
	 */
	private function shownSlugs(): array {
		if (is_file($this->manifestFile) === false) {
			return [];
		}

		$manifest = json_decode((string)file_get_contents($this->manifestFile), true);
		if (is_array($manifest) === false) {
			return [];
		}

		$found = [];
		array_walk_recursive(
			$manifest,
			static function (mixed $value, string|int $key) use (&$found): void {
				if (is_string($value) === false || trim($value) === '') {
					return;
				}

				// `schema` on a page or widget, `schemaSlug` on a deep link.
				// Nothing else in the manifest names a schema, and matching
				// every string would make every slug look shown.
				if (in_array($key, ['schema', 'schemaSlug'], true) === true) {
					$found[] = trim($value);
				}
			}
		);

		return array_values(array_unique($found));
	}//end shownSlugs()

	/**
	 * The slugs named as a string literal anywhere in the scanned sources.
	 *
	 * @return array<int, string> The slugs, without duplicates.
	 */
	private function referencedSlugs(): array {
		$haystack = '';
		foreach ($this->sourceDirs as $dir) {
			foreach ($this->filesIn(dir: $dir) as $file) {
				$haystack .= file_get_contents($file);
			}
		}

		$found = [];
		foreach (array_keys($this->schemas()) as $slug) {
			// Quoted, in either quote style. A bare word match would count a
			// slug that happens to be a substring of a method name, which is
			// how `case` would look referenced by every file in the app.
			if (str_contains($haystack, "'".$slug."'") === true || str_contains($haystack, '"'.$slug.'"') === true) {
				$found[] = $slug;
			}
		}

		return $found;
	}//end referencedSlugs()

	/**
	 * Every PHP, Vue, JS and TS file under a directory, minus the exclusions.
	 *
	 * @param string $dir The directory.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function filesIn(string $dir): array {
		if (is_dir($dir) === false) {
			return [];
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			$path = $file->getPathname();
			if (in_array(strtolower($file->getExtension()), ['php', 'vue', 'js', 'ts', 'json'], true) === false) {
				continue;
			}

			foreach ($this->excludeDirs as $excluded) {
				if (str_starts_with($this->normalise(path: $path), $this->normalise(path: $excluded)) === true) {
					continue 2;
				}
			}

			$files[] = $path;
		}

		return $files;
	}//end filesIn()

	/**
	 * A path without `..` segments, so an exclusion can be compared to it.
	 *
	 * @param string $path The path.
	 *
	 * @return string The resolved path, or the input when it does not exist.
	 */
	private function normalise(string $path): string {
		$real = realpath($path);
		if ($real === false) {
			return $path;
		}

		return $real;
	}//end normalise()
}//end class
