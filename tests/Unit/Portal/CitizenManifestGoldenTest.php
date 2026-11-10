<?php

/**
 * CitizenManifest golden.
 *
 * Pins the resident's actions, collections and case collection key for key
 * and in order, with and without the case types the filing actions open,
 * so the declarations can be regrouped without portaliq reading anything
 * different.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CitizenManifest;
use PHPUnit\Framework\TestCase;

/**
 * The resident's manifest declarations, exactly.
 *
 * @covers \OCA\Dossiq\Portal\CitizenManifest
 * @uses \OCA\Dossiq\Portal\PortalConversation
 */
class CitizenManifestGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../golden/citizen-manifest.json';

	/**
	 * The declarations with and without filing case types.
	 *
	 * @return array<string, mixed>
	 */
	public static function declarations(): array {
		$declared = [];
		$variants = [
			'none' => new CitizenManifest(),
			'filing' => new CitizenManifest(['createBezwaar' => ' ct-bezwaar ', 'createKlacht' => 'ct-klacht'], 'Uw zaken'),
		];
		foreach ($variants as $name => $manifest) {
			$declared[$name] = [
				'actions' => $manifest->actions(),
				'collections' => $manifest->collections(),
				'caseCollection' => $manifest->caseCollection(),
			];
		}

		return $declared;
	}//end declarations()

	/**
	 * Every declaration is exactly as before, keys in order.
	 *
	 * @return void
	 */
	public function testTheDeclarationsAreUnchanged(): void {
		$this->assertSame(
			(string)file_get_contents(self::GOLDEN),
			json_encode(self::declarations(), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
		);
	}//end testTheDeclarationsAreUnchanged()
}//end class
