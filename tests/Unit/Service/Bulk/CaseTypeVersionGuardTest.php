<?php

/**
 * Unit tests for the selection-time case type version guard.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bulk
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
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bulk;

use OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard;
use OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A selection spanning two versions of one case type is refused; anything else passes.
 *
 * @covers \OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard
 * @uses \OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseTypeVersionGuardTest extends TestCase {

	/**
	 * Build the guard over cases and case types held in memory.
	 *
	 * @param array<string, mixed>               $cases     Case id to its row (or a Throwable to throw).
	 * @param array<string, array<string, mixed>> $caseTypes Case type id to its row.
	 * @param array<string, string>              $config    Overrides of the dossiq settings.
	 *
	 * @return CaseTypeVersionGuard The guard.
	 */
	private function guard(array $cases, array $caseTypes, array $config = []): CaseTypeVersionGuard {
		$objects = new class($cases, $caseTypes) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>               $cases     The cases.
			 * @param array<string, array<string, mixed>> $caseTypes The case types.
			 */
			public function __construct(private readonly array $cases, private readonly array $caseTypes) {
			}

			/**
			 * Find a case or a case type.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed The row.
			 */
			public function find(string $id, string $register = '', string $schema = ''): mixed {
				$store = ($schema === 'case') ? $this->cases : $this->caseTypes;
				if (array_key_exists($id, $store) === false) {
					throw new RuntimeException('not found: ' . $id);
				}

				if ($store[$id] instanceof \Throwable) {
					throw $store[$id];
				}

				return $store[$id];
			}
		};

		$values = $config + ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		return new CaseTypeVersionGuard(settingsService: $settings, caseTypes: new CaseTypeStore(settingsService: $settings));
	}//end guard()

	/**
	 * Run the guard and answer the exception it threw, or null.
	 *
	 * @param CaseTypeVersionGuard $guard   The guard.
	 * @param array<int, string>   $caseIds The selection.
	 *
	 * @return MixedCaseTypeVersionsException|null The refusal.
	 */
	private function refusal(CaseTypeVersionGuard $guard, array $caseIds): ?MixedCaseTypeVersionsException {
		try {
			$guard->assertOneVersion(caseIds: $caseIds);
		} catch (MixedCaseTypeVersionsException $e) {
			return $e;
		}

		return null;
	}//end refusal()

	/**
	 * Two versions of one case type, linked through previousVersion, are refused with counts.
	 *
	 * @return void
	 */
	public function testTwoVersionsOfOneFamilyAreRefused(): void {
		$guard = $this->guard(
			cases: [
				'c-1' => ['caseType' => 'ct-v3'],
				'c-2' => ['caseType' => 'ct-v2'],
				'c-3' => ['caseType' => ['id' => 'ct-v2']],
			],
			caseTypes: [
				'ct-v2' => ['version' => 2, 'title' => 'Bezwaar'],
				'ct-v3' => ['version' => 3, 'title' => 'Bezwaar', 'previousVersion' => 'ct-v2'],
			]
		);

		$refusal = $this->refusal(guard: $guard, caseIds: ['c-1', 'c-2', 'c-3']);

		self::assertNotNull($refusal);
		self::assertSame(expected: 'mixed_case_type_versions', actual: $refusal->getMessage());
		self::assertSame(expected: 'Bezwaar', actual: $refusal->getCaseTypeTitle());
		self::assertSame(expected: [2, 3], actual: $refusal->getVersions());
		self::assertSame(expected: [3 => 1, 2 => 2], actual: $refusal->getCounts());
	}//end testTwoVersionsOfOneFamilyAreRefused()

	/**
	 * Two unrelated case types are not a version mix.
	 *
	 * @return void
	 */
	public function testUnrelatedCaseTypesPass(): void {
		$guard = $this->guard(
			cases: ['c-1' => ['caseType' => 'ct-a'], 'c-2' => ['caseType' => 'ct-b']],
			caseTypes: ['ct-a' => ['version' => 1], 'ct-b' => ['version' => 2]]
		);

		self::assertNull($this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']));
	}//end testUnrelatedCaseTypesPass()

	/**
	 * A selection of one case type is never refused, even without the case types readable.
	 *
	 * @return void
	 */
	public function testOneCaseTypeIsNeverRefused(): void {
		$guard = $this->guard(cases: ['c-1' => ['caseType' => 'ct-a'], 'c-2' => ['caseType' => 'ct-a']], caseTypes: []);

		self::assertNull($this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']));
	}//end testOneCaseTypeIsNeverRefused()

	/**
	 * The family is followed across several hops, and a missing ancestor names the family.
	 *
	 * @return void
	 */
	public function testTheFamilyIsFollowedToItsRoot(): void {
		$guard = $this->guard(
			cases: ['c-1' => ['caseType' => 'ct-v3'], 'c-2' => ['caseType' => 'ct-v2b']],
			caseTypes: [
				'ct-v2' => ['version' => 2, 'previousVersion' => 'ct-v1-gone'],
				'ct-v3' => ['version' => 3, 'previousVersion' => 'ct-v2'],
				'ct-v2b' => ['version' => 2, 'previousVersion' => 'ct-v1-gone'],
			]
		);

		$refusal = $this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']);

		self::assertNotNull($refusal);
		self::assertSame(expected: 'ct-v1-gone', actual: $refusal->getCaseTypeTitle(), message: 'no title: the family id stands in');
		self::assertSame(expected: [2, 3], actual: $refusal->getVersions());
	}//end testTheFamilyIsFollowedToItsRoot()

	/**
	 * A previousVersion cycle ends the walk instead of looping.
	 *
	 * @return void
	 */
	public function testAVersionCycleEndsTheWalk(): void {
		$guard = $this->guard(
			cases: ['c-1' => ['caseType' => 'ct-a'], 'c-2' => ['caseType' => 'ct-b']],
			caseTypes: [
				'ct-a' => ['version' => 1, 'previousVersion' => 'ct-b'],
				'ct-b' => ['version' => 2, 'previousVersion' => 'ct-a'],
			]
		);

		self::assertNull(
			$this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']),
			'each case type walks to the other and stops there, so they are two families'
		);
	}//end testAVersionCycleEndsTheWalk()

	/**
	 * Same-version case types of one family pass, and a missing version counts as 1.
	 *
	 * @return void
	 */
	public function testOneVersionAcrossCopiesPasses(): void {
		$guard = $this->guard(
			cases: ['c-1' => ['caseType' => 'ct-v1'], 'c-2' => ['caseType' => 'ct-copy']],
			caseTypes: [
				'ct-v1' => ['title' => 'Melding'],
				'ct-copy' => ['version' => 1, 'previousVersion' => 'ct-v1'],
			]
		);

		self::assertNull($this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']));
	}//end testOneVersionAcrossCopiesPasses()

	/**
	 * Blank ids, unreadable cases and cases without a case type are ignored.
	 *
	 * @return void
	 */
	public function testUnreadableCasesAreIgnored(): void {
		$guard = $this->guard(
			cases: [
				'c-1' => ['caseType' => 'ct-v2'],
				'c-2' => new RuntimeException('gone'),
				'c-3' => ['caseType' => ''],
				'c-4' => 'not a row',
			],
			caseTypes: [
				'ct-v1' => ['version' => 1],
				'ct-v2' => ['version' => 2, 'previousVersion' => 'ct-v1'],
			]
		);

		self::assertNull($this->refusal(guard: $guard, caseIds: ['c-1', ' ', 'c-2', 'c-3', 'c-4', 'c-missing']));
	}//end testUnreadableCasesAreIgnored()

	/**
	 * Case entities are read through getObject or jsonSerialize.
	 *
	 * @return void
	 */
	public function testEntitiesAreReadThroughTheirAccessors(): void {
		$viaGetObject = new class {
			/**
			 * The object data.
			 *
			 * @return array<string, string> The row.
			 */
			public function getObject(): array {
				return ['caseType' => 'ct-v1'];
			}
		};
		$viaJson = new class {
			/**
			 * Serialise.
			 *
			 * @return array<string, string> The row.
			 */
			public function jsonSerialize(): array {
				return ['caseType' => 'ct-v2'];
			}
		};

		$guard = $this->guard(
			cases: ['c-1' => $viaGetObject, 'c-2' => $viaJson],
			caseTypes: [
				'ct-v1' => ['version' => 1, 'title' => 'Vergunning'],
				'ct-v2' => ['version' => 2, 'previousVersion' => 'ct-v1'],
			]
		);

		$refusal = $this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']);

		self::assertNotNull($refusal);
		self::assertSame(expected: 'Vergunning', actual: $refusal->getCaseTypeTitle());
		self::assertSame(expected: [1 => 1, 2 => 1], actual: $refusal->getCounts());
	}//end testEntitiesAreReadThroughTheirAccessors()

	/**
	 * Without a configured case schema nothing is read and nothing is refused.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaRefusesNothing(): void {
		$guard = $this->guard(
			cases: ['c-1' => ['caseType' => 'ct-v1'], 'c-2' => ['caseType' => 'ct-v2']],
			caseTypes: ['ct-v1' => ['version' => 1], 'ct-v2' => ['version' => 2, 'previousVersion' => 'ct-v1']],
			config: ['case_schema' => '']
		);

		self::assertNull($this->refusal(guard: $guard, caseIds: ['c-1', 'c-2']));
	}//end testAnUnconfiguredCaseSchemaRefusesNothing()
}//end class
