<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Settings\DependencyApps;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The integrations tab names each sibling app and the id it was found under.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class DependencyAppsTest extends TestCase {

	/**
	 * Read the rows against an instance holding the given app ids.
	 *
	 * @param array<int, string> $installed The installed app ids.
	 *
	 * @return array<string, array<string, mixed>> The rows by product name.
	 */
	private function rows(array $installed): array {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, $installed, true)
		);

		return array_column((new DependencyApps())->read(appManager: $appManager), null, 'name');
	}//end rows()

	/**
	 * An app installed under its former id is present and names that id.
	 *
	 * @return void
	 */
	public function testAnAppFoundUnderItsFormerIdNamesThatId(): void {
		$rows = $this->rows(installed: ['openregister', 'openconnector']);

		$this->assertTrue(condition: $rows['Integriq']['present']);
		$this->assertSame(expected: 'openconnector', actual: $rows['Integriq']['appId']);
		$this->assertTrue(condition: $rows['Openregister']['present']);
	}//end testAnAppFoundUnderItsFormerIdNamesThatId()

	/**
	 * A missing app reads as not installed under its canonical id.
	 *
	 * @return void
	 */
	public function testAMissingAppIsNotPresent(): void {
		$rows = $this->rows(installed: []);

		$this->assertFalse(condition: $rows['Humaniq']['present']);
		$this->assertSame(expected: 'humaniq', actual: $rows['Humaniq']['appId']);
	}//end testAMissingAppIsNotPresent()

	/**
	 * Openregister and Integriq are needed, the other four work alongside.
	 *
	 * @return void
	 */
	public function testOnlyOpenregisterAndIntegriqAreRequired(): void {
		$required = array_keys(array_filter(array_column($this->rows(installed: []), 'required', 'name')));

		$this->assertSame(expected: ['Openregister', 'Integriq'], actual: $required);
	}//end testOnlyOpenregisterAndIntegriqAreRequired()
}//end class
