<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\BulkAction\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BulkAction\Support;

use InvalidArgumentException;
use OCA\Dossiq\BulkAction\Support\DeclaresBulkAction;
use PHPUnit\Framework\TestCase;

/**
 * The id read from the using class and the reason rule.
 *
 * @covers \OCA\Dossiq\BulkAction\Support\DeclaresBulkAction
 */
class DeclaresBulkActionTest extends TestCase {

	/**
	 * A class using the trait, with the reason rule made callable.
	 *
	 * @return object
	 */
	private function action(): object {
		return new class {
			use DeclaresBulkAction;

			public const ID = 'dossiq:test-action';

			/**
			 * @param array<string, mixed> $parameters The parameters.
			 */
			public function check(array $parameters): void {
				$this->requireReason(parameters: $parameters);
			}
		};
	}

	public function testIdIsTheUsingClassConstant(): void {
		$this->assertSame('dossiq:test-action', $this->action()->getId());
	}

	public function testReasonPresentPasses(): void {
		$this->action()->check(['reason' => 'because']);
		$this->addToAssertionCount(1);
	}

	public function testBlankReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('reason is required');
		$this->action()->check(['reason' => '  ']);
	}

	public function testMissingReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->action()->check([]);
	}
}
