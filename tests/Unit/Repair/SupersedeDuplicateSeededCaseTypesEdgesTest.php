<?php

/**
 * SupersedeDuplicateSeededCaseTypes when OpenRegister is missing, a read or a write fails, or a row is shaped unusually.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SupersedeDuplicateSeededCaseTypes;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An ObjectService double whose read or write can fail.
 */
class FailingDuplicateCaseTypeObjectService {

	/**
	 * The stored case types.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every partial write that went through, as [id, changes].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $patches = [];

	/**
	 * Whether a read throws.
	 *
	 * @var boolean
	 */
	public bool $failRead = false;

	/**
	 * The object ids whose write throws.
	 *
	 * @var array<int, string>
	 */
	public array $failWrites = [];

	/**
	 * Mimic ObjectService::searchObjects().
	 *
	 * @param array<string, mixed> $query The query.
	 * @param mixed ...$scope Scope arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query, mixed ...$scope): array {
		if ($this->failRead === true) {
			throw new RuntimeException('database gone');
		}

		return $this->rows;
	}//end searchObjects()

	/**
	 * Mimic ObjectService::patchObject().
	 *
	 * @param string $objectId The object.
	 * @param array<string, mixed> $data The changes.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
		if (in_array($objectId, $this->failWrites, true) === true) {
			throw new RuntimeException('write refused');
		}

		$this->patches[] = [$objectId, $data];
		return array_merge(['id' => $objectId], $data);
	}//end patchObject()
}//end class

/**
 * @covers \OCA\Dossiq\Repair\SupersedeDuplicateSeededCaseTypes
 */
class SupersedeDuplicateSeededCaseTypesEdgesTest extends TestCase {

	/**
	 * The step over the given object service and configuration.
	 *
	 * @param object|null          $objects The object service, or null when OpenRegister is missing.
	 * @param array<string,string> $config  The config values.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return SupersedeDuplicateSeededCaseTypes
	 */
	private function step(?object $objects, array $config=['register' => '20', 'case_type_schema' => '28'], ?LoggerInterface $logger=null): SupersedeDuplicateSeededCaseTypes {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return new SupersedeDuplicateSeededCaseTypes(
			settingsService: $settings,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end step()

	/**
	 * An output that expects exactly one info line.
	 *
	 * @param string $line The line.
	 *
	 * @return IOutput
	 */
	private function outputSaying(string $line): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($line);
		return $output;
	}//end outputSaying()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertSame(
			'Supersede the older copies of a seeded case type stored twice',
			$this->step(objects: null)->getName()
		);
	}//end testTheStepNamesWhatItDoes()

	/**
	 * Without OpenRegister the step skips and says so.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$this->step(objects: null)->run(
			$this->outputSaying('OpenRegister or the case type schema is not available; skipping.')
		);
	}//end testWithoutOpenRegisterTheStepSkips()

	/**
	 * Without a case type schema the step skips and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutACaseTypeSchemaTheStepSkips(): void {
		$objects       = new FailingDuplicateCaseTypeObjectService();
		$objects->rows = [
			['@self' => ['id' => 'a', 'slug' => 's', 'created' => '1'], 'version' => 1],
			['@self' => ['id' => 'b', 'slug' => 's', 'created' => '2'], 'version' => 1],
		];

		$this->step(objects: $objects, config: ['register' => '20'])->run(
			$this->outputSaying('OpenRegister or the case type schema is not available; skipping.')
		);

		self::assertSame([], $objects->patches);
	}//end testWithoutACaseTypeSchemaTheStepSkips()

	/**
	 * A read that fails is logged and nothing is superseded.
	 *
	 * @return void
	 */
	public function testAFailedReadSupersedesNothing(): void {
		$objects           = new FailingDuplicateCaseTypeObjectService();
		$objects->failRead = true;
		$logger            = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq: could not read the case types to supersede seeded duplicates',
				['app' => 'dossiq', 'exception' => 'database gone']
			);

		$this->step(objects: $objects, logger: $logger)->run(
			$this->outputSaying('Duplicate seeded case types: 0 older copies superseded.')
		);

		self::assertSame([], $objects->patches);
	}//end testAFailedReadSupersedesNothing()

	/**
	 * A write that fails is logged and not counted; the other older copy is still superseded.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndNotCounted(): void {
		$objects             = new FailingDuplicateCaseTypeObjectService();
		$objects->rows       = [
			['@self' => ['id' => 'old-1', 'slug' => 'permit', 'created' => '2026-01-01'], 'version' => 1],
			['@self' => ['id' => 'old-2', 'slug' => 'permit', 'created' => '2026-02-01'], 'version' => 1],
			['@self' => ['id' => 'new', 'slug' => 'permit', 'created' => '2026-03-01'], 'version' => 1],
		];
		$objects->failWrites = ['old-1'];
		$logger              = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq: could not supersede a duplicate seeded case type',
				['app' => 'dossiq', 'id' => 'old-1', 'exception' => 'write refused']
			);

		$this->step(objects: $objects, logger: $logger)->run(
			$this->outputSaying('Duplicate seeded case types: 1 older copies superseded.')
		);

		self::assertSame([['old-2', ['supersededBy' => 'new']]], $objects->patches);
	}//end testAFailedWriteIsLoggedAndNotCounted()

	/**
	 * An organisation given as an object groups by its uuid, so two organisations stay apart.
	 *
	 * @return void
	 */
	public function testAnOrganisationObjectGroupsByItsUuid(): void {
		$objects       = new FailingDuplicateCaseTypeObjectService();
		$objects->rows = [
			['@self' => ['id' => 'a-old', 'slug' => 'permit', 'created' => '1', 'organisation' => ['uuid' => 'org-a']], 'version' => 1],
			['@self' => ['id' => 'b-only', 'slug' => 'permit', 'created' => '2', 'organisation' => ['id' => 'org-b']], 'version' => 1],
			['@self' => ['id' => 'a-new', 'slug' => 'permit', 'created' => '3', 'organisation' => 'org-a'], 'version' => 1],
		];

		$this->step(objects: $objects)->run(
			$this->outputSaying('Duplicate seeded case types: 1 older copies superseded.')
		);

		self::assertSame([['a-old', ['supersededBy' => 'a-new']]], $objects->patches);
	}//end testAnOrganisationObjectGroupsByItsUuid()

	/**
	 * A row whose metadata is not an array has no slug and is left alone.
	 *
	 * @return void
	 */
	public function testARowWithoutMetadataIsLeftAlone(): void {
		$objects       = new FailingDuplicateCaseTypeObjectService();
		$objects->rows = [
			['@self' => 'broken', 'id' => 'x', 'version' => 1],
			['@self' => 'broken', 'id' => 'y', 'version' => 1],
		];

		$this->step(objects: $objects)->run(
			$this->outputSaying('Duplicate seeded case types: 0 older copies superseded.')
		);

		self::assertSame([], $objects->patches);
	}//end testARowWithoutMetadataIsLeftAlone()
}//end class
