<?php

/**
 * The ownership check the free-form move stands on.
 *
 * 🔴 IT REFUSED EVERY MOVE. `assertStatusBelongsToCaseType()` read
 * `caseType.statusTypes`, a property the schema does not declare: the link
 * lives on the CHILD, as `statusType.caseType`. So the list was empty on every
 * real case type, the loop matched nothing, and an admin's free-form move
 * answered `status_type_not_in_case_type` whatever it was asked to do. Nothing
 * was red, because nothing tested this path.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 */
class CaseStatusStoreOwnershipTest extends TestCase {
	/**
	 * A status whose `caseType` names the case type is accepted.
	 *
	 * @return void
	 */
	public function testTheBackReferenceOnTheStatusIsEnough(): void {
		$store = $this->store(
			[
				'ct-1' => ['id' => 'ct-1', 'title' => 'Bezwaar'],
				'st-1' => ['id' => 'st-1', 'name' => 'In behandeling', 'caseType' => 'ct-1'],
			]
		);

		$store->assertStatusBelongsToCaseType(caseTypeId: 'ct-1', statusTypeId: 'st-1');

		// Reaching here IS the assertion: the method answers by throwing.
		self::assertTrue(true);
	}//end testTheBackReferenceOnTheStatusIsEnough()

	/**
	 * An expanded back-reference is read too.
	 *
	 * @return void
	 */
	public function testAnExpandedBackReferenceIsRead(): void {
		$store = $this->store(
			[
				'ct-1' => ['id' => 'ct-1'],
				'st-1' => ['id' => 'st-1', 'caseType' => ['id' => 'ct-1', 'title' => 'Bezwaar']],
			]
		);

		$store->assertStatusBelongsToCaseType(caseTypeId: 'ct-1', statusTypeId: 'st-1');

		self::assertTrue(true);
	}//end testAnExpandedBackReferenceIsRead()

	/**
	 * A status belonging to ANOTHER case type is still refused.
	 *
	 * @return void
	 */
	public function testAStatusOfAnotherCaseTypeIsRefused(): void {
		$store = $this->store(
			[
				'ct-1' => ['id' => 'ct-1'],
				'st-1' => ['id' => 'st-1', 'caseType' => 'ct-other'],
			]
		);

		$this->expectExceptionMessage('status_type_not_in_case_type');

		$store->assertStatusBelongsToCaseType(caseTypeId: 'ct-1', statusTypeId: 'st-1');
	}//end testAStatusOfAnotherCaseTypeIsRefused()

	/**
	 * An unreadable status type refuses rather than passing.
	 *
	 * @return void
	 */
	public function testAnUnreadableStatusTypeIsRefused(): void {
		$store = $this->store(['ct-1' => ['id' => 'ct-1']]);

		$this->expectExceptionMessage('status_type_not_in_case_type');

		$store->assertStatusBelongsToCaseType(caseTypeId: 'ct-1', statusTypeId: 'st-unknown');
	}//end testAnUnreadableStatusTypeIsRefused()

	/**
	 * A store over a fixed set of objects, keyed by id.
	 *
	 * @param array<string, array<string, mixed>> $objects The store contents.
	 *
	 * @return CaseStatusStore The store under test.
	 */
	private function store(array $objects): CaseStatusStore {
		$objectService = new class($objects) {
			/**
			 * @param array<string, array<string, mixed>> $objects The contents.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * @param string $id       The object id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				if (isset($this->objects[$id]) === false) {
					throw new RuntimeException('not found');
				}

				return $this->objects[$id];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'status_type_schema' => 'statusType',
			][$key] ?? '')
		);

		return new CaseStatusStore($settings, new StatusTypeLookup($settings, new CaseTypeResolver(new CaseTypeStore($settings))), new NullLogger());
	}//end store()
}//end class
