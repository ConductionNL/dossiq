<?php

/**
 * Unit tests for DefaultTestConsumers: an absent consumer is created with
 * timestamps, a present one gets its authorization configuration updated,
 * and the two defaults keep their frozen names and scopes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use DateTime;
use OCA\Dossiq\Repair\Support\DefaultTestConsumers;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\DefaultTestConsumers
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
class DefaultTestConsumersTest extends TestCase {

	/**
	 * The two defaults keep the frozen names the seed script looks up, and only the admin is a superuser.
	 *
	 * @return void
	 */
	public function testTheDefaultsKeepTheirFrozenNamesAndScopes(): void {
		$defaults = (new DefaultTestConsumers())->defaults();

		$this->assertSame(expected: ['procest-admin', 'procest-limited'], actual: array_column(array: $defaults, column_key: 'name'));
		$this->assertTrue(condition: $defaults[0]['authorizationConfiguration']['superuser']);
		$this->assertFalse(condition: $defaults[1]['authorizationConfiguration']['superuser']);
		$this->assertSame(
			expected: [
				['component' => 'ztc', 'scopes' => ['zaaktypen.lezen']],
				['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'maxVertrouwelijkheidaanduiding' => 'openbaar'],
			],
			actual: $defaults[1]['authorizationConfiguration']['scopes']
		);
	}//end testTheDefaultsKeepTheirFrozenNamesAndScopes()

	/**
	 * An absent consumer is created with fresh timestamps; a present one has its configuration replaced.
	 *
	 * @return void
	 */
	public function testAnAbsentConsumerIsCreatedAndAPresentOneUpdated(): void {
		$present = new class {
			/**
			 * The configuration the consumer was given.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $configuration = null;

			/**
			 * When the consumer was last updated.
			 *
			 * @var DateTime|null
			 */
			public ?DateTime $updated = null;

			/**
			 * Record the new configuration.
			 *
			 * @param array<string, mixed> $configuration The configuration
			 *
			 * @return void
			 */
			public function setAuthorizationConfiguration(array $configuration): void {
				$this->configuration = $configuration;
			}

			/**
			 * Record the update moment.
			 *
			 * @param DateTime $updated The moment
			 *
			 * @return void
			 */
			public function setUpdated(DateTime $updated): void {
				$this->updated = $updated;
			}
		};

		$mapper = new class($present) {
			/**
			 * The rows created.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $createdRows = [];

			/**
			 * The consumers updated.
			 *
			 * @var list<object>
			 */
			public array $updatedRows = [];

			/**
			 * A mapper that holds one consumer, the limited one.
			 *
			 * @param object $present The consumer it holds
			 */
			public function __construct(private readonly object $present) {
			}

			/**
			 * The consumers by name.
			 *
			 * @param array<string, string> $filters The name filter
			 *
			 * @return list<object>
			 */
			public function findAll(array $filters): array {
				if ($filters['name'] === 'procest-limited') {
					return [$this->present];
				}

				return [];
			}

			/**
			 * Record an update.
			 *
			 * @param object $consumer The consumer
			 *
			 * @return void
			 */
			public function update(object $consumer): void {
				$this->updatedRows[] = $consumer;
			}

			/**
			 * Record a creation.
			 *
			 * @param array<string, mixed> $object The row
			 *
			 * @return void
			 */
			public function createFromArray(array $object): void {
				$this->createdRows[] = $object;
			}
		};

		$counts = (new DefaultTestConsumers())->upsert(consumerMapper: $mapper);

		$this->assertSame(expected: [1, 1], actual: $counts);
		$this->assertSame(expected: 'procest-admin', actual: $mapper->createdRows[0]['name']);
		$this->assertInstanceOf(expected: DateTime::class, actual: $mapper->createdRows[0]['created']);
		$this->assertInstanceOf(expected: DateTime::class, actual: $mapper->createdRows[0]['updated']);
		$this->assertSame(expected: [$present], actual: $mapper->updatedRows);
		$this->assertFalse(condition: $present->configuration['superuser']);
		$this->assertInstanceOf(expected: DateTime::class, actual: $present->updated);
	}//end testAnAbsentConsumerIsCreatedAndAPresentOneUpdated()
}//end class
