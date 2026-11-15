<?php

/**
 * FindsMappedObjects — the mapped-object lookup and the uuid of a reference.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\FindsMappedObjects;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Service\Zgw\FindsMappedObjects
 */
final class FindsMappedObjectsTest extends TestCase {

	/**
	 * A host whose object service echoes the lookup.
	 *
	 * @return object The host.
	 */
	private function host(): object {
		$objectService = new class {
			/**
			 * Echo the lookup.
			 *
			 * @param string $uuid     The uuid.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $uuid, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options?->register;
				$schema = $options?->schema;
				return ['uuid' => $uuid, 'register' => $register, 'schema' => $schema];
			}//end find()
		};
		$zgwService = new class($objectService) {
			/**
			 * Constructor.
			 *
			 * @param object $objectService The object service.
			 */
			public function __construct(private readonly object $objectService) {
			}//end __construct()

			/**
			 * The object service.
			 *
			 * @return object
			 */
			public function getObjectService(): object {
				return $this->objectService;
			}//end getObjectService()
		};

		return new class($zgwService) {
			use FindsMappedObjects;

			private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

			/**
			 * Constructor.
			 *
			 * @param object $zgwService The ZGW service.
			 */
			public function __construct(private readonly object $zgwService) {
			}//end __construct()

			/**
			 * Expose the lookups.
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
	}//end host()

	/**
	 * The lookup goes to the mapping's register and schema; the uuid is read from a URL.
	 *
	 * @return void
	 */
	public function testLookups(): void {
		$host = $this->host();
		$uuid = '0b0e6e7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

		self::assertSame(['uuid' => 'u1', 'register' => 'r', 'schema' => 's'], $host->call('find', ['uuid' => 'u1', 'config' => ['sourceRegister' => 'r', 'sourceSchema' => 's']]));
		self::assertSame($uuid, $host->call('uuidOf', ['value' => 'https://x/zaken/'.$uuid]));
		self::assertNull($host->call('uuidOf', ['value' => '']));
		self::assertNull($host->call('uuidOf', ['value' => 'no-uuid']));
	}//end testLookups()
}//end class
