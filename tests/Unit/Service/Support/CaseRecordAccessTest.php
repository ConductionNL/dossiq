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

/**
 * The refusals name what was not done to the case.
 *
 * @covers \OCA\Dossiq\Service\Support\CaseRecordAccess
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
			public function find(string $id, mixed $register = null, mixed $schema = null): never {
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
			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				return null;
			}//end find()
		};
		$missing = $this->refusal(fn () => $this->records(objectService: $empty)->read(caseId: 'c1'));
		self::assertSame('case-not-found', $missing->getRule());
		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $missing->getStatus());
	}//end testEachFailureRefuses()
}//end class
