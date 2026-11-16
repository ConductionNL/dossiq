<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Listener\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\ReadsPayloadIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * `@self.id` wins, the top-level `id` is the fallback, nothing is empty.
 *
 * @covers \OCA\Dossiq\Listener\Support\ReadsPayloadIdentifier
 */
class ReadsPayloadIdentifierTest extends TestCase {

	public function testIdentifier(): void {
		$reader = new class {
			use ReadsPayloadIdentifier;

			public function of(array $payload): string {
				return $this->identifier(payload: $payload);
			}
		};

		$this->assertSame('self', $reader->of(['@self' => ['id' => 'self'], 'id' => 'top']));
		$this->assertSame('top', $reader->of(['@self' => ['id' => ''], 'id' => 'top']));
		$this->assertSame('', $reader->of([]));
	}
}
