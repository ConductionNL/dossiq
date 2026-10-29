<?php

/**
 * CaseRelationStore Unit Tests
 *
 * OpenRegister's object service is shared within a request and keeps the
 * register and schema an earlier call set. Asked for a case's uses or used-by
 * under another schema, it looks the case up in that schema's table, logs
 * "Object not found in magic table" and answers nothing, so the typed relation
 * groups ("Wacht op") were missing on DqZaakGerelateerd.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-relations-scoped-to-the-case/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CaseRelationStore::relationRows().
 */
class CaseRelationStoreTest extends TestCase {
	/**
	 * A fake object service that answers only under the case register and schema.
	 *
	 * @return object The fake.
	 */
	private function objectService(): object {
		return new class {
			/** @var array<int, string> */
			public array $calls = [];

			public string $register = 'stale-register';

			public string $schema = 'stale-schema';

			public function setRegister(string $register): void {
				$this->calls[] = 'register:'.$register;
				$this->register = $register;
			}

			public function setSchema(string $schema): void {
				$this->calls[] = 'schema:'.$schema;
				$this->schema = $schema;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getObjectUses(string $objectId): array {
				return $this->answer();
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getObjectUsedBy(string $objectId): array {
				return $this->answer();
			}

			/**
			 * @return array<string, mixed>
			 */
			private function answer(): array {
				if ($this->register !== 'dossiq' || $this->schema !== 'case') {
					return ['results' => [], 'total' => 0];
				}

				return ['results' => [['id' => 'far', 'relation' => ['property' => 'blockingCases', 'type' => 'waitsOn']]], 'total' => 1];
			}
		};
	}

	/**
	 * The store over a settings service naming register dossiq and schema case.
	 *
	 * @param object $objectService The object service.
	 *
	 * @return CaseRelationStore The store.
	 */
	private function store(object $objectService): CaseRelationStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		return new CaseRelationStore($settings, $this->createMock(LoggerInterface::class));
	}

	/**
	 * Both directions are asked under the case register and schema, register first.
	 *
	 * @return void
	 */
	public function testAsksUnderTheCaseSchemaWhateverWasSetBefore(): void {
		$objectService = $this->objectService();
		$store         = $this->store($objectService);

		foreach ([false, true] as $incoming) {
			$rows = $store->relationRows(caseUuid: 'case-1', incoming: $incoming);
			$this->assertSame('blockingCases', $rows[0]['relation']['property'] ?? null);
		}

		$this->assertSame('register:dossiq', $objectService->calls[0] ?? null);
		$this->assertSame('schema:case', $objectService->calls[1] ?? null);
	}
}
