<?php

/**
 * ZgwZtcRulesService Unit Tests — publish + deletion validation paths.
 *
 * Focused on the case-types-02-backend-validation tasks: the test fixture
 * builds the rules service against mocked SettingsService + FieldValidator
 * dependencies and stubs the OpenRegister `ObjectService::searchObjectsBySlug`
 * surface so each scenario can deterministically wire status / case rows.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\ZgwZtcRulesService
 * @covers \OCA\Dossiq\Service\Zgw\ZtcConceptRules
 * @covers \OCA\Dossiq\Service\Zgw\ZtcTypeReferences
 * @covers \OCA\Dossiq\Service\Zgw\ZtcPublishAndDeleteChecks
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\DeadlineMonitoringSeedDataService
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses   \OCA\Dossiq\Service\FieldValidator
 */
class ZgwZtcRulesServiceTest extends TestCase {
	private ZgwZtcRulesService $svc;
	private SettingsService $settings;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(LoggerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			function (string $key, string $default = ''): string {
				return match ($key) {
					'status_type_schema' => 'statusType',
					'case_type_schema' => 'caseType',
					'case_schema' => 'case',
					default => $default,
				};
			}
		);
		$this->svc = new ZgwZtcRulesService(
			$logger,
			$this->settings,
			new FieldValidator(),
		);
	}

	/**
	 * Build an OR-stub that resolves a deterministic searchObjectsBySlug map.
	 *
	 * @param array<string, array<int, array<string,mixed>>> $byKey Map keyed by "{register}|{schema}|{filter_json}".
	 *
	 * @return object
	 */
	private function stubObjectService(array $byKey): object {
		return new class($byKey) {
			public function __construct(
				private array $map,
			) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				// Real OpenRegister contract: a top-level `id`/`uuid` filter
				// addresses a schema property no schema declares and matches
				// ZERO rows. Only declared-property filters resolve.
				if (isset($filters['id']) === true || isset($filters['uuid']) === true) {
					return [];
				}

				// Key on (register|schema|caseType filter) — the production filter shape.
				$caseType = (string)($filters['caseType'] ?? '');
				$isFinal = isset($filters['isFinal']) === true ? '1' : '';
				$key = $register . '|' . $schema . '|' . $caseType . '|' . $isFinal . '|';
				return $this->map[$key] ?? [];
			}

			public function searchObjects(array $query): array {
				return [];
			}

			/**
			 * The real get-by-id path: resolves the id directly, throws on a
			 * miss, returns an entity-shaped object like the live service.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			): object {
				foreach ($this->map as $rows) {
					foreach ($rows as $row) {
						if (($row['id'] ?? null) === (string)$id) {
							return new class ($row) implements \JsonSerializable {
								public function __construct(private readonly array $row) {
								}

								public function jsonSerialize(): array {
									return $this->row;
								}
							};
						}
					}
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('Object ' . $id . ' does not exist');
			}
		};
	}

	public function testValidatePublishFailsWithNoStatusTypes(): void {
		$os = $this->stubObjectService([]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains('At least one status type must be defined before publishing', $errors);
	}

	public function testValidatePublishFailsWithNoFinalStatus(): void {
		$os = $this->stubObjectService([
			'reg|statusType|ct-1||' => [['id' => 's1', 'isFinal' => false]],
		]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains('At least one status type must be marked as final', $errors);
		$this->assertNotContains('At least one status type must be defined before publishing', $errors);
	}

	public function testValidatePublishFailsWithMissingValidFrom(): void {
		$os = $this->stubObjectService([
			'reg|statusType|ct-1||' => [['id' => 's1', 'isFinal' => true]],
			'reg|caseType|||ct-1' => [['id' => 'ct-1', 'validFrom' => '']],
		]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains("'Valid from' date must be set before publishing", $errors);
	}

	public function testValidatePublishSucceedsWithAllPrerequisites(): void {
		$os = $this->stubObjectService([
			'reg|statusType|ct-1||' => [
				['id' => 's1', 'isFinal' => false],
				['id' => 's2', 'isFinal' => true],
			],
			'reg|caseType|||ct-1' => [['id' => 'ct-1', 'validFrom' => '2026-01-01']],
		]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertSame([], $errors);
	}

	public function testValidatePublishFailsWithoutObjectService(): void {
		$this->svc->setContext(null, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains('Cannot validate publish: OpenRegister object service unavailable', $errors);
	}

	public function testValidatePublishFailsWithEmptyCaseTypeId(): void {
		$os = $this->stubObjectService([]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', '');
		$this->assertContains('Cannot validate publish: case type id is empty', $errors);
	}

	public function testValidateDeletionAllowedWhenNoCasesExist(): void {
		$os = $this->stubObjectService([]);
		$this->svc->setContext($os, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertFalse($r['blocked']);
		$this->assertFalse($r['requiresConfirmation']);
		$this->assertSame('', $r['message']);
	}

	public function testValidateDeletionBlockedWhenActiveCasesExist(): void {
		// Two cases exist; the schemaSettings's case_schema key resolves to 'case'.
		// No final status types, so every case is treated as active.
		$os = $this->stubObjectService([
			'reg|case|ct-1||' => [
				['id' => 'c1', 'status' => 'open'],
				['id' => 'c2', 'status' => 'in_progress'],
			],
		]);
		$this->svc->setContext($os, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertTrue($r['blocked']);
		$this->assertFalse($r['requiresConfirmation']);
		$this->assertStringContainsString('2 active case(s)', $r['message']);
	}

	public function testValidateDeletionRequiresConfirmationWhenOnlyClosedCases(): void {
		// 1 closed case (status === 'final'), no active.
		// Final status types list contains 'final'.
		$os = $this->stubObjectService([
			'reg|case|ct-1||' => [['id' => 'c1', 'status' => 'final']],
			'reg|statusType|ct-1|1|' => [['id' => 'final', 'isFinal' => true]],
		]);
		$this->svc->setContext($os, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertFalse($r['blocked']);
		$this->assertTrue($r['requiresConfirmation']);
		$this->assertStringContainsString('1 closed case(s)', $r['message']);
	}

	public function testValidateDeletionReturnsDefaultWithoutObjectService(): void {
		$this->svc->setContext(null, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertFalse($r['blocked']);
		$this->assertFalse($r['requiresConfirmation']);
	}

	/**
	 * An object service whose searches and finds are answered by closures.
	 *
	 * @param \Closure $search Answers (register, schema, filters).
	 * @param \Closure|null $find Answers (id); null means the row does not exist.
	 *
	 * @return object
	 */
	private function scriptedObjectService(\Closure $search, ?\Closure $find = null): object {
		return new class($search, $find) {
			public function __construct(
				private \Closure $search,
				private ?\Closure $find,
			) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return ($this->search)($register, $schema, $filters);
			}

			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): mixed {
				if ($this->find === null) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
				}

				return ($this->find)((string)$id);
			}
		};
	}

	/**
	 * Characterisation (method-decomposition): the publish check stops at a failed status type read.
	 */
	public function testValidatePublishStopsWhenStatusTypesCannotBeRead(): void {
		$os = $this->scriptedObjectService(static function (): array {
			throw new \RuntimeException('down');
		});
		$this->svc->setContext($os, []);

		$this->assertSame(['Could not load status types for case type'], $this->svc->validatePublish('reg', 'ct-1'));
	}

	/**
	 * Characterisation: every unmet precondition is reported, in order, and a case type that is
	 * missing or unreadable counts as one without a valid-from date.
	 */
	public function testValidatePublishReportsEveryUnmetPrecondition(): void {
		$none = static fn (): array => [];
		$notFinal = static fn (): array => [['isFinal' => 0], 'not a row', ['isFinal' => null]];
		$broken = static function (string $id): array {
			throw new \RuntimeException('down');
		};

		$this->svc->setContext($this->scriptedObjectService($none), []);
		$this->assertSame(
			['At least one status type must be defined before publishing', "'Valid from' date must be set before publishing"],
			$this->svc->validatePublish('reg', 'ct-1')
		);

		$this->svc->setContext($this->scriptedObjectService($notFinal, $broken), []);
		$this->assertSame(
			['At least one status type must be marked as final', "'Valid from' date must be set before publishing"],
			$this->svc->validatePublish('reg', 'ct-1')
		);

		$final = static fn (): array => [['isFinal' => 1]];
		$this->svc->setContext($this->scriptedObjectService($final, static fn (string $id): array => ['validFrom' => '2026-01-01']), []);
		$this->assertSame([], $this->svc->validatePublish('reg', 'ct-1'));
	}

	/**
	 * Characterisation: the publish check reads the status types of the case type from the status
	 * type schema, and the case type itself by id from the case type schema.
	 */
	public function testValidatePublishReadsTheConfiguredSchemas(): void {
		$seen = [];
		$search = static function (string $register, string $schema, array $filters) use (&$seen): array {
			$seen[] = [$register, $schema, $filters];
			return [];
		};
		$find = static function (string $id) use (&$seen): array {
			$seen[] = $id;
			return [];
		};
		$this->svc->setContext($this->scriptedObjectService($search, $find), []);
		$this->svc->validatePublish('reg', 'ct-1');

		$this->assertSame([['reg', 'statusType', ['caseType' => 'ct-1']], 'ct-1'], $seen);
	}

	/**
	 * Characterisation: a delete check that cannot read the cases, or has no id, lets the delete through.
	 */
	public function testValidateDeletionPassesWhenCasesCannotBeRead(): void {
		$default = ['blocked' => false, 'requiresConfirmation' => false, 'message' => ''];
		$os = $this->scriptedObjectService(static function (): array {
			throw new \RuntimeException('down');
		});
		$this->svc->setContext($os, []);

		$this->assertSame($default, $this->svc->validateDeletion('reg', 'ct-1'));
		$this->assertSame($default, $this->svc->validateDeletion('reg', ''));
	}

	/**
	 * Characterisation: a case counts as closed only when its status is a final status type that
	 * could be read; no status, another status, or an unreadable final list all count as active.
	 */
	public function testValidateDeletionCountsActiveAndClosedCases(): void {
		$cases = [['status' => 'final'], ['status' => 'open'], ['status' => ''], [], ['status' => 'final-2']];
		$search = static function (string $register, string $schema, array $filters) use ($cases): array {
			if ($schema === 'case') {
				return $cases;
			}

			return [['id' => 'final', 'isFinal' => true], 'not a row', ['isFinal' => true], ['id' => 'final-2']];
		};
		$this->svc->setContext($this->scriptedObjectService($search), []);

		$this->assertSame(
			[
				'blocked' => true,
				'requiresConfirmation' => false,
				'message' => 'Cannot delete case type: 3 active case(s) still use this type. Close or reassign all cases first.',
			],
			$this->svc->validateDeletion('reg', 'ct-1')
		);

		$unreadable = static function (string $register, string $schema, array $filters) use ($cases): array {
			if ($schema === 'case') {
				return [['status' => 'final'], ['status' => 'final-2']];
			}

			throw new \RuntimeException('down');
		};
		$this->svc->setContext($this->scriptedObjectService($unreadable), []);
		$this->assertStringContainsString('2 active case(s)', $this->svc->validateDeletion('reg', 'ct-1')['message']);

		$closedOnly = static function (string $register, string $schema, array $filters): array {
			if ($schema === 'case') {
				return [['status' => 'final'], ['status' => 'final']];
			}

			return [['id' => 'final']];
		};
		$this->svc->setContext($this->scriptedObjectService($closedOnly), []);
		$this->assertSame(
			['blocked' => false, 'requiresConfirmation' => true, 'message' => 'Deleting will affect 2 closed case(s). Confirm to proceed.'],
			$this->svc->validateDeletion('reg', 'ct-1')
		);
	}

	/**
	 * Characterisation: the delete check reads the cases of the case type, then its final status types.
	 */
	public function testValidateDeletionReadsTheConfiguredSchemas(): void {
		$seen = [];
		$search = static function (string $register, string $schema, array $filters) use (&$seen): array {
			$seen[] = [$register, $schema, $filters];
			return [['status' => 'x']];
		};
		$this->svc->setContext($this->scriptedObjectService($search), []);
		$this->svc->validateDeletion('reg', 'ct-1');

		$this->assertSame(
			[['reg', 'case', ['caseType' => 'ct-1']], ['reg', 'statusType', ['caseType' => 'ct-1', 'isFinal' => true]]],
			$seen
		);
	}
}
