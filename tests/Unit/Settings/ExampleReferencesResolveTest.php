<?php

/**
 * Every reference an example object makes resolves to an object in the same set.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The register's example objects are refused on import when a property the
 * schema declares `format: uuid` holds a slug. OpenRegister resolves a sibling
 * by the seed reference token `@ref:<schema>:<slug>` (or `@ref:<slug>`) before
 * it validates, so an example reference is either a uuid or such a token, and
 * the token names an object of the referenced schema in the merged set.
 */
class ExampleReferencesResolveTest extends TestCase {
	/**
	 * A uuid, as the schema's `format: uuid` reads it.
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The merged register: the monolith plus every register.d fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged;

	/**
	 * Merge the real register files.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		[$this->merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../lib/Settings/register.d'
		);
	}//end setUp()

	/**
	 * No example reference holds a bare slug, and every token names an existing example of the referenced schema.
	 *
	 * @return void
	 */
	public function testEveryExampleReferenceIsAUuidOrAResolvableToken(): void {
		$schemas = ($this->merged['components']['schemas'] ?? []);
		$objects = ($this->merged['components']['objects'] ?? []);

		$bySchemaSlug = [];
		$bySlug = [];
		foreach ($objects as $object) {
			$self = ($object['@self'] ?? []);
			$bySchemaSlug[($self['schema'] ?? '') . ':' . ($self['slug'] ?? '')] = true;
			$bySlug[($self['slug'] ?? '')] = true;
		}

		$problems = [];
		foreach ($objects as $object) {
			$self = ($object['@self'] ?? []);
			$properties = ($schemas[($self['schema'] ?? '')]['properties'] ?? []);
			foreach ($object as $key => $value) {
				$property = ($properties[$key] ?? []);
				$format = ($property['format'] ?? ($property['items']['format'] ?? null));
				if ($key === '@self' || $format !== 'uuid') {
					continue;
				}

				$target = ($property['$ref'] ?? ($property['items']['$ref'] ?? null));
				foreach ((is_array($value) === true ? $value : [$value]) as $reference) {
					$problem = $this->problemWith(
						reference: $reference,
						target: $target,
						bySchemaSlug: $bySchemaSlug,
						bySlug: $bySlug
					);
					if ($problem !== null) {
						$problems[] = ($self['schema'] ?? '?') . ':' . ($self['slug'] ?? '?') . '.' . $key . ' ' . $problem;
					}
				}
			}//end foreach
		}//end foreach

		$this->assertSame(
			[],
			$problems,
			'Example references must be a uuid or @ref:<schema>:<slug> naming an example in the set; the importer refuses a bare slug.'
		);
	}//end testEveryExampleReferenceIsAUuidOrAResolvableToken()

	/**
	 * What is wrong with one reference, or null when it resolves.
	 *
	 * @param mixed                $reference    The value.
	 * @param string|null          $target       The referenced schema, when the property names one.
	 * @param array<string, bool>  $bySchemaSlug Examples by "schema:slug".
	 * @param array<string, bool>  $bySlug       Examples by slug.
	 *
	 * @return string|null The problem.
	 */
	private function problemWith(mixed $reference, ?string $target, array $bySchemaSlug, array $bySlug): ?string {
		if (is_string($reference) === false || $reference === '' || preg_match(self::UUID, $reference) === 1) {
			return null;
		}

		if (str_starts_with($reference, '@ref:') === false) {
			return 'holds the bare slug "' . $reference . '"';
		}

		$token = substr($reference, strlen('@ref:'));
		if (str_contains($token, ':') === false) {
			return (isset($bySlug[$token]) === true) ? null : 'names "' . $token . '", which no example has';
		}

		[$schema, $slug] = explode(':', $token, 2);
		if ($target !== null && $schema !== $target) {
			return 'names schema "' . $schema . '" where the property references "' . $target . '"';
		}

		return (isset($bySchemaSlug[$schema . ':' . $slug]) === true) ? null : 'names "' . $token . '", which no example has';
	}//end problemWith()
}//end class
