<?php

/**
 * PortalContributionProvider manifest golden.
 *
 * Pins the manifest every audience receives, key for key and in order, as
 * portaliq reads it: the collections with their scope fields and trust
 * levels, the actions with their writable fields and server-set values, the
 * notifications and the pages. The manifests are built from declarations,
 * so any change in how they are assembled must leave this file unchanged.
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

use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * The manifest of every audience, exactly.
 *
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses \OCA\Dossiq\Portal\PortalPages
 * @uses \OCA\Dossiq\Portal\CitizenManifest
 * @uses \OCA\Dossiq\Portal\PortalConversation
 */
class PortalContributionManifestGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../golden/portal-contribution-manifests.json';

	/**
	 * The manifests by audience, as the provider builds them.
	 *
	 * @return array<string, mixed>
	 */
	private function manifests(): array {
		$provider = new PortalContributionProvider();
		$manifests = [];
		foreach (array_merge($provider->getAudiences(), ['employee', '']) as $audience) {
			$manifests[$audience] = $provider->getContribution(['audience' => $audience]);
		}

		return $manifests;
	}//end manifests()

	/**
	 * Every audience gets exactly the manifest it got before, keys in order.
	 *
	 * @return void
	 */
	public function testEveryAudienceGetsTheSameManifest(): void {
		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);

		$this->assertSame($golden, json_decode((string)json_encode($this->manifests()), true));
		$this->assertSame(
			(string)file_get_contents(self::GOLDEN),
			json_encode($this->manifests(), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
		);
	}//end testEveryAudienceGetsTheSameManifest()
}//end class
