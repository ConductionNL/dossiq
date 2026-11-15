<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TenantBrandingSanitiser: which branding fields and CSS declarations survive.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Tenant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Tenant;

use InvalidArgumentException;
use OCA\Dossiq\Service\Tenant\TenantBrandingSanitiser;
use PHPUnit\Framework\TestCase;

/**
 * Branding fields, the logo guard and the custom CSS whitelist.
 *
 * @covers \OCA\Dossiq\Service\Tenant\TenantBrandingSanitiser
 */
class TenantBrandingSanitiserTest extends TestCase {

	private TenantBrandingSanitiser $sanitiser;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->sanitiser = new TenantBrandingSanitiser();
	}//end setUp()

	/**
	 * Only the known fields survive, colours and font as given, CSS sanitised.
	 *
	 * @return void
	 */
	public function testOnlyKnownFieldsSurvive(): void {
		$out = $this->sanitiser->sanitiseBranding([
			'logo' => 'logo.png',
			'primaryColor' => '#AABBCC',
			'secondaryColor' => '#00ff00',
			'fontFamily' => 'Inter',
			'customCSS' => 'COLOR: red; position: fixed',
			'extra' => 'dropped',
		]);

		$this->assertSame([
			'logo' => 'logo.png',
			'primaryColor' => '#AABBCC',
			'secondaryColor' => '#00ff00',
			'fontFamily' => 'Inter',
			'customCSS' => 'color: red;',
		], $out);
		$this->assertSame([], $this->sanitiser->sanitiseBranding([]));
	}//end testOnlyKnownFieldsSurvive()

	/**
	 * A bad secondary colour is refused and named.
	 *
	 * @return void
	 */
	public function testABadSecondaryColourIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid hex color for secondaryColor: red');
		$this->sanitiser->sanitiseBranding(['secondaryColor' => 'red']);
	}//end testABadSecondaryColourIsRefused()

	/**
	 * A logo with metadata is checked; one without is kept as it is.
	 *
	 * @return void
	 */
	public function testTheLogoGuardRunsOnlyWithMetadata(): void {
		$this->assertSame(['logo' => 'x'], $this->sanitiser->sanitiseBranding(['logo' => 'x']));
		$this->assertSame(['logo' => 'x'], $this->sanitiser->sanitiseBranding(['logo' => 'x', 'logoMimeType' => 'image/webp', 'logoBytes' => TenantBrandingSanitiser::LOGO_MAX_BYTES]));

		foreach ([
			[['logoMimeType' => 'image/gif'], 'Unsupported logo MIME type: image/gif'],
			[['logoBytes' => 10], 'Unsupported logo MIME type: '],
			[['logoMimeType' => 'image/png', 'logoBytes' => (TenantBrandingSanitiser::LOGO_MAX_BYTES + 1)], 'Logo exceeds 5MB'],
		] as [$meta, $message]) {
			try {
				$this->sanitiser->sanitiseBranding(array_merge(['logo' => 'x'], $meta));
				$this->fail('should refuse: '.$message);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testTheLogoGuardRunsOnlyWithMetadata()

	/**
	 * Declarations split on newlines and semicolons, lowercased, trimmed; malformed ones dropped.
	 *
	 * @return void
	 */
	public function testCustomCssDeclarationsAreNormalised(): void {
		$css = "  Font-Size :  14px \n--nc-color-primary: #123456;nonsense;margin: 0 auto; display: none\n\n";
		$this->assertSame('font-size: 14px; --nc-color-primary: #123456; margin: 0 auto;', $this->sanitiser->sanitiseCustomCss($css));
	}//end testCustomCssDeclarationsAreNormalised()

	/**
	 * Nothing left, or any dangerous token in any case, yields an empty string.
	 *
	 * @return void
	 */
	public function testCustomCssThatKeepsNothingIsEmpty(): void {
		$this->assertSame('', $this->sanitiser->sanitiseCustomCss('display: none; position: fixed'));
		$this->assertSame('', $this->sanitiser->sanitiseCustomCss(''));
		foreach (['color: red; background: URL(x)', 'color: EXPRESSION(1)', '@IMPORT "a"', 'color: red; content: "<b>"', 'color: JavaScript:alert'] as $css) {
			$this->assertSame('', $this->sanitiser->sanitiseCustomCss($css), $css);
		}
	}//end testCustomCssThatKeepsNothingIsEmpty()
}//end class
