<?php

/**
 * A document may be created in any status a ZGW DRC client sends.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister starts a created object in its lifecycle's initial state unless
 * the schema lists other start states in `createStates`. A DRC client may
 * create an enkelvoudiginformatieobject as ter_vaststelling, definitief or
 * gearchiveerd, which the ZGW mapping stores as for_determination, final and
 * archived.
 *
 * @coversNothing
 */
class DocumentCreateStatesTest extends TestCase {

	/**
	 * The document lifecycle starts in in_bewerking and accepts the other three on create.
	 *
	 * @return void
	 */
	public function testDocumentCreateStates(): void {
		$schema    = ShippedRegisterSchema::schema('document');
		$lifecycle = $schema['configuration']['x-openregister-lifecycle'];

		$this->assertSame('in_bewerking', $lifecycle['initial']);
		$this->assertSame(['for_determination', 'final', 'archived'], $lifecycle['createStates'] ?? null);
		foreach ($lifecycle['createStates'] as $state) {
			$this->assertContains($state, $schema['properties']['status']['enum']);
		}

		$this->assertTrue(version_compare((string)$schema['version'], '1.2.0', '>='), 'the document schema version moved with createStates');
	}//end testDocumentCreateStates()

	/**
	 * The register version moved with the change.
	 *
	 * @return void
	 */
	public function testRegisterVersion(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);

		$this->assertTrue(version_compare((string)$register['info']['version'], '0.20.91', '>='));
	}//end testRegisterVersion()
}//end class
