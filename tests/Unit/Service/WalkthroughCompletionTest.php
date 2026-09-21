<?php

/**
 * The tour is per surface and per person, and a step that lost its surface says so.
 *
 * D-4 assumed `walkthrough_completed_version` was one key for the whole app,
 * so a handler joining in month nine would be taught nothing. It is not: the
 * library's `useWalkthrough` addresses `completionConfigKey` through
 * `/apps/{appId}/api/preferences/{key}`, which is a per-USER preference, and
 * `composeSteps` filters each step on its own `sinceVersion` against that
 * person's last-seen version. Both properties the change asks for are already
 * true, so what this file does is hold them: a manifest that stops declaring
 * `sinceVersion` per step, or a completion key that moves to app config, would
 * quietly take them away again.
 *
 * What is genuinely missing is D-5, and that half is built here.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Setup\FirstRunReadiness;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * What the platform guarantees, and the broken step dossiq reports itself.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */
class WalkthroughCompletionTest extends TestCase {

	/**
	 * The app manifest as shipped.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
	}//end manifest()

	/**
	 * The readiness reader, which also reports the broken tour steps.
	 *
	 * @return FirstRunReadiness The reader.
	 */
	private function readiness(): FirstRunReadiness {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new InMemoryRegister());
		$settings->method('getConfigValue')->willReturn('');
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new FirstRunReadiness(settingsService: $settings, appConfig: $appConfig);
	}//end readiness()

	/**
	 * Completion is addressed per person, not once for the whole app.
	 *
	 * The key is read and written through the app's per-user preferences
	 * endpoint. A key that moved to app config would make the first person to
	 * finish the tour the last person to be offered it.
	 *
	 * This test checks the manifest names the key. That the shared runner
	 * resolves it to a per-user preference is asserted in
	 * tests/vitest/walkthroughCompletionIsPerPerson.spec.js, because the
	 * library lives in node_modules and CI installs no node packages in the
	 * PHPUnit cells: read from here, the file came back empty and the check
	 * could only fail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testCompletionIsPerPerson(): void {
		$walkthrough = ($this->manifest()['walkthrough'] ?? []);

		$this->assertSame('walkthrough_completed_version', $walkthrough['completionConfigKey'] ?? '');
	}//end testCompletionIsPerPerson()

	/**
	 * Every step carries its own version, so a later one is offered on its own.
	 *
	 * This is what makes a surface added later reach somebody who finished the
	 * rest: the runner compares each step's `sinceVersion` against the
	 * person's seen version, and a step with no version defaults to 0.0.0,
	 * which every returning person has already passed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testEveryStepCarriesItsOwnVersion(): void {
		$tours = ($this->manifest()['walkthrough']['tours'] ?? []);
		$this->assertNotEmpty($tours, 'no tour is declared, so this file proves nothing');

		foreach ($tours as $tour) {
			foreach (($tour['steps'] ?? []) as $step) {
				$this->assertArrayHasKey(
					'sinceVersion',
					$step,
					'step "' . ($step['id'] ?? '?') . '" carries no sinceVersion, so it is offered to nobody who has seen the tour'
				);
			}
		}
	}//end testEveryStepCarriesItsOwnVersion()

	/**
	 * Every page or nav target a step names is a surface this app has.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testNoStepNamesASurfaceThatIsGone(): void {
		$missing = array_values(array_filter(
			$this->readiness()->brokenTourSteps(),
			static fn (array $step): bool => $step['state'] === 'missing'
		));

		$this->assertSame(
			[],
			$missing,
			"These tour steps name a surface the app no longer has:\n"
			. implode("\n", array_map(
				static fn (array $s): string => $s['tour'] . '/' . $s['step'] . ' names ' . $s['kind'] . ' "' . $s['surface'] . '"',
				$missing
			))
		);
	}//end testNoStepNamesASurfaceThatIsGone()

	/**
	 * A step naming a page that is gone is reported, rather than skipped.
	 *
	 * The test above passes on a tree where every step is fine, and a check
	 * that cannot fail reports the same green as one that passed. So this runs
	 * the same comparison over a tour that DOES name a missing page and
	 * asserts it is caught, naming the surface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testAStepThatLostItsSurfaceIsCaughtAndNamed(): void {
		$manifest = $this->manifest();
		$this->assertNotContains('APageNobodyShips', array_column(($manifest['pages'] ?? []), 'id'));

		// The shipped tour, with one step pointed at a page this app does not
		// have. Nothing else changes, so a green here would mean the scan
		// cannot see a broken step at all.
		$manifest['walkthrough']['tours'][0]['steps'][1]['target'] = [
			'kind' => 'nav-item',
			'ref' => 'APageNobodyShips',
		];

		$missing = array_values(array_filter(
			$this->readiness()->brokenTourSteps(manifest: $manifest),
			static fn (array $step): bool => $step['state'] === 'missing'
		));

		$this->assertCount(1, $missing, 'the scan did not see a step naming a page that is gone');
		$this->assertSame('APageNobodyShips', $missing[0]['surface']);
		$this->assertSame($manifest['walkthrough']['tours'][0]['steps'][1]['id'], $missing[0]['step']);
	}//end testAStepThatLostItsSurfaceIsCaughtAndNamed()

	/**
	 * A DOM target is reported as unverifiable, not as broken.
	 *
	 * `element` names a test id and `selector` a raw CSS selector; no manifest
	 * resolves either. Counting one as broken would put a permanent false
	 * finding in front of an administrator, and counting it as fine would
	 * claim a check that did not happen. It says which it is instead.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testADomTargetIsUnverifiableRatherThanBroken(): void {
		$unverifiable = array_values(array_filter(
			$this->readiness()->brokenTourSteps(),
			static fn (array $step): bool => $step['state'] === 'unverifiable'
		));

		$this->assertNotEmpty($unverifiable, 'no DOM target was classified, so the scan did not run');
		foreach ($unverifiable as $step) {
			$this->assertNotContains($step['kind'], ['page', 'nav-item']);
			$this->assertNotSame('', $step['surface']);
		}
	}//end testADomTargetIsUnverifiableRatherThanBroken()

}//end class
