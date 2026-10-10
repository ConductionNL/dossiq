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
use OCA\Dossiq\Settings\EmailSettings;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;

/**
 * The delegated email registration delegates, and draws nothing.
 *
 * 🔴 ITS FORM WAS A SECOND COPY OF A SECTION. It mounted a bundle of its own
 * that drew "Case email: shared mailbox" again at the bottom of the admin
 * page, in a wider box, holding only the application information (round-4
 * cloud check). The panel is a section of AdminRoot; this form is empty.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
class EmailSettingsTest extends TestCase {

	/**
	 * The form's template outputs nothing and loads no script.
	 *
	 * The template is rendered with output buffering rather than read as
	 * text, so a template that printed something through PHP still fails.
	 *
	 * @return void
	 */
	public function testTheFormDrawsNothing(): void {
		$response = (new EmailSettings())->getForm();
		$this->assertSame(Application::APP_ID, $response->getApp());

		$file = dirname(__DIR__, 3) . '/templates/' . $response->getTemplateName() . '.php';
		$this->assertFileExists($file);

		ob_start();
		include $file;
		$output = (string)ob_get_clean();

		$this->assertSame('', trim($output), 'the delegated email form must draw nothing');
		$this->assertStringNotContainsString(
			'addScript',
			(string)file_get_contents($file),
			'no bundle of its own: the panel is a section of AdminRoot'
		);
	}//end testTheFormDrawsNothing()

	/**
	 * It still delegates the mailbox keys, which is why it stays registered.
	 *
	 * @return void
	 */
	public function testItStillDelegatesTheMailboxKeys(): void {
		$settings = new EmailSettings();
		$this->assertInstanceOf(IDelegatedSettings::class, $settings);

		$keys = $settings->getAuthorizedAppConfig()[Application::APP_ID] ?? [];
		$this->assertContains('email_mail_account_id', $keys);
		$this->assertContains('email_imap_folder', $keys);
	}//end testItStillDelegatesTheMailboxKeys()
}//end class
