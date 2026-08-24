<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Sections
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Sections;

use OCA\Dossiq\Sections\PersonalSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\TestCase;

/**
 * Pins the personal-settings section.
 *
 * This class exists ONLY because Nextcloud keeps the admin and personal section
 * registries apart: a personal form registered against the admin section id
 * resolves to nothing and renders nowhere. These assertions are what stop that
 * from being "fixed" by deleting the class and reusing SettingsSection.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
 */
class PersonalSectionTest extends TestCase {

	/**
	 * Build the section with stubbed collaborators.
	 *
	 * @return PersonalSection The section under test.
	 */
	private function section(): PersonalSection {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');

		return new PersonalSection($l, $urls);
	}//end section()

	/**
	 * It is an icon section, so it can carry procest's glyph.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testItIsAnIconSection(): void {
		$this->assertInstanceOf(IIconSection::class, $this->section());

	}//end testItIsAnIconSection()

	/**
	 * The id matches the one PersonalSettings::getSection() returns.
	 *
	 * If these two ever drift apart the form silently renders nowhere, which is
	 * exactly the failure this class was added to prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testIdMatchesTheSettingsSection(): void {
		$this->assertSame('dossiq', $this->section()->getID());

	}//end testIdMatchesTheSettingsSection()

	/**
	 * The name is run through translation rather than hardcoded at the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testNameIsTranslated(): void {
		$this->assertSame('Dossiq', $this->section()->getName());

	}//end testNameIsTranslated()

	/**
	 * Ordering priority.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testPriority(): void {
		$this->assertSame(75, $this->section()->getPriority());

	}//end testPriority()

	/**
	 * The icon resolves through the URL generator, not a literal path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testIconComesFromTheUrlGenerator(): void {
		$this->assertStringContainsString('app-dark.svg', $this->section()->getIcon());

	}//end testIconComesFromTheUrlGenerator()

	/**
	 * The icon is requested for the LIVE app id.
	 *
	 * This asserts the argument, not the return value, and that distinction is
	 * the whole point: the test above stubs imagePath() with a canned string,
	 * so it passes no matter which app is asked for. A stale id shipped behind
	 * it and was not a cosmetic defect — imagePath() throws for an app that
	 * does not exist, and Nextcloud calls getIcon() on every section while
	 * assembling the settings navigation, so it returned 500 for every
	 * /settings/* page while the app's own pages kept working.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testIconIsRequestedForTheCurrentAppId(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())
			->method('imagePath')
			->with('dossiq', 'app-dark.svg')
			->willReturn('/apps/dossiq/img/app-dark.svg');

		(new PersonalSection($l, $urls))->getIcon();

	}//end testIconIsRequestedForTheCurrentAppId()

}//end class
