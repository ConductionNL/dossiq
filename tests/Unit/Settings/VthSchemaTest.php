<?php

/**
 * VTH Schema Unit Tests
 *
 * Validates that the dossiq_register.json schema configuration contains all
 * required VTH (Vergunningen, Toezicht, Handhaving) schema definitions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying the VTH workflow configuration schema.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 */
class VthSchemaTest extends TestCase {

	/**
	 * The decoded dossiq_register data.
	 *
	 * @var array
	 */
	private array $registerData;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$schemaFilePath = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$content = file_get_contents($schemaFilePath);
		$this->registerData = json_decode($content, true);

	}//end setUp()

	/**
	 * Test that all four VTH schemas are registered in dossiq_register.json.
	 *
	 * @return void
	 */
	public function testAllVthSchemasAreRegistered(): void {
		$schemas = $this->registerData['components']['schemas'];

		$vthSchemas = [
			'inspectieChecklist',
			'inspectieRapport',
			'handhavingsactie',
			'adviesAanvraag',
		];

		foreach ($vthSchemas as $schemaName) {
			$this->assertArrayHasKey(
				$schemaName,
				$schemas,
				"VTH schema '{$schemaName}' must be defined in dossiq_register.json"
			);
		}

	}//end testAllVthSchemasAreRegistered()

}//end class
