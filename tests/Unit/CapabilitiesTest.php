<?php

/**
 * Tests for Capabilities: the conflict card waits for conflictResolution.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit;

use OCA\Dossiq\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Dossiq announces the resolve endpoint, and the composition root registers it.
 *
 * @covers \OCA\Dossiq\Capabilities
 */
class CapabilitiesTest extends TestCase {

	/**
	 * The conflict card reads exactly this key.
	 *
	 * @return void
	 */
	public function testConflictResolutionIsAnnounced(): void {
		$this->assertSame(['dossiq' => ['conflictResolution' => true]], (new Capabilities())->getCapabilities());
	}//end testConflictResolutionIsAnnounced()

	/**
	 * Without the registration Nextcloud never asks the class.
	 *
	 * @return void
	 */
	public function testTheApplicationRegistersIt(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		$this->assertStringContainsString('registerCapability(\\OCA\\Dossiq\\Capabilities::class)', $source);
	}//end testTheApplicationRegistersIt()
}//end class
