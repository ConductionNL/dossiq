<?php

/**
 * ReadsMergedRegisterConfig — the shipped register JSON with its fragments.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ReadsMergedRegisterConfig;
use PHPUnit\Framework\TestCase;

/**
 * The shipped register is read and handed to the fragment merger.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsMergedRegisterConfig
 */
final class ReadsMergedRegisterConfigTest extends TestCase {

	/**
	 * The merger receives the shipped register and the register.d directory, and its answer is returned.
	 *
	 * @return void
	 */
	public function testMergesTheShippedRegister(): void {
		$fragments = new class() {
			/**
			 * The calls made, as [base, fragmentDir].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * A merge that tags the base.
			 *
			 * @param array<string, mixed> $base        The base config.
			 * @param string               $fragmentDir The fragment directory.
			 *
			 * @return array<int, mixed>
			 */
			public function merge(array $base, string $fragmentDir): array {
				$this->calls[] = [$base, $fragmentDir];
				return [['merged' => true, 'hadComponents' => isset($base['components'])], []];
			}
		};
		$subject = new class($fragments) {
			use ReadsMergedRegisterConfig {
				mergedRegisterConfig as public;
			}

			/**
			 * Constructor.
			 *
			 * @param object $fragments The fragment merger.
			 */
			public function __construct(private readonly object $fragments) {
			}
		};

		self::assertSame(['merged' => true, 'hadComponents' => true], $subject->mergedRegisterConfig());
		self::assertCount(1, $fragments->calls);
		self::assertStringEndsWith('/Settings/register.d', $fragments->calls[0][1]);
		self::assertDirectoryExists($fragments->calls[0][1]);
	}//end testMergesTheShippedRegister()
}//end class
