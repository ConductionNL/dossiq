<?php

/**
 * StoresWooRows — a Woo row saved into its schema, and a row's uuid.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Woo\StoresWooRows;
use PHPUnit\Framework\TestCase;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Woo\StoresWooRows
 */
final class StoresWooRowsTest extends TestCase {

	/**
	 * The identity fields are dropped, an existing uuid is kept and a serialisable answer is flattened.
	 *
	 * @return void
	 */
	public function testSaveAndIdentity(): void {
		$objectService = new class {
			/**
			 * The last save.
			 *
			 * @var array<string, mixed>
			 */
			public array $saved = [];

			/**
			 * Record the save and answer a serialisable row.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return object
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): object {
				$this->saved = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return new class($object) implements \JsonSerializable {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private readonly array $row) {
					}//end __construct()

					/**
					 * The row with an id.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->row + ['id' => 'new-id'];
					}//end jsonSerialize()
				};
			}//end saveObject()
		};
		$host = new class {
			use StoresWooRows;

			/**
			 * Expose the trait.
			 *
			 * @param string $method The method.
			 * @param array  $args   The named arguments.
			 *
			 * @return mixed
			 */
			public function call(string $method, array $args): mixed {
				return $this->$method(...$args);
			}//end call()
		};

		$stored = $host->call('storeRow', ['target' => [$objectService, 'reg', 'sch'], 'row' => ['id' => 'x', '@self' => [], 'title' => 'T'], 'uuid' => '']);

		self::assertSame(['title' => 'T', 'id' => 'new-id'], $stored);
		self::assertSame(['object' => ['title' => 'T'], 'register' => 'reg', 'schema' => 'sch', 'uuid' => null], $objectService->saved);
		self::assertSame('u1', $host->call('idOf', ['row' => ['@self' => ['id' => ' u1 ']]]));
		self::assertSame('', $host->call('idOf', ['row' => []]));
	}//end testSaveAndIdentity()
}//end class
