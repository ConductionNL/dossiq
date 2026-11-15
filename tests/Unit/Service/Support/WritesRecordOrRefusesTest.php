<?php

/**
 * WritesRecordOrRefuses — store a record without its metadata, or refuse.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\WritesRecordOrRefuses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The saved row comes back; a failing save or an empty answer is refused.
 *
 * @covers \OCA\Dossiq\Service\Support\WritesRecordOrRefuses
 */
final class WritesRecordOrRefusesTest extends TestCase {

	/**
	 * A store whose save answers the given outcome.
	 *
	 * @param array<string, mixed>|Throwable|null $outcome What the save answers or throws.
	 *
	 * @return object
	 */
	private function subject(array|Throwable|null $outcome): object {
		return new class($outcome) {
			use WritesRecordOrRefuses {
				writeOrRefuse as public;
			}

			/**
			 * The record the save was handed.
			 *
			 * @var array<string, mixed>
			 */
			public array $handed = [];

			/**
			 * Hold the outcome.
			 *
			 * @param array<string, mixed>|Throwable|null $outcome The outcome.
			 */
			public function __construct(private array|Throwable|null $outcome) {
			}//end __construct()

			/**
			 * Save the record.
			 *
			 * @param array<string, mixed> $record The record.
			 * @param string|null          $uuid   The uuid.
			 *
			 * @return array<string, mixed>|null
			 */
			private function store(array $record, ?string $uuid): ?array {
				$this->handed = $record + ['@uuid' => $uuid];
				if ($this->outcome instanceof Throwable) {
					throw $this->outcome;
				}

				return $this->outcome;
			}//end store()

			/**
			 * The refusal.
			 *
			 * @param Throwable|null $previous The cause.
			 *
			 * @return RefusedException
			 */
			private function unrecorded(?Throwable $previous): RefusedException {
				return new RefusedException(rule: 'unwritable', sentence: 'Not recorded.', previous: $previous);
			}//end unrecorded()
		};
	}//end subject()

	/**
	 * The metadata is stripped and the saved row answered.
	 *
	 * @return void
	 */
	public function testSavedRowIsAnswered(): void {
		$subject = $this->subject(outcome: ['id' => 'x']);

		self::assertSame(expected: ['id' => 'x'], actual: $subject->writeOrRefuse(['id' => 'a', 'uuid' => 'a', '@self' => [], 'name' => 'n'], 'u'));
		self::assertSame(expected: ['name' => 'n', '@uuid' => 'u'], actual: $subject->handed);
	}//end testSavedRowIsAnswered()

	/**
	 * A failing save is refused with its cause.
	 *
	 * @return void
	 */
	public function testFailingSaveIsRefused(): void {
		$cause = new RuntimeException('down');
		try {
			$this->subject(outcome: $cause)->writeOrRefuse([], null);
			self::fail(message: 'Expected a refusal.');
		} catch (RefusedException $e) {
			self::assertSame(expected: $cause, actual: $e->getPrevious());
		}
	}//end testFailingSaveIsRefused()

	/**
	 * An empty answer is refused without a cause.
	 *
	 * @return void
	 */
	public function testEmptyAnswerIsRefused(): void {
		$this->expectException(exception: RefusedException::class);

		$this->subject(outcome: null)->writeOrRefuse([], null);
	}//end testEmptyAnswerIsRefused()
}//end class
