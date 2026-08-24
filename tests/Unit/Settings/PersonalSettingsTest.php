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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Settings\PersonalSettings;
use OCP\Settings\ISettings;
use PHPUnit\Framework\TestCase;

/**
 * Pins the personal-settings registration.
 *
 * Small surface, but every value here is load-bearing: a wrong template name
 * renders an empty page, and a section id the personal-settings page cannot
 * resolve makes the form vanish silently rather than error.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
 */
class PersonalSettingsTest extends TestCase {

	/**
	 * It is a PERSONAL setting, not an admin one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testItIsAPersonalSetting(): void {
		$this->assertInstanceOf(ISettings::class, new PersonalSettings());

	}//end testItIsAPersonalSetting()

	/**
	 * The form renders procest's personal-settings template.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testFormRendersThePersonalTemplate(): void {
		$response = (new PersonalSettings())->getForm();

		$this->assertSame(Application::APP_ID, $response->getApp());
		$this->assertSame('settings/personal', $response->getTemplateName());

	}//end testFormRendersThePersonalTemplate()

	/**
	 * The form belongs to procest's own settings section.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testSectionIsProcest(): void {
		$this->assertSame('dossiq', (new PersonalSettings())->getSection());

	}//end testSectionIsProcest()

	/**
	 * The priority is a plain ordering integer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
	 */
	public function testPriorityIsAnInteger(): void {
		$this->assertSame(50, (new PersonalSettings())->getPriority());

	}//end testPriorityIsAnInteger()

}//end class
