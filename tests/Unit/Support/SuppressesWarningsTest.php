<?php

/**
 * SuppressesWarnings — run an operation with PHP warnings recorded, not raised.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Support\SuppressesWarnings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The warning-suppressing trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Support\SuppressesWarnings
 */
final class SuppressesWarningsTest extends TestCase {

	/**
	 * A host exposing the trait's private methods.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class {
			use SuppressesWarnings;

			/**
			 * Run an operation through the trait.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed What it returns.
			 */
			public function run(callable $operation): mixed {
				return $this->withoutWarnings($operation);
			}//end run()

			/**
			 * The last recorded warning.
			 *
			 * @return string The text.
			 */
			public function last(): string {
				return $this->lastSuppressedWarning();
			}//end last()
		};
	}//end host()

	/**
	 * The operation's return value comes back, and nothing is recorded when it
	 * raises nothing.
	 *
	 * @return void
	 */
	public function testTheResultPassesThroughWithNothingRecorded(): void {
		$host = $this->host();

		self::assertSame(['ok' => true], $host->run(static fn (): array => ['ok' => true]));
		self::assertSame('', $host->last());
	}//end testTheResultPassesThroughWithNothingRecorded()

	/**
	 * A warning is recorded with its severity instead of being raised.
	 *
	 * @return void
	 */
	public function testAWarningIsRecordedWithItsSeverity(): void {
		$host = $this->host();

		$result = $host->run(
			static function (): string {
				trigger_error('disk almost full', E_USER_WARNING);
				return 'done';
			}
		);

		self::assertSame('done', $result);
		self::assertSame('[' . E_USER_WARNING . '] disk almost full', $host->last());
	}//end testAWarningIsRecordedWithItsSeverity()

	/**
	 * Of several warnings the last one is kept, and the next run starts clean.
	 *
	 * @return void
	 */
	public function testTheLastWarningWinsAndEachRunStartsClean(): void {
		$host = $this->host();

		$host->run(
			static function (): void {
				trigger_error('first', E_USER_WARNING);
				trigger_error('second', E_USER_WARNING);
			}
		);
		self::assertSame('[' . E_USER_WARNING . '] second', $host->last());

		$host->run(static fn (): int => 1);
		self::assertSame('', $host->last());
	}//end testTheLastWarningWinsAndEachRunStartsClean()

	/**
	 * A real engine warning (reading a missing file) is recorded, not raised.
	 *
	 * @return void
	 */
	public function testAnEngineWarningIsRecorded(): void {
		$host = $this->host();

		$result = $host->run(static fn () => file_get_contents('/nonexistent/dossiq-' . uniqid() . '.txt'));

		self::assertFalse($result);
		self::assertStringStartsWith('[' . E_WARNING . '] file_get_contents(', $host->last());
	}//end testAnEngineWarningIsRecorded()

	/**
	 * A user notice is outside the suppressed set: the trait's handler does not
	 * record it (PHP passes it on instead of swallowing it as a warning).
	 *
	 * @return void
	 */
	public function testNoticesOutsideTheSuppressedSetAreNotRecorded(): void {
		$host = $this->host();
		$level = error_reporting(E_ALL & ~E_USER_NOTICE);
		try {
			$result = $host->run(static fn (): bool => trigger_error('just so you know', E_USER_NOTICE));
		} finally {
			error_reporting($level);
		}

		self::assertTrue($result);
		self::assertSame('', $host->last());
	}//end testNoticesOutsideTheSuppressedSetAreNotRecorded()

	/**
	 * The previous error handler is restored afterwards, also when the operation
	 * throws, and the exception propagates.
	 *
	 * @return void
	 */
	public function testThePreviousHandlerIsRestoredEvenWhenTheOperationThrows(): void {
		$host = $this->host();
		$outer = [];
		set_error_handler(
			static function (int $severity, string $message) use (&$outer): bool {
				$outer[] = $message;
				return true;
			}
		);
		try {
			try {
				$host->run(
					static function (): void {
						throw new RuntimeException('boom');
					}
				);
				self::fail('expected the exception to propagate');
			} catch (RuntimeException $e) {
				self::assertSame('boom', $e->getMessage());
			}

			$host->run(static fn () => trigger_error('inside', E_USER_WARNING));
			trigger_error('after', E_USER_WARNING);
		} finally {
			restore_error_handler();
		}

		self::assertSame(['after'], $outer);
		self::assertSame('[' . E_USER_WARNING . '] inside', $host->last());
	}//end testThePreviousHandlerIsRestoredEvenWhenTheOperationThrows()
}//end class
