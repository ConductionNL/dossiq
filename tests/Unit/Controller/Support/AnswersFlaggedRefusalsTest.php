<?php

/**
 * The flagged refusals are a 401 and a 403 with `success: false`.
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

use OCA\Dossiq\Controller\Support\AnswersFlaggedRefusals;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersFlaggedRefusals
 */
class AnswersFlaggedRefusalsTest extends TestCase {

	public function testRefusals(): void {
		$host = new class {
			use AnswersFlaggedRefusals;

			public function anonymous(): mixed {
				return $this->notAuthenticated();
			}

			public function outsider(): mixed {
				return $this->notAuthorized();
			}
		};

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $host->anonymous()->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authenticated'], $host->anonymous()->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $host->outsider()->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authorized'], $host->outsider()->getData());
	}
}
