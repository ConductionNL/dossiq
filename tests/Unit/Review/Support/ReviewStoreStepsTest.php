<?php

/**
 * ReviewStoreSteps — whether a review schema is reachable, and its refusals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\Support\ReviewStoreSteps;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store is available only when the reviews store is and the schema is configured.
 *
 * @covers \OCA\Dossiq\Review\Support\ReviewStoreSteps
 */
final class ReviewStoreStepsTest extends TestCase {

	/**
	 * A class exposing the trait's steps.
	 *
	 * @param bool   $reviewsAvailable Whether the reviews store answers available.
	 * @param string $schema           The configured schema id.
	 *
	 * @return object
	 */
	private function subject(bool $reviewsAvailable, string $schema): object {
		$reviews = new class($reviewsAvailable) {
			/**
			 * Hold the answer.
			 *
			 * @param bool $available The answer.
			 */
			public function __construct(private readonly bool $available) {
			}//end __construct()

			/**
			 * Whether the store is available.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				return $this->available;
			}//end isAvailable()
		};
		$settings = new class($schema) {
			/**
			 * Hold the schema.
			 *
			 * @param string $schema The schema id.
			 */
			public function __construct(private readonly string $schema) {
			}//end __construct()

			/**
			 * The configured value for the schema key.
			 *
			 * @param string $key The key.
			 *
			 * @return string
			 */
			public function getConfigValue(string $key): string {
				if ($key === 'test_schema') {
					return $this->schema;
				}

				return '';
			}//end getConfigValue()
		};

		return new class($reviews, $settings) {
			use ReviewStoreSteps {
				isAvailable as public;
				refusal as public;
			}

			public const SCHEMA_KEY = 'test_schema';

			/**
			 * Hold the collaborators.
			 *
			 * @param object $reviews         The reviews store.
			 * @param object $settingsService The settings.
			 */
			public function __construct(private readonly object $reviews, private readonly object $settingsService) {
			}//end __construct()
		};
	}//end subject()

	/**
	 * Available needs both the store and a configured schema.
	 *
	 * @return void
	 */
	public function testAvailableNeedsStoreAndSchema(): void {
		self::assertTrue(condition: $this->subject(reviewsAvailable: true, schema: '7')->isAvailable());
		self::assertFalse(condition: $this->subject(reviewsAvailable: false, schema: '7')->isAvailable());
		self::assertFalse(condition: $this->subject(reviewsAvailable: true, schema: '')->isAvailable());
	}//end testAvailableNeedsStoreAndSchema()

	/**
	 * A refusal is 422 by default and carries its cause.
	 *
	 * @return void
	 */
	public function testRefusalDefaultsTo422(): void {
		$subject = $this->subject(reviewsAvailable: true, schema: '7');
		$cause = new RuntimeException(message: 'boom');

		$refusal = $subject->refusal(rule: 'r', sentence: 'S.', previous: $cause);

		self::assertInstanceOf(expected: RefusedException::class, actual: $refusal);
		self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $refusal->getStatus());
		self::assertSame(expected: $cause, actual: $refusal->getPrevious());
	}//end testRefusalDefaultsTo422()
}//end class
