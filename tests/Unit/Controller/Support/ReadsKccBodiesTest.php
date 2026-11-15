<?php

/**
 * The KCC body drops identifiers and routing; the 401 is fixed.
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

use OCA\Dossiq\Controller\Support\ReadsKccBodies;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\ReadsKccBodies
 */
class ReadsKccBodiesTest extends TestCase {

	public function testBodyAndRefusal(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'x', 'uuid' => 'y', '@self' => [], '_route' => 'r', 'name' => 'n']);
		$host = new class ($request) {
			use ReadsKccBodies;

			public function __construct(private readonly IRequest $request) {
			}

			public function body(): array {
				return $this->bodyParams();
			}

			public function refusal(): mixed {
				return $this->unauthorized();
			}
		};

		$this->assertSame(['name' => 'n'], $host->body());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $host->refusal()->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $host->refusal()->getData());
	}
}
