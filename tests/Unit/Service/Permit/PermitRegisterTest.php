<?php

/**
 * Tests for the permit register seam.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Permit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Permit;


use OCA\Dossiq\Service\Permit\PermitRegister;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An object service that records whether each call ran as the system.
 */
class SystemRecordingRegister extends InMemoryRegister {

	/**
	 * How many operations ran as the system.
	 *
	 * @var int
	 */
	public int $systemRuns = 0;

	/**
	 * Run an operation as the system, as OpenRegister's ObjectService does.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation): mixed {
		$this->systemRuns += 1;

		return $operation();
	}//end runAsSystem()
}//end class

/**
 * The permit register reads, searches and writes the dossiq register as the system.
 *
 * @covers \OCA\Dossiq\Service\Permit\PermitRegister
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PermitRegisterTest extends TestCase {

	/**
	 * A read answers the object, and runs as the system.
	 *
	 * @return void
	 */
	public function testReadAnswersTheObjectAsTheSystem(): void {
		$objects = new SystemRecordingRegister();
		$objects->seed(schema: 'permit', uuid: 'permit-1', row: ['title' => 'Parkeervergunning']);

		$found = (new PermitRegister(objectService: $objects, register: 'dossiq'))->read(schema: 'permit', id: 'permit-1');

		$this->assertSame('Parkeervergunning', $found['title']);
		$this->assertSame(1, $objects->systemRuns);
	}//end testReadAnswersTheObjectAsTheSystem()

	/**
	 * A blank id or a missing object reads as null; a blank id asks nothing.
	 *
	 * @return void
	 */
	public function testReadOfABlankOrMissingIdAnswersNull(): void {
		$objects = new SystemRecordingRegister();
		$register = new PermitRegister(objectService: $objects, register: 'dossiq');

		$this->assertNull($register->read(schema: 'permit', id: '  '));
		$this->assertSame(0, $objects->systemRuns);
		$this->assertNull($register->read(schema: 'permit', id: 'missing'));
		$this->assertSame(1, $objects->systemRuns);
	}//end testReadOfABlankOrMissingIdAnswersNull()

	/**
	 * A read failure other than a missing object reaches the caller.
	 *
	 * @return void
	 */
	public function testReadFailureReachesTheCaller(): void {
		$objects = new class {
			/**
			 * Fail every find.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return never
			 */
			public function find(string $id, int|string $register = '', int|string $schema = ''): never {
				throw new RuntimeException('register down');
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('register down');

		(new PermitRegister(objectService: $objects, register: 'dossiq'))->read(schema: 'permit', id: 'permit-1');
	}//end testReadFailureReachesTheCaller()

	/**
	 * A search answers the matching rows without RBAC or tenancy.
	 *
	 * @return void
	 */
	public function testSearchIsUnscopedAndRunsAsTheSystem(): void {
		$objects = new SystemRecordingRegister();
		$objects->seed(schema: 'permit', uuid: 'p-1', row: ['portalSubject' => 'bsn-1']);
		$objects->seed(schema: 'permit', uuid: 'p-2', row: ['portalSubject' => 'bsn-2']);
		$objects->hiddenFromCaller = ['permit'];

		$rows = (new PermitRegister(objectService: $objects, register: 'dossiq'))->search(schema: 'permit', filters: ['portalSubject' => 'bsn-2']);

		$this->assertSame(['p-2'], array_column($rows, 'id'));
		$this->assertSame(1, $objects->systemRuns);
	}//end testSearchIsUnscopedAndRunsAsTheSystem()

	/**
	 * A save writes a new object on the schema and answers it.
	 *
	 * @return void
	 */
	public function testSaveWritesANewObject(): void {
		$objects = new SystemRecordingRegister();

		$saved = (new PermitRegister(objectService: $objects, register: 'dossiq'))->save(schema: 'case', object: ['title' => 'Kenteken wijzigen']);

		$this->assertSame('Kenteken wijzigen', $saved['title']);
		$this->assertSame(1, $objects->writes);
		$this->assertSame(1, $objects->systemRuns);
		$this->assertSame(['title' => 'Kenteken wijzigen', 'id' => $saved['id']], $objects->row(schema: 'case', uuid: $saved['id']));
	}//end testSaveWritesANewObject()

	/**
	 * Without a system seam the operations run directly.
	 *
	 * @return void
	 */
	public function testWithoutASystemSeamOperationsRunDirectly(): void {
		$objects = new InMemoryRegister();
		$objects->seed(schema: 'permit', uuid: 'permit-1', row: ['title' => 'Parkeervergunning']);
		$register = new PermitRegister(objectService: $objects, register: 'dossiq');

		$this->assertSame('Parkeervergunning', $register->read(schema: 'permit', id: 'permit-1')['title']);
		$this->assertCount(1, $register->search(schema: 'permit', filters: []));
	}//end testWithoutASystemSeamOperationsRunDirectly()

}//end class
