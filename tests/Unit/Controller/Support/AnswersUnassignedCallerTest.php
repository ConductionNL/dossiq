<?php

/**
 * The shared refusal answers its fixed body and status.
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

use OCA\Dossiq\Controller\Support\AnswersUnassignedCaller;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\AnswersUnassignedCaller
 */
class AnswersUnassignedCallerTest extends TestCase {

	public function testAnswer(): void {
		$host = new class {
			use AnswersUnassignedCaller;

			public function answer(): mixed {
				return $this->notAssigned();
			}
		};

		$this->assertSame(Http::STATUS_FORBIDDEN, $host->answer()->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Access denied: you are not assigned to this case'], $host->answer()->getData());
	}
}
