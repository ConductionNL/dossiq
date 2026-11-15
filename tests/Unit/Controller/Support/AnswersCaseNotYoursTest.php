<?php

/**
 * The not-yours answer is a fixed 403.
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

use OCA\Dossiq\Controller\Support\AnswersCaseNotYours;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersCaseNotYours
 */
class AnswersCaseNotYoursTest extends TestCase {

	public function testAnswer(): void {
		$host = new class {
			use AnswersCaseNotYours;

			public function answer(): mixed {
				return $this->notYours();
			}
		};

		$this->assertSame(Http::STATUS_FORBIDDEN, $host->answer()->getStatus());
		$this->assertSame(['message' => 'You cannot move this case.', 'error' => 'case-access-denied'], $host->answer()->getData());
	}
}
