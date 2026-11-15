<?php

/**
 * A signed-out caller is a 401, a failed read a 503, an unreadable case type a 404, a readable one null.
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

use OCA\Dossiq\Controller\Support\RefusesUnreadableCaseTypes;
use OCA\Dossiq\Kernel\Contracts\ReadableObjectsInterface;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\Support\RefusesUnreadableCaseTypes
 */
class RefusesUnreadableCaseTypesTest extends TestCase {

	/**
	 * A controller over the given read answers.
	 *
	 * @param bool      $caller   Whether someone is signed in.
	 * @param bool|null $readable Whether the case type reads; null throws.
	 *
	 * @return object
	 */
	private function controller(bool $caller, ?bool $readable): object {
		$objects = $this->createMock(ReadableObjectsInterface::class);
		$objects->method('hasCaller')->willReturn($caller);
		if ($readable === null) {
			$objects->method('findsAsCaller')->willThrowException(new RuntimeException('down'));
		} else {
			$objects->method('findsAsCaller')->with('caseType', 'ct1')->willReturn($readable);
		}

		return new class ($objects) {
			use RefusesUnreadableCaseTypes;

			public function __construct(private readonly ReadableObjectsInterface $readable) {
			}

			public function refusal(): mixed {
				return $this->caseTypeRefusal(id: 'ct1');
			}
		};
	}

	public function testRefusals(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(caller: false, readable: true)->refusal()->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller(caller: true, readable: null)->refusal()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(caller: true, readable: false)->refusal()->getStatus());
		$this->assertNull($this->controller(caller: true, readable: true)->refusal());
	}
}
