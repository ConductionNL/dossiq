<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Review\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\Support\ReviewSchemaRows;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Availability needs both the review store and this store's schema; a refusal is 422 unless said otherwise.
 *
 * @covers \OCA\Dossiq\Review\Support\ReviewSchemaRows
 */
class ReviewSchemaRowsTest extends TestCase {

	/**
	 * A store over the given availability and schema.
	 *
	 * @param bool   $reviews Whether the review store is available.
	 * @param string $schema  The configured schema.
	 *
	 * @return object
	 */
	private function store(bool $reviews, string $schema): object {
		$access = $this->createMock(DocumentRelevance::class);
		$access->method('isAvailable')->willReturn($reviews);
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturn($schema);

		return new class ($access, $settings) {
			use ReviewSchemaRows;

			private const SCHEMA_KEY = 'test_schema';

			public function __construct(
				private readonly DocumentRelevance $reviews,
				private readonly SettingsServiceInterface $settingsService,
			) {
			}

			public function available(): bool {
				return $this->isAvailable();
			}

			public function refuse(string $rule, ?\Throwable $previous = null): RefusedException {
				return ($previous === null)
					? $this->refusal(rule: $rule, sentence: 'No.')
					: $this->refusal(rule: $rule, sentence: 'No.', status: RefusedException::STATUS_INDETERMINATE, previous: $previous);
			}
		};
	}

	public function testAvailability(): void {
		$this->assertTrue($this->store(reviews: true, schema: 's')->available());
		$this->assertFalse($this->store(reviews: true, schema: '')->available());
		$this->assertFalse($this->store(reviews: false, schema: 's')->available());
	}

	public function testRefusals(): void {
		$plain = $this->store(reviews: true, schema: 's')->refuse(rule: 'r');
		$this->assertSame('r', $plain->getRule());
		$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $plain->getStatus());

		$cause = new RuntimeException('down');
		$caused = $this->store(reviews: true, schema: 's')->refuse(rule: 'r', previous: $cause);
		$this->assertSame(RefusedException::STATUS_INDETERMINATE, $caused->getStatus());
		$this->assertSame($cause, $caused->getPrevious());
	}
}
