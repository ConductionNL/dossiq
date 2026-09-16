<?php

/**
 * The declared readiness items and the reported ones agree, in both directions.
 *
 * Carried forward from REQ-SETUP-PRO-001, which learned it one layer up: an
 * item the screen renders that the status never reports cannot be answered,
 * and an item the status reports that no screen renders cannot be satisfied.
 * Both look like a working list until somebody tries to finish it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Setup\FirstRunReadiness;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The two lists agree, and every item leads to a screen that exists.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */
class SetupDeclaredMatchesReportedTest extends TestCase {

	/**
	 * The readiness reader over an empty instance.
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
	 * The declaration is not empty, so a green run means the reader ran.
	 *
	 * A reader that finds nothing passes every other test in this file, which
	 * is the shape of a check that cannot fail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testTheDeclarationIsNotEmpty(): void {
		$declared = $this->readiness()->declared();

		$this->assertCount(5, $declared, 'the declaration was not read, so this file proves nothing');
		$this->assertContains(
			'published-case-type',
			array_column($declared, 'id')
		);
	}//end testTheDeclarationIsNotEmpty()

	/**
	 * Every declared item is reported, and every reported item is declared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testTheTwoListsAgree(): void {
		$readiness = $this->readiness();

		$this->assertEqualsCanonicalizing(
			array_column($readiness->declared(), 'id'),
			array_column($readiness->report(), 'id')
		);
	}//end testTheTwoListsAgree()

	/**
	 * Every item names a screen, and every reader answers for a declared item.
	 *
	 * The second half is what keeps a reported item from being unsatisfiable:
	 * a report entry whose read raises "no reader is declared" is an item
	 * nobody can ever tick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testEveryReportedItemCanBeSatisfied(): void {
		foreach ($this->readiness()->report() as $item) {
			$this->assertNotSame('', $item['screen'], $item['id'] . ' names no screen');
			$this->assertStringNotContainsString(
				'no reader',
				$item['failure'],
				$item['id'] . ' is reported but no reader answers for it'
			);
		}
	}//end testEveryReportedItemCanBeSatisfied()

	/**
	 * Nothing in the readiness list is a wizard step.
	 *
	 * A readiness item that turned up in `steps` would become a prompt
	 * CnSetupWizard cannot fulfil, and `testEveryActionableManifestStepIsReported`
	 * compares steps against the manifest in both directions. The two lists are
	 * different kinds of thing and this keeps them apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testAReadinessItemIsNotAWizardStep(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$steps = array_column(($manifest['setup']['steps'] ?? []), 'id');

		foreach ($this->readiness()->declared() as $item) {
			$this->assertNotContains(
				$item['id'],
				$steps,
				$item['id'] . ' is declared both as a readiness item and as a wizard step'
			);
		}
	}//end testAReadinessItemIsNotAWizardStep()

	/**
	 * Only `register-check` is required, so no readiness item can block the app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function testOnlyRegisterCheckGates(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);

		$required = [];
		foreach (($manifest['setup']['steps'] ?? []) as $step) {
			if (($step['required'] ?? false) === true) {
				$required[] = $step['id'];
			}
		}

		$this->assertSame(['register-check'], $required);
	}//end testOnlyRegisterCheckGates()

}//end class
