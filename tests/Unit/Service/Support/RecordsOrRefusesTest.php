<?php

/**
 * RecordsOrRefuses — a stored record, or the class's refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\RecordsOrRefuses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Stored, threw, or read nothing back.
 *
 * @covers \OCA\Dossiq\Service\Support\RecordsOrRefuses
 */
final class RecordsOrRefusesTest extends TestCase {

	/**
	 * A class using the trait whose store answers the given closure.
	 *
	 * @param \Closure $store The store step.
	 *
	 * @return object
	 */
	private function subject(\Closure $store): object {
		return new class($store) {
			use RecordsOrRefuses;

			/**
			 * Constructor.
			 *
			 * @param \Closure $storeStep The store step.
			 */
			public function __construct(private readonly \Closure $storeStep) {
			}

			/**
			 * The store step.
			 *
			 * @param array<string, mixed> $record The record.
			 * @param string|null          $uuid   The uuid.
			 *
			 * @return array<string, mixed>|null
			 */
			private function store(array $record, ?string $uuid): ?array {
				return ($this->storeStep)($record, $uuid);
			}

			/**
			 * The refusal.
			 *
			 * @param Throwable|null $previous The cause.
			 *
			 * @return RefusedException
			 */
			private function unrecorded(?Throwable $previous): RefusedException {
				return new RefusedException(rule: 'unwritable', sentence: 'Not recorded.', status: RefusedException::STATUS_INDETERMINATE, previous: $previous);
			}

			/**
			 * The trait's answer.
			 *
			 * @param array<string, mixed> $record The record.
			 * @param string|null          $uuid   The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function write(array $record, ?string $uuid): array {
				return $this->storedOrRefused(record: $record, uuid: $uuid);
			}
		};
	}//end subject()

	/**
	 * A stored row is answered as stored.
	 *
	 * @return void
	 */
	public function testAnswersTheStoredRow(): void {
		$subject = $this->subject(static fn (array $record, ?string $uuid): array => $record + ['uuid' => ($uuid ?? 'new')]);

		self::assertSame(['a' => 1, 'uuid' => 'u-1'], $subject->write(['a' => 1], 'u-1'));
	}//end testAnswersTheStoredRow()

	/**
	 * A failing store is refused with the cause attached.
	 *
	 * @return void
	 */
	public function testRefusesAFailingStore(): void {
		$cause   = new RuntimeException('down');
		$subject = $this->subject(static function () use ($cause): ?array {
			throw $cause;
		});

		try {
			$subject->write([], null);
			self::fail('Expected a refusal.');
		} catch (RefusedException $e) {
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testRefusesAFailingStore()

	/**
	 * A store that reads nothing back is refused without a cause.
	 *
	 * @return void
	 */
	public function testRefusesANullStore(): void {
		$subject = $this->subject(static fn (): ?array => null);

		try {
			$subject->write([], null);
			self::fail('Expected a refusal.');
		} catch (RefusedException $e) {
			self::assertNull($e->getPrevious());
		}
	}//end testRefusesANullStore()
}//end class
