<?php

/**
 * Dossiq Object Type Catalogue
 *
 * The object types the catalogue page lists: the schemas in dossiq's own
 * register, and nothing else. Other apps' schemas and OpenRegister's nc-*
 * virtual schemas are never listed, because the list reads the register's
 * own schema references rather than every schema on the instance.
 *
 * A case type does not reference an object type, so no "used in N case types"
 * count is derived here.
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
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Catalogue;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Lists the schemas in dossiq's own register as catalogue rows.
 *
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */
class ObjectTypeCatalogue {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig App config holding dossiq's register id.
	 * @param ContainerInterface $container The DI container, used to reach
	 *                                      OpenRegister's mappers.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The object types in dossiq's register, sorted by title.
	 *
	 * Returns an empty list when no register is configured. A register or
	 * schema OpenRegister cannot read throws, so the caller can say the list
	 * could not be read instead of showing it as empty.
	 *
	 * @return array<int, array{id: string, slug: string, title: string, description: string, version: string, properties: int}> The rows.
	 *
	 * @throws \Throwable When OpenRegister is absent or cannot read the register or its schemas.
	 *
	 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
	 */
	public function list(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return [];
		}

		$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
		$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		$register = $registerMapper->find($registerId, false, false);

		$ids = [];
		foreach ((array)$register->getSchemas() as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		if ($ids === []) {
			return [];
		}

		$rows = [];
		foreach ($schemaMapper->findMultiple($ids, false, false) as $schema) {
			$rows[] = $this->row(schema: $schema);
		}

		usort(
			$rows,
			static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title'])
		);

		return $rows;
	}//end list()

	/**
	 * One catalogue row for a schema. An untitled schema reads as its slug.
	 *
	 * @param object $schema An OpenRegister schema.
	 *
	 * @return array{id: string, slug: string, title: string, description: string, version: string, properties: int} The row.
	 */
	private function row(object $schema): array {
		$slug = (string)($schema->getSlug() ?? '');
		$title = (string)($schema->getTitle() ?? '');
		if ($title === '') {
			$title = $slug;
		}

		return [
			'id' => (string)$schema->getId(),
			'slug' => $slug,
			'title' => $title,
			'description' => (string)($schema->getDescription() ?? ''),
			'version' => (string)($schema->getVersion() ?? ''),
			'properties' => count((array)($schema->getProperties() ?? [])),
		];
	}//end row()
}//end class
