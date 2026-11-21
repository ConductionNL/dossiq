<?php

/**
 * A schema entity carrying whatever fields a test hands it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\Db\Schema;

/**
 * OpenRegister's form destination takes a Schema entity; a test that only needs a few
 * readable fields builds one here.
 */
final class SchemaRow {

	/**
	 * A schema with these public fields.
	 *
	 * @param array<string, mixed> $fields The fields, read back as public properties.
	 *
	 * @return Schema The schema.
	 */
	public static function of(array $fields): Schema {
		$schema = new #[\AllowDynamicProperties] class extends Schema {

			/**
			 * The schema's properties, readable from the test.
			 *
			 * @var array<int|string, mixed>|null
			 */
			public ?array $properties = [];
		};
		foreach ($fields as $name => $value) {
			$schema->$name = $value;
		}

		return $schema;
	}//end of()
}//end class
