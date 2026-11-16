<?php

/**
 * CaseRecordAccess — one case read and written back, or a refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseRecordAccess;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The refusals name what was not done to the case.
 *
 * @covers \OCA\Dossiq\Service\Support\CaseRecordAccess
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
final class CaseRecordAccessTest extends TestCase {

	/**
	 * Access over the given object service.
	 *
	 * @param object|null $objectService The object service, or null when absent.
	 *
	 * @return CaseRecordAccess
	 */
	private function records(?object $objectService): CaseRecordAccess {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'reg'], ['case_schema', '', 'case']]);

		return new CaseRecordAccess(settingsService: $settings, store: new CaseTypeStore(settingsService: $settings), done: 'moved');
	}//end records()

	/**
	 * Run a call and answer the refusal it throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return RefusedException
	 */
	private function refusal(callable $call): RefusedException {
		try {
			$call();
		} catch (RefusedException $e) {
			return $e;
		}

		self::fail('No refusal');
	}//end refusal()

	/**
	 * An absent register, an unreadable case, a missing case and a failed write each refuse.
	 *
	 * @return void
	 */
	public function testEachFailureRefuses(): void {
		$absent = $this->refusal(fn () => $this->records(objectService: null)->read(caseId: 'c1'));
		self::assertSame('case-store-unavailable', $absent->getRule());

		$broken = new class {
			/**
			 * Fail to read.
			 *
			 * @param string $id The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema The schema.
			 *
			 * @return never
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): never {
				$register = $options?->register;
				$schema = $options?->schema;
				throw new \RuntimeException('down');
			}//end find()

			/**
			 * Fail to write.
			 *
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The data.
			 *
			 * @return never
			 */
			public function updateObject(string $objectId, array $data): never {
				throw new \RuntimeException('down');
			}//end updateObject()
		};
		$unreadable = $this->refusal(fn () => $this->records(objectService: $broken)->read(caseId: 'c1'));
		self::assertSame('case-unreadable', $unreadable->getRule());
		self::assertSame(RefusedException::STATUS_INDETERMINATE, $unreadable->getStatus());

		$unwritten = $this->refusal(fn () => $this->records(objectService: $broken)->write(caseId: 'c1', case: [], rule: 'move-not-written'));
		self::assertSame('move-not-written', $unwritten->getRule());

		$empty = new class {
			/**
			 * Find nothing.
			 *
			 * @param string $id The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema The schema.
			 *
			 * @return null
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = $options?->register;
				$schema = $options?->schema;
				return null;
			}//end find()
		};
		$missing = $this->refusal(fn () => $this->records(objectService: $empty)->read(caseId: 'c1'));
		self::assertSame('case-not-found', $missing->getRule());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $missing->getStatus());
	}//end testEachFailureRefuses()

	/**
	 * A found case is read from the configured register and schema, and written back by its id.
	 *
	 * @return void
	 */
	public function testAFoundCaseIsReadAndWrittenBack(): void {
		$store = new class {
			/**
			 * The finds asked, as [id, register, schema].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $finds = [];

			/**
			 * The writes asked, as [id, data].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $writes = [];

			/**
			 * Find the case.
			 *
			 * @param string                 $id            The id.
			 * @param ObjectFindOptions|null $options       The options.
			 * @param bool                   $_rbac         Unused.
			 * @param bool                   $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->finds[] = [$id, $options?->register, $options?->schema];
				return ['id' => $id, 'title' => 'Bouwvergunning', 'status' => 'intake'];
			}//end find()

			/**
			 * Write the case.
			 *
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The data.
			 *
			 * @return void
			 */
			public function updateObject(string $objectId, array $data): void {
				$this->writes[] = [$objectId, $data];
			}//end updateObject()
		};
		$records = $this->records(objectService: $store);

		$case = $records->read(caseId: 'c1');
		self::assertSame(['id' => 'c1', 'title' => 'Bouwvergunning', 'status' => 'intake'], $case);
		self::assertSame([['c1', 'reg', 'case']], $store->finds);

		$case['status'] = 'behandeling';
		$records->write(caseId: 'c1', case: $case, rule: 'move-not-written');
		self::assertSame([['c1', ['id' => 'c1', 'title' => 'Bouwvergunning', 'status' => 'behandeling']]], $store->writes);
	}//end testAFoundCaseIsReadAndWrittenBack()
}//end class
