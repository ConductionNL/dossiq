<?php

/**
 * Golden test for TenantBrandingSanitiser::sanitiseCustomCss: what is kept of
 * every kind of input, exactly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Tenant;

use OCA\Dossiq\Service\Tenant\TenantBrandingSanitiser;
use PHPUnit\Framework\TestCase;

class TenantCustomCssGoldenTest extends TestCase {

	public function testWhatIsKept(): void {
		$sanitiser = new TenantBrandingSanitiser();
		$cases     = [
			'' => '',
			'   ' => '',
			'color: red' => 'color: red;',
			" COLOR : Red ;\n\nfont-size:12px;;margin: 0" => 'color: Red; font-size: 12px; margin: 0;',
			'background-color: #fff; content: "a:b"' => 'background-color: #fff;',
			'font-family: "A:B", serif' => 'font-family: "A:B", serif;',
			'color' => '',
			'position: fixed; z-index: 9' => '',
			'color: red; background: URL(x)' => '',
			'color: Expression(alert(1))' => '',
			'@IMPORT "x.css"; color: red' => '',
			'color: red; x: JavaScript:alert(1)' => '',
			'color: red </style>' => '',
			'color: a > b' => '',
			"border-color: blue\nfont-weight: bold\r\n" => 'border-color: blue; font-weight: bold;',
			'color: ' => 'color: ;',
		];
		$answers = [];
		foreach (array_keys($cases) as $css) {
			$answers[$css] = $sanitiser->sanitiseCustomCss(css: (string) $css);
		}

		$this->assertSame($cases, $answers);
	}//end testWhatIsKept()
}//end class
