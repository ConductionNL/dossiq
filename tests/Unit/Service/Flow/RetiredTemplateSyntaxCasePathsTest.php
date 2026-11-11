<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use PHPUnit\Framework\TestCase;

/**
 * How case placeholders are rewritten for a document and for a source call.
 */
class RetiredTemplateSyntaxCasePathsTest extends TestCase {

	/**
	 * Case paths, the bare case, another root and an empty path, in one template.
	 */
	private const TEMPLATE = 'A {{case.title}} B {{ case.a.b }} C {{case}} D {{other.x}} E {{case.}}';

	/**
	 * A document reads case paths under `item.`; anything else is blanked.
	 *
	 * @return void
	 */
	public function testForDocument(): void {
		self::assertSame('A {{ item.title }} B {{ item.a.b }} C  D  E ', (new RetiredTemplateSyntax())->forDocument(template: self::TEMPLATE, what: 'text'));
	}//end testForDocument()

	/**
	 * A source call reads case paths bare; anything else is blanked.
	 *
	 * @return void
	 */
	public function testForSourceCall(): void {
		self::assertSame('A {{ title }} B {{ a.b }} C  D  E ', (new RetiredTemplateSyntax())->forSourceCall(template: self::TEMPLATE));
	}//end testForSourceCall()

	/**
	 * Twig code in a document is refused, naming what holds it.
	 *
	 * @return void
	 */
	public function testTwigInADocumentIsRefused(): void {
		foreach (['x {% if %}', 'x {# c #}'] as $template) {
			try {
				(new RetiredTemplateSyntax())->forDocument(template: $template, what: 'body');
				self::fail('Not refused: ' . $template);
			} catch (UnmappableStep $e) {
				self::assertSame('the body contains "{%" or "{#", which dossiq printed as text and Filinq would run as template code', $e->getMessage());
			}
		}
	}//end testTwigInADocumentIsRefused()
}//end class
