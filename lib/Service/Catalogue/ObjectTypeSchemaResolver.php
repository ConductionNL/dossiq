<?php

/**
 * Dossiq Object Type Schema Resolver
 *
 * Reads the OpenRegister schema behind an object a case links, from the link's
 * own `objectUrl`. A case-object link names its object by URL; when that URL
 * is OpenRegister's objects endpoint (`/apps/openregister/api/objects/<register>/
 * <schema>/<id>`) the schema is named in it, so the catalogue can show the
 * schema's title, how many properties it has, whether its objects go on a
 * map and how many objects the register holds in it. The schema is resolved within the register the URL names, never
 * across registers, because a slug is only unique inside its register. A
 * free-text object type, a URL of another system, or a schema that cannot be
 * read is answered with null: nothing is guessed, and one unreadable schema
 * never fails the catalogue.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Catalogue
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
 *
 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Catalogue;

use Exception;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the schema an OpenRegister object URL names.
 *
 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
 */
class ObjectTypeSchemaResolver {

	/**
	 * The part of an OpenRegister objects URL that names register and schema.
	 *
	 * @var string
	 */
	private const OBJECTS_PATH = '#/apps/openregister/api/objects/([^/?\#]+)/([^/?\#]+)(?:/|$)#';

	/**
	 * The OpenRegister property type of a point or shape on the map.
	 *
	 * @var string
	 */
	private const GEO_TYPE = 'geo';

	/**
	 * The OpenRegister service that resolves a schema within a register.
	 *
	 * @var string
	 */
	private const RESOLVER = 'OCA\\OpenRegister\\Service\\RegisterScopedSchemaResolver';

	/**
	 * The OpenRegister service that counts the objects of a schema.
	 *
	 * @var string
	 */
	private const OBJECTS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * What {@see self::read()} answers for a schema OpenRegister would not
	 * give: a register that does not resolve, a schema the register does not
	 * carry, or a read that was refused.
	 *
	 * @var array{unreadable: true}
	 */
	private const UNREADABLE = ['unreadable' => true];

	/**
	 * Schemas already read in this request, by register and schema as the URL names them.
	 *
	 * @var array<string, array{id: string, title: string, properties: int, views: array<int, string>, objects: int|null}|array{unreadable: true}|null>
	 */
	private array $seen = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, for OpenRegister's schema mapper.
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The schema an object URL names: its title, its number of properties,
	 * the views its objects support (`map` when a property is a geo type,
	 * `table` always) and the number of objects the register holds in it
	 * (null when that count cannot be read), or null when the URL names no
	 * OpenRegister schema or the schema cannot be read.
	 *
	 * @param string $objectUrl The link's object URL.
	 *
	 * @return array{id: string, title: string, properties: int, views: array<int, string>, objects: int|null}|null The schema, or null.
	 *
	 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
	 */
	public function describe(string $objectUrl): ?array {
		$named = self::segmentsOf(objectUrl: $objectUrl);
		if ($named === null) {
			return null;
		}

		$key = $named['register'] . '/' . $named['schema'];
		if (array_key_exists($key, $this->seen) === false) {
			$this->seen[$key] = $this->read(register: $named['register'], schema: $named['schema']);
		}

		$described = $this->seen[$key];
		if ($described === null || isset($described['unreadable']) === true) {
			return null;
		}

		return $described;
	}//end describe()

	/**
	 * Whether an object URL names an OpenRegister schema at all.
	 *
	 * @param string $objectUrl The URL.
	 *
	 * @return bool True for an OpenRegister objects URL.
	 */
	public function namesSchema(string $objectUrl): bool {
		return self::schemaNamedIn(objectUrl: $objectUrl) !== '';
	}//end namesSchema()

	/**
	 * The schema id or slug an OpenRegister objects URL names, or ''.
	 *
	 * @param string $objectUrl The URL.
	 *
	 * @return string The schema id or slug.
	 */
	public static function schemaNamedIn(string $objectUrl): string {
		return (self::segmentsOf(objectUrl: $objectUrl)['schema'] ?? '');
	}//end schemaNamedIn()

	/**
	 * The register and schema an OpenRegister objects URL names, or null.
	 *
	 * @param string $objectUrl The URL.
	 *
	 * @return array{register: string, schema: string}|null The two segments, decoded.
	 */
	private static function segmentsOf(string $objectUrl): ?array {
		if (preg_match(self::OBJECTS_PATH, $objectUrl, $match) !== 1) {
			return null;
		}

		return ['register' => rawurldecode($match[1]), 'schema' => rawurldecode($match[2])];
	}//end segmentsOf()

	/**
	 * Read one schema within its register and shape what the catalogue shows
	 * of it.
	 *
	 * A schema OpenRegister will not give answers {@see self::UNREADABLE}
	 * rather than an exception, so the one row stays empty and the rest of
	 * the catalogue still loads.
	 *
	 * @param string $register The register id or slug the URL names.
	 * @param string $schema   The schema id or slug the URL names.
	 *
	 * @return array{id: string, title: string, properties: int, views: array<int, string>, objects: int|null}|array{unreadable: true}|null The schema,
	 *                                                                                                     UNREADABLE, or null without OpenRegister.
	 */
	private function read(string $register, string $schema): ?array {
		if ($this->container->has(self::RESOLVER) === false) {
			return null;
		}

		try {
			$pair   = $this->container->get(self::RESOLVER)->resolvePair(registerRef: $register, schemaRef: $schema);
			$entity = $pair['schema'];
		} catch (Exception $e) {
			$this->logger->info(
				'Dossiq: the schema a linked object names could not be read',
				['register' => $register, 'schema' => $schema, 'reason' => $e->getMessage()]
			);
			return self::UNREADABLE;
		}

		$properties = (array)$entity->getProperties();

		$schemaId = '';
		if (method_exists($entity, 'getId') === true) {
			$schemaId = (string)$entity->getId();
		}

		return [
			'id'         => $schemaId,
			'title'      => (string)($entity->getTitle() ?? $schema),
			'properties' => count($properties),
			'views'      => self::viewsOf(properties: $properties),
			'objects'    => $this->objectCount(register: ($pair['register'] ?? null), schema: $entity),
		];
	}//end read()

	/**
	 * How many objects the register holds in the schema, or null when
	 * OpenRegister cannot count them. A count that fails leaves the number
	 * out rather than showing zero.
	 *
	 * @param object|null $register The resolved register.
	 * @param object      $schema   The resolved schema.
	 *
	 * @return int|null The count.
	 *
	 * @spec openspec/changes/catalogue-object-count/specs/catalogue/spec.md
	 */
	private function objectCount(?object $register, object $schema): ?int {
		if ($register === null || $this->container->has(self::OBJECTS) === false) {
			return null;
		}

		try {
			$objects = $this->container->get(self::OBJECTS);
			$objects->setRegister($register);
			$objects->setSchema($schema);

			return (int)$objects->count(['filters' => []]);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: the objects of a linked schema could not be counted',
				['reason' => $e->getMessage()]
			);
			return null;
		}
	}//end objectCount()

	/**
	 * The views a schema's objects support: `map` when a property is a geo
	 * type, then `table`, which every object list is.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string> The views.
	 */
	public static function viewsOf(array $properties): array {
		foreach ($properties as $property) {
			if (is_array($property) === true && ($property['type'] ?? '') === self::GEO_TYPE) {
				return ['map', 'table'];
			}
		}

		return ['table'];
	}//end viewsOf()
}//end class
