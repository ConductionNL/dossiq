<?php

/**
 * The template library ships the Woo decision letters DqDocumentGenereren
 * offers: partly public (version 3) and public (version 2).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shipped template content; the template library spec covers the format
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Woo decision letters.
 */
class WooDecisionTemplatesTest extends TestCase {

	/**
	 * Both letters are document templates with a decision and the objection clause, in their version.
	 *
	 * @return void
	 */
	public function testTheWooDecisionLettersAreShipped(): void {
		$dir = __DIR__.'/../../../lib/Settings/templates/';
		foreach (['woo-besluit-deels-openbaar' => '3.0.0', 'woo-besluit-openbaar' => '2.0.0'] as $id => $version) {
			$template = json_decode((string)file_get_contents($dir.$id.'.json'), true);
			$this->assertSame(expected: [$id, 'document', $version], actual: [$template['id'], $template['category'], $template['version']]);
			$this->assertStringContainsString(needle: 'Besluit', haystack: $template['body']);
			$this->assertStringContainsString(needle: 'bezwaar', haystack: $template['body']);
		}
	}//end testTheWooDecisionLettersAreShipped()
}//end class
