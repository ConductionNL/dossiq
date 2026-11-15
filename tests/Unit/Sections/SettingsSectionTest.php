<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Sections;

use OCA\Dossiq\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The admin settings section Dossiq registers.
 *
 * @covers \OCA\Dossiq\Sections\SettingsSection
 */
class SettingsSectionTest extends TestCase {
	/**
	 * The section is identified as dossiq, translated, placed at 75 and shows the dark app icon.
	 *
	 * @return void
	 */
	public function testTheSectionDescribesItself(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Dossiq')->willReturn('Dossiq (vertaald)');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')->with('dossiq', 'app-dark.svg')->willReturn('/apps/dossiq/img/app-dark.svg');

		$section = new SettingsSection($l10n, $urls);

		self::assertSame('dossiq', $section->getID());
		self::assertSame('Dossiq (vertaald)', $section->getName());
		self::assertSame(75, $section->getPriority());
		self::assertSame('/apps/dossiq/img/app-dark.svg', $section->getIcon());
	}
}
