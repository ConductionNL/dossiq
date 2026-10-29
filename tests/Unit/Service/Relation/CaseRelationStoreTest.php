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
			/**
			 * The setters called, in order.
			 *
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * The register an earlier call left set.
			 *
			 * @var string
			 */
			public string $register = 'stale-register';

			/**
			 * The schema an earlier call left set.
			 *
			 * @var string
			 */
			public string $schema = 'stale-schema';

			/**
			 * Mimic OR setRegister().
			 *
			 * @param string $register Register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
				$this->calls[]  = 'register:'.$register;
				$this->register = $register;
			}//end setRegister()

			/**
			 * Mimic OR setSchema().
			 *
			 * @param string $schema Schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->calls[] = 'schema:'.$schema;
				$this->schema  = $schema;
			}//end setSchema()

			/**
			 * Mimic OR getObjectUses().
			 *
			 * @param string $objectId Object UUID.
			 *
			 * @return array<string, mixed>
			 */
			public function getObjectUses(string $objectId): array {
				return $this->answer();
			}//end getObjectUses()

			/**
			 * Mimic OR getObjectUsedBy().
			 *
			 * @param string $objectId Object UUID.
			 *
			 * @return array<string, mixed>
			 */
			public function getObjectUsedBy(string $objectId): array {
				return $this->answer();
			}//end getObjectUsedBy()

			/**
			 * Rows only under the case register and schema, as OR's magic tables.
			 *
			 * @return array<string, mixed>
			 */
			private function answer(): array {
				if ($this->register !== 'dossiq' || $this->schema !== 'case') {
					return ['results' => [], 'total' => 0];
				}

				return [
					'results' => [['id' => 'far', 'relation' => ['property' => 'blockingCases', 'type' => 'waitsOn']]],
					'total'   => 1,
				];
			}//end answer()
		};
	}//end objectService()

	/**
	 * The store over a settings service naming register dossiq and schema case.
	 *
	 * @param object $objectService The object service.
	 *
	 * @return CaseRelationStore The store.
	 */
	private function store(object $objectService): CaseRelationStore {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		return new CaseRelationStore(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end store()

	/**
	 * Both directions are asked under the case register and schema, register first.
	 *
	 * @return void
	 */
	public function testAsksUnderTheCaseSchemaWhateverWasSetBefore(): void {
		$objectService = $this->objectService();
		$store         = $this->store(objectService: $objectService);

		foreach ([false, true] as $incoming) {
			$rows = $store->relationRows(caseUuid: 'case-1', incoming: $incoming);
			$this->assertSame(expected: 'blockingCases', actual: ($rows[0]['relation']['property'] ?? null));
		}

		$this->assertSame(expected: 'register:dossiq', actual: ($objectService->calls[0] ?? null));
		$this->assertSame(expected: 'schema:case', actual: ($objectService->calls[1] ?? null));
	}//end testAsksUnderTheCaseSchemaWhateverWasSetBefore()
}//end class
