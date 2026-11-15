<?php

/**
 * Only a string parameter is read; anything else is null.
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

use OCA\Dossiq\Controller\Support\ReadsStringParams;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\ReadsStringParams
 */
class ReadsStringParamsTest extends TestCase {

	public function testStringParam(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['a', null, 'text'], ['b', null, 3], ['c', null, null]]);
		$host = new class ($request) {
			use ReadsStringParams;

			public function __construct(private readonly IRequest $request) {
			}

			public function param(string $name): ?string {
				return $this->stringParam(name: $name);
			}
		};

		$this->assertSame('text', $host->param('a'));
		$this->assertNull($host->param('b'));
		$this->assertNull($host->param('c'));
	}
}
