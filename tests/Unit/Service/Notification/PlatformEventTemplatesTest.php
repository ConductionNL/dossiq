<?php

/**
 * The Dutch text dossiq declares for OpenRegister's platform events.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Service\Notification\PlatformEventTemplates;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Notification\PlatformEventTemplates
 */
class PlatformEventTemplatesTest extends TestCase {

	/**
	 * A known event answers its subject and body.
	 *
	 * @return void
	 */
	public function testAKnownEventAnswersItsDutchText(): void {
		$this->assertSame(
			expected: [
				'subject' => '{{title}}: van {{from}} naar {{to}}',
				'body' => '{{title}} staat nu op {{to}} en was {{from}}. Kijk of er iets van je wordt verwacht.',
			],
			actual: PlatformEventTemplates::dutchFor(event: 'object_transitioned')
		);
	}//end testAKnownEventAnswersItsDutchText()

	/**
	 * Every declared event is answered as declared.
	 *
	 * @return void
	 */
	public function testEveryDeclaredEventIsAnswered(): void {
		foreach (PlatformEventTemplates::DUTCH as $event => $text) {
			$this->assertSame(expected: $text, actual: PlatformEventTemplates::dutchFor(event: $event), message: $event);
		}
	}//end testEveryDeclaredEventIsAnswered()

	/**
	 * An event dossiq declares nothing for answers null, not an empty template.
	 *
	 * @return void
	 */
	public function testAnUnknownEventAnswersNull(): void {
		$this->assertNull(actual: PlatformEventTemplates::dutchFor(event: 'object_deleted'));
		$this->assertNull(actual: PlatformEventTemplates::dutchFor(event: ''));
		$this->assertNull(actual: PlatformEventTemplates::dutchFor(event: 'Object_Created'));
	}//end testAnUnknownEventAnswersNull()
}//end class
