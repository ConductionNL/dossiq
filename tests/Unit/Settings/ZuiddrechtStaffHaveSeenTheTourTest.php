<?php

/**
 * The Zuiddrecht staff have seen the getting-started tour.
 *
 * The example-set fragment writes the tour's per-user value for every staff
 * member; this holds the key to the manifest's `completionConfigKey` (with
 * the `pref_` prefix PreferencesController stores it under), the value to
 * the manifest's `version` (what nextcloud-vue compares the seen version
 * against), and the list to the nine staff members.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/zuiddrecht-staff-have-seen-the-tour/specs/getting-started-tour/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Onboarding\WalkthroughCompletion;
use PHPUnit\Framework\TestCase;

/**
 * The fragment's tour preferences match the manifest.
 *
 * @spec openspec/changes/zuiddrecht-staff-have-seen-the-tour/specs/getting-started-tour/spec.md
 */
class ZuiddrechtStaffHaveSeenTheTourTest extends TestCase {

	/**
	 * A JSON file of the app, decoded.
	 *
	 * @param string $path The path from the app root.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function json(string $path): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../' . $path), true);
	}//end json()

	/**
	 * Every staff member has seen the tour at the manifest's version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zuiddrecht-staff-have-seen-the-tour/specs/getting-started-tour/spec.md
	 */
	public function testEveryStaffMemberHasSeenTheTourAtTheManifestVersion(): void {
		$manifest = $this->json(path: 'src/manifest.json');
		$fragment = $this->json(path: 'lib/Settings/example-sets/zuiddrecht.json');
		$key      = 'pref_' . $manifest['walkthrough']['completionConfigKey'];

		$this->assertSame('pref_' . WalkthroughCompletion::PREFERENCE_KEY, $key);

		$users = [];
		foreach ($fragment['userPreferences'] as $entry) {
			$this->assertArrayNotHasKey('app', $entry, 'the loader writes the fragment\'s own app');
			if ($entry['key'] !== $key) {
				// Other preferences, such as a menu of saved views, are not the tour's.
				continue;
			}

			$this->assertSame($manifest['version'], $entry['value'], 'the tour compares the seen version against the manifest version');
			$users[] = $entry['user'];
		}

		$staff = ['daan', 'esra', 'thomas', 'marleen', 'ilse', 'bram', 'petra', 'samira', 'noor'];
		$this->assertSame(
			array_map(static fn (string $member): string => '{{cast:' . $member . '.uid}}', $staff),
			$users
		);
	}//end testEveryStaffMemberHasSeenTheTourAtTheManifestVersion()
}//end class
