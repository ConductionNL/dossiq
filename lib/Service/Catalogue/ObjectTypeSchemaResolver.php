<?php

/**
 * Dossiq Object Type Schema Resolver
 *
 * Reads the OpenRegister schema behind an object a case links, from the link's
 * own `objectUrl`. A case-object link names its object by URL; when that URL
 * is OpenRegister's objects endpoint (`/apps/openregister/api/objects/<register>/
 * <schema>/<id>`) the schema is named in it, so the catalogue can show the
 * schema's title, how many properties it has and whether its objects go on a
 * map. A free-text object type, or a URL of another system, names no schema
 * and is answered with null: nothing is guessed.
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
	 * Schemas already read in this request, by the name in the URL.
	 *
	 * @var array<string, array{title: string, properties: int, views: array<int, string>}|null>
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
	 * The schema an object URL names: its title, its number of properties and
	 * the views its objects support (`map` when a property is a geo type,
	 * `table` always), or null when the URL names no OpenRegister schema or
	 * the schema cannot be read.
	 *
	 * @param string $objectUrl The link's object URL.
	 *
	 * @return array{title: string, properties: int, views: array<int, string>}|null The schema, or null.
	 *
	 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
	 */
	public function describe(string $objectUrl): ?array {
		$schema = self::schemaNamedIn(objectUrl: $objectUrl);
		if ($schema === '') {
			return null;
		}

		if (array_key_exists($schema, $this->seen) === false) {
			$this->seen[$schema] = $this->read(schema: $schema);
		}

		return $this->seen[$schema];
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
		if (preg_match(self::OBJECTS_PATH, $objectUrl, $match) !== 1) {
			return '';
		}

		return rawurldecode($match[2]);
	}//end schemaNamedIn()

	/**
	 * Read one schema and shape what the catalogue shows of it.
	 *
	 * @param string $schema The schema id or slug.
	 *
	 * @return array{title: string, properties: int, views: array<int, string>}|null The schema, or null.
	 */
	private function read(string $schema): ?array {
		try {
			$entity = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: the schema a linked object names could not be read',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$properties = (array)$entity->getProperties();

		return [
			'title'      => (string)($entity->getTitle() ?? $schema),
			'properties' => count($properties),
			'views'      => self::viewsOf(properties: $properties),
		];
	}//end read()

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
