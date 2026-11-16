<?php

/**
 * The no-access answer is a translated 403.
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

use OCA\Dossiq\Controller\Support\AnswersNoCaseAccess;
use OCP\AppFramework\Http;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersNoCaseAccess
 */
class AnswersNoCaseAccessTest extends TestCase {

	public function testAnswer(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$host = new class ($l10n) {
			use AnswersNoCaseAccess;

			public function __construct(private readonly IL10N $l10n) {
			}

			public function answer(): mixed {
				return $this->noAccess();
			}
		};

		$this->assertSame(Http::STATUS_FORBIDDEN, $host->answer()->getStatus());
		$this->assertSame(['error' => 'no-case-access', 'message' => 'You do not have access to this case'], $host->answer()->getData());
	}
}
