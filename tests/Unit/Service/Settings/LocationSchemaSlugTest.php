<?php

/**
 * The location schema key is set from the schema the register declares.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use PHPUnit\Framework\TestCase;

/**
 * Without this mapping `location_schema` stays empty and the BAG check on a location save never runs.
 *
 * @coversNothing
 */
class LocationSchemaSlugTest extends TestCase {

	/**
	 * The register's location schema is `case-location`, and it backs `location_schema`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/bag-location-save-validation/spec.md#requirement-a-refused-location-save-names-why-by-code
	 */
	public function testTheCaseLocationSchemaBacksTheLocationKey(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/dossiq_register.json'), true);

		self::assertArrayHasKey('case-location', $register['components']['schemas']);
		self::assertSame('location_schema', SchemaSlugMap::SLUG_TO_CONFIG_KEY['case-location'] ?? null);
	}//end testTheCaseLocationSchemaBacksTheLocationKey()
}//end class
