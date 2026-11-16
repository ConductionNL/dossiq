<?php

/**
 * The signed-out answer is a translated 401.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\AnswersSignedOutCallers;
use OCP\AppFramework\Http;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersSignedOutCallers
 */
class AnswersSignedOutCallersTest extends TestCase {

	public function testTheAnswerIsATranslated401(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => '[' . $text . ']');
		$controller = new class ($l10n) {
			use AnswersSignedOutCallers;

			public function __construct(private readonly IL10N $l10n) {
			}

			public function answer(): mixed {
				return $this->notSignedIn();
			}
		};

		$answer = $controller->answer();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $answer->getStatus());
		$this->assertSame(['error' => '[You are not signed in.]'], $answer->getData());
	}
}
