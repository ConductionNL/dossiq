<?php

/**
 * PublishedCase Unit Tests
 *
 * The case read by id in the configured register and case schema: an array
 * as is, an entity through its JSON form, a missing or unreadable case
 * refused (the unreadable one logged); and the save back into the same
 * register and schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use JsonSerializable;
use OCA\Dossiq\Service\Publication\PublishedCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for PublishedCase.
 *
 * @covers \OCA\Dossiq\Service\Publication\PublishedCase
 */
final class PublishedCaseTest extends TestCase {

	/**
	 * The entry every test reads and saves against.
	 *
	 * @var array<string, mixed>
	 */
	private const ENTRY = ['caseId' => 'case-1', 'register' => '23', 'schema' => '5'];

	/**
	 * An object store answering the given find result, or throwing it.
	 *
	 * @param mixed $found What find answers, or a Throwable it throws.
	 *
	 * @return object
	 */
	private function store(mixed $found): object {
		return new class($found) {
			/**
			 * The find calls, as [id, register, schema].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $finds = [];

			/**
			 * The saves, as [object, register, schema].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $found What find answers, or a Throwable it throws.
			 */
			public function __construct(private mixed $found) {
			}

			/**
			 * Record the find and answer or throw.
			 *
			 * @param string            $id      The id.
			 * @param ObjectFindOptions $options Where to look.
			 *
			 * @return mixed
			 */
			public function find(string $id, ObjectFindOptions $options): mixed {
				$this->finds[] = [$id, $options->register, $options->schema];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}

			/**
			 * Record the save.
			 *
			 * @param array             $object  The object.
			 * @param ObjectSaveOptions $options Where to save.
			 *
			 * @return array
			 */
			public function saveObject(array $object, ObjectSaveOptions $options): array {
				$this->saves[] = [$object, $options->target->register, $options->target->schema];
				return $object;
			}
		};
	}//end store()

	/**
	 * An array case is read as is, by id in the configured register and schema.
	 *
	 * @return void
	 */
	public function testAnArrayCaseIsReadAsIs(): void {
		$store = $this->store(found: ['id' => 'case-1', 'title' => 'Besluit']);

		$case = (new PublishedCase(logger: $this->createMock(originalClassName: LoggerInterface::class)))->load(objectService: $store, entry: self::ENTRY);

		$this->assertSame(expected: ['id' => 'case-1', 'title' => 'Besluit'], actual: $case);
		$this->assertSame(expected: [['case-1', '23', '5']], actual: $store->finds);
	}//end testAnArrayCaseIsReadAsIs()

	/**
	 * An entity is read through its JSON form.
	 *
	 * @return void
	 */
	public function testAnEntityIsReadThroughItsJsonForm(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The case as an array.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-1'];
			}
		};

		$case = (new PublishedCase(logger: $this->createMock(originalClassName: LoggerInterface::class)))->load(
			objectService: $this->store(found: $entity),
			entry: self::ENTRY
		);

		$this->assertSame(expected: ['id' => 'case-1'], actual: $case);
	}//end testAnEntityIsReadThroughItsJsonForm()

	/**
	 * A case that is not there is refused without a log line.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Case not found: case-1');

		(new PublishedCase(logger: $logger))->load(objectService: $this->store(found: null), entry: self::ENTRY);
	}//end testAMissingCaseIsRefused()

	/**
	 * A read that fails is logged with the case and the reason, and refused.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'PublicationService::publish find failed',
			['app' => 'dossiq', 'caseId' => 'case-1', 'error' => 'store down']
		);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Case not found: case-1');

		(new PublishedCase(logger: $logger))->load(objectService: $this->store(found: new RuntimeException('store down')), entry: self::ENTRY);
	}//end testAFailedReadIsLoggedAndRefused()

	/**
	 * The case is saved back into the configured register and schema.
	 *
	 * @return void
	 */
	public function testTheCaseIsSavedIntoItsRegisterAndSchema(): void {
		$store = $this->store(found: null);

		(new PublishedCase(logger: $this->createMock(originalClassName: LoggerInterface::class)))->save(
			objectService: $store,
			case: ['id' => 'case-1', 'publishedAt' => '2026-11-27T10:00:00+00:00'],
			entry: self::ENTRY
		);

		$this->assertSame(
			expected: [[['id' => 'case-1', 'publishedAt' => '2026-11-27T10:00:00+00:00'], '23', '5']],
			actual: $store->saves
		);
	}//end testTheCaseIsSavedIntoItsRegisterAndSchema()
}//end class
