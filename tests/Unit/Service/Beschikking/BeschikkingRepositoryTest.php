<?php

/**
 * Beschikking repository test
 *
 * Reads and writes beschikkingen in the configured register and schema. A
 * read that fails is logged and reads as absent, which requireBeschikking()
 * turns into `not_found`; a write without storage or without a configured
 * schema is refused by name.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BeschikkingRepository
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class BeschikkingRepositoryTest extends TestCase {

	/**
	 * The repository over an object service and a schema.
	 *
	 * @param object|null          $objects The object service.
	 * @param string               $schema  The beschikking schema.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return BeschikkingRepository
	 */
	private function repository(?object $objects, string $schema = 'beschikking', ?LoggerInterface $logger = null): BeschikkingRepository {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'beschikking_schema' => $schema][$key] ?? ''
		);

		return new BeschikkingRepository($settings, ($logger ?? new NullLogger()));
	}//end repository()

	/**
	 * A saved beschikking reads back, and keeps its id for the next save.
	 *
	 * @return void
	 */
	public function testASavedBeschikkingReadsBack(): void {
		$register = new InMemoryRegister();
		$repository = $this->repository($register);

		$saved = $repository->save(['reference' => 'B-1', 'status' => 'concept']);
		$this->assertSame(['reference' => 'B-1', 'status' => 'concept', 'id' => 'generated-1'], $saved);
		$this->assertSame(['reference' => 'B-1', 'status' => 'concept', 'id' => 'generated-1'], $register->row(schema: 'beschikking', uuid: 'generated-1'));

		$this->assertSame($saved, $repository->requireBeschikking('generated-1'));
	}//end testASavedBeschikkingReadsBack()

	/**
	 * A row without an id gets the requested one.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdGetsTheRequestedOne(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'beschikking', uuid: 'dec-1', row: ['reference' => 'B-1']);

		$this->assertSame(['reference' => 'B-1', 'id' => 'dec-1'], $this->repository($register)->requireBeschikking('dec-1'));
	}//end testARowWithoutAnIdGetsTheRequestedOne()

	/**
	 * A read that fails is logged and is not found.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotFound(): void {
		$objects = new class {
			/**
			 * @param mixed $id       The id.
			 * @param mixed ...$named The rest.
			 *
			 * @return never
			 */
			public function find(mixed $id, mixed ...$named): never {
				throw new DoesNotExistException('gone');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error')->with('BeschikkingService: find failed', ['exception' => 'gone', 'decisionId' => 'dec-9']);
		$repository = $this->repository($objects, 'beschikking', $logger);

		$this->assertNull($repository->find('dec-9'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not_found');
		$repository->requireBeschikking('dec-9');
	}//end testAFailedReadIsNotFound()

	/**
	 * Without storage or a schema nothing is read, and a write is refused by name.
	 *
	 * @return void
	 */
	public function testWithoutStorageOrSchemaNothingIsReadOrWritten(): void {
		$this->assertNull($this->repository(null)->find('dec-1'));
		$this->assertNull($this->repository(new InMemoryRegister(), '')->find('dec-1'));

		try {
			$this->repository(null)->save(['reference' => 'B-1']);
			$this->fail('saved without storage');
		} catch (RuntimeException $e) {
			$this->assertSame('storage_unavailable', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('beschikking_schema_not_configured');
		$this->repository(new InMemoryRegister(), '')->save(['reference' => 'B-1']);
	}//end testWithoutStorageOrSchemaNothingIsReadOrWritten()
}//end class
