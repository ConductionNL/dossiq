<?php

/**
 * Unit tests for CaseTypePublishService — validate first, then publish.
 *
 * 🔴 WHAT THESE GUARD IS A HALF-RUN PUBLISH. Publishing writes twice: the case
 * type loses its draft flag and the active workflow template gains a published
 * lifecycle status and the change note. Validating between the two writes
 * would leave a case type published with a draft workflow, and nothing
 * afterwards says which half ran. So the tests that matter are: findings stop
 * EVERYTHING, and a failed first write does not attempt the second.
 *
 * The second thing they guard is the reason this class does not reuse
 * `ZgwZtcRulesService::validatePublish()`: that method asks the store for
 * `statusType where caseType = X`, which is a CHILD type's own rows. A child
 * that inherits its lifecycle would be refused with "give it a status" while
 * its own page showed four.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypePublishService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class CaseTypePublishServiceTest extends TestCase {

	/**
	 * The object-service double the last built service wrote through.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * A publish service over a fixed store.
	 *
	 * @param array<string, array<string, mixed>>            $caseTypes Case types by id.
	 * @param array<string, array<int, array<string, mixed>>> $rows      Rows per schema key.
	 * @param boolean                                        $saveFails Whether writes throw.
	 *
	 * @return CaseTypePublishService The service.
	 */
	private function service(array $caseTypes, array $rows = [], bool $saveFails = false): CaseTypePublishService {
		$this->objectService = new class($caseTypes, $rows, $saveFails) {
			/**
			 * Everything saveObject() was handed, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<string, mixed>> $caseTypes Case types by id.
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 * @param boolean $saveFails Whether writes throw.
			 */
			public function __construct(
				private array $caseTypes,
				private array $rows,
				private bool $saveFails,
			) {
			}

			/**
			 * Read one object by id.
			 *
			 * @param string $id The id.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string $register, string $schema): array {
				return ($this->caseTypes[$id] ?? []);
			}

			/**
			 * Search one schema, filtered on the caseType back-reference.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$schema = (string)($query['@self']['schema'] ?? '');
				$wanted = (string)($query['caseType'] ?? '');

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($wanted): bool {
							$own = (string)($row['caseType'] ?? '');
							if ($wanted === 'IS NULL') {
								return ($own === '');
							}

							return ($own === $wanted);
						}
					)
				);
			}

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if ($this->saveFails === true) {
					throw new RuntimeException('unwritable');
				}

				$this->saved[] = ['schema' => $schema, 'object' => $object];

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : $key)
		);

		$store = new CaseTypeStore($settings);

		return new CaseTypePublishService($settings, new CaseTypeResolver($store), $store, new NullLogger());
	}//end service()

	/**
	 * A draft with a lifecycle a case can enter and leave.
	 *
	 * @param array<string, mixed> $overrides Fields to change on the case type.
	 *
	 * @return CaseTypePublishService The service.
	 */
	private function publishableService(array $overrides = []): CaseTypePublishService {
		$caseType = array_merge(
			['id' => 'ct', 'title' => 'Bezwaar', 'isDraft' => true, 'initialStatus' => 's1'],
			$overrides
		);

		return $this->service(
			['ct' => $caseType],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'order' => 1, 'caseType' => 'ct'],
					['id' => 's2', 'name' => 'Afgehandeld', 'order' => 2, 'isFinal' => true, 'caseType' => 'ct'],
				],
				'workflow_template_schema' => [
					['id' => 'wf', 'title' => 'Flow', 'version' => 3, 'isActive' => true, 'caseType' => 'ct'],
				],
			]
		);
	}//end publishableService()

	/**
	 * A draft that has everything answers no findings.
	 */
	public function testAReadyDraftHasNoFindings(): void {
		self::assertSame([], $this->publishableService()->validate(caseTypeId: 'ct'));
	}//end testAReadyDraftHasNoFindings()

	/**
	 * A case type with no statuses cannot be published.
	 */
	public function testATypeWithNoStatusesIsRefused(): void {
		$service = $this->service(['ct' => ['id' => 'ct', 'title' => 'Bezwaar']]);

		self::assertSame(['Give the case type at least one status.'], $service->validate(caseTypeId: 'ct'));
	}//end testATypeWithNoStatusesIsRefused()

	/**
	 * A lifecycle a case cannot leave is refused.
	 */
	public function testALifecycleWithNoFinalStatusIsRefused(): void {
		$service = $this->service(
			['ct' => ['id' => 'ct', 'title' => 'Bezwaar', 'initialStatus' => 's1']],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct'],
				],
			]
		);

		$findings = $service->validate(caseTypeId: 'ct');

		self::assertCount(1, $findings);
		self::assertStringContainsString('final', $findings[0]);
	}//end testALifecycleWithNoFinalStatusIsRefused()

	/**
	 * A type with no initial status is refused: the spec's own scenario.
	 */
	public function testATypeWithNoInitialStatusIsRefused(): void {
		$findings = $this->publishableService(['initialStatus' => ''])->validate(caseTypeId: 'ct');

		self::assertSame(['Pick the status a new case of this type starts in.'], $findings);
	}//end testATypeWithNoInitialStatusIsRefused()

	/**
	 * 🔴 AN INITIAL STATUS POINTING SOMEWHERE ELSE IS WORSE THAN NONE.
	 *
	 * A new case is filed into a status this type's own lifecycle cannot move
	 * it out of, and every transition then refuses with a from-status mismatch
	 * nobody can trace back to here.
	 */
	public function testAnInitialStatusFromAnotherTypeIsRefused(): void {
		$findings = $this->publishableService(['initialStatus' => 'foreign'])->validate(caseTypeId: 'ct');

		self::assertSame(['Pick the status a new case of this type starts in.'], $findings);
	}//end testAnInitialStatusFromAnotherTypeIsRefused()

	/**
	 * 🔴 A CHILD TYPE VALIDATES ON WHAT IT INHERITS.
	 *
	 * The reason this validation is not ZgwZtcRulesService's: that one asks
	 * for the child's OWN statuses, of which a child that inherits has none.
	 */
	public function testAChildValidatesOnItsParentsStatuses(): void {
		$service = $this->service(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar', 'initialStatus' => 's1'],
				'child' => [
					'id' => 'child',
					'title' => 'Bezwaar (verkort)',
					'parentCaseType' => 'parent',
				],
			],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'order' => 1, 'caseType' => 'parent'],
					['id' => 's2', 'name' => 'Afgehandeld', 'order' => 2, 'isFinal' => true, 'caseType' => 'parent'],
				],
			]
		);

		// The child inherits its parent's initialStatus too, so it resolves.
		self::assertSame([], $service->validate(caseTypeId: 'child'));
	}//end testAChildValidatesOnItsParentsStatuses()

	/**
	 * 🔴 A CYCLE IS REFUSED AT PUBLISH, AND THE FINDING NAMES IT.
	 *
	 * Publishing is the only write dossiq owns: a case type is saved straight
	 * to OpenRegister's object API by the page, with no dossiq code in
	 * between, so "refused on save" can only be met here.
	 */
	public function testALoopingParentChainIsRefusedAtPublish(): void {
		$service = $this->service(
			[
				'a' => ['id' => 'a', 'title' => 'Bezwaar', 'parentCaseType' => 'b', 'initialStatus' => 's1'],
				'b' => ['id' => 'b', 'title' => 'Bezwaar (verkort)', 'parentCaseType' => 'a'],
			],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'a'],
					['id' => 's2', 'name' => 'Klaar', 'isFinal' => true, 'caseType' => 'a'],
				],
			]
		);

		$findings = $service->validate(caseTypeId: 'a');

		self::assertCount(1, $findings);
		self::assertStringContainsString('Bezwaar (verkort)', $findings[0]);
	}//end testALoopingParentChainIsRefusedAtPublish()

	/**
	 * An ordinary parent is not a cycle, and publishes.
	 */
	public function testAnOrdinaryParentIsNotRefused(): void {
		$service = $this->service(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar', 'initialStatus' => 's1'],
				'child' => ['id' => 'child', 'title' => 'Kort', 'parentCaseType' => 'parent'],
			],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'parent'],
					['id' => 's2', 'name' => 'Klaar', 'isFinal' => true, 'caseType' => 'parent'],
				],
			]
		);

		self::assertSame([], $service->validate(caseTypeId: 'child'));
	}//end testAnOrdinaryParentIsNotRefused()

	/**
	 * An unreadable case type says so rather than listing four findings.
	 */
	public function testAnUnreadableTypeAnswersOneFinding(): void {
		self::assertSame(
			['This case type could not be read.'],
			$this->service([])->validate(caseTypeId: 'gone')
		);
	}//end testAnUnreadableTypeAnswersOneFinding()

	/**
	 * 🔴 FINDINGS STOP EVERYTHING. Not one write, not a partial publish.
	 */
	public function testFindingsPreventEveryWrite(): void {
		$service = $this->service(['ct' => ['id' => 'ct', 'title' => 'Bezwaar']]);

		$result = $service->publish(caseTypeId: 'ct', changeNote: 'Eerste versie');

		self::assertFalse($result['published']);
		self::assertNotSame([], $result['findings']);
		self::assertSame([], $this->objectService->saved);
	}//end testFindingsPreventEveryWrite()

	/**
	 * Publishing clears the draft flag on the case type.
	 */
	public function testPublishingClearsTheDraftFlag(): void {
		$result = $this->publishableService()->publish(caseTypeId: 'ct', changeNote: 'Eerste versie');

		self::assertTrue($result['published']);
		$caseType = $this->savedFor(schema: 'case_type_schema');
		self::assertFalse($caseType['isDraft']);
	}//end testPublishingClearsTheDraftFlag()

	/**
	 * Publishing marks the active workflow template published, with the note.
	 */
	public function testPublishingMarksTheTemplateAndWritesTheNote(): void {
		$result = $this->publishableService()->publish(caseTypeId: 'ct', changeNote: 'Eerste versie');

		$template = $this->savedFor(schema: 'workflow_template_schema');

		self::assertSame('published', $template['lifecycleStatus']);
		self::assertFalse($template['isDraft']);
		self::assertSame('Eerste versie', $template['description']);
		self::assertSame(3, $result['version']);
	}//end testPublishingMarksTheTemplateAndWritesTheNote()

	/**
	 * An empty change note leaves the template's existing description alone.
	 */
	public function testAnEmptyNoteDoesNotEraseTheDescription(): void {
		$service = $this->service(
			['ct' => ['id' => 'ct', 'title' => 'Bezwaar', 'initialStatus' => 's1']],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct'],
					['id' => 's2', 'name' => 'Klaar', 'isFinal' => true, 'caseType' => 'ct'],
				],
				'workflow_template_schema' => [
					[
						'id' => 'wf',
						'title' => 'Flow',
						'description' => 'Wat er eerder veranderde',
						'isActive' => true,
						'caseType' => 'ct',
					],
				],
			]
		);

		$service->publish(caseTypeId: 'ct', changeNote: '   ');

		self::assertSame(
			'Wat er eerder veranderde',
			$this->savedFor(schema: 'workflow_template_schema')['description']
		);
	}//end testAnEmptyNoteDoesNotEraseTheDescription()

	/**
	 * A type with no workflow template publishes anyway, with no version.
	 *
	 * Refusing would block every case type that drives its lifecycle from
	 * transitions alone, and those are the majority.
	 */
	public function testATypeWithNoTemplatePublishesWithoutAVersion(): void {
		$service = $this->service(
			['ct' => ['id' => 'ct', 'title' => 'Bezwaar', 'initialStatus' => 's1']],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct'],
					['id' => 's2', 'name' => 'Klaar', 'isFinal' => true, 'caseType' => 'ct'],
				],
			]
		);

		$result = $service->publish(caseTypeId: 'ct', changeNote: 'Eerste versie');

		self::assertTrue($result['published']);
		self::assertNull($result['version']);
	}//end testATypeWithNoTemplatePublishesWithoutAVersion()

	/**
	 * A write that fails is reported as a refusal, not as a publish.
	 */
	public function testAFailedWriteIsNotReportedAsPublished(): void {
		$caseType = ['id' => 'ct', 'title' => 'Bezwaar', 'initialStatus' => 's1'];
		$service = $this->service(
			['ct' => $caseType],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct'],
					['id' => 's2', 'name' => 'Klaar', 'isFinal' => true, 'caseType' => 'ct'],
				],
			],
			true
		);

		$result = $service->publish(caseTypeId: 'ct', changeNote: 'Eerste versie');

		self::assertFalse($result['published']);
		self::assertSame(['The case type could not be saved.'], $result['findings']);
	}//end testAFailedWriteIsNotReportedAsPublished()

	/**
	 * The object last written to one schema.
	 *
	 * @param string $schema The schema key.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function savedFor(string $schema): array {
		foreach (array_reverse($this->objectService->saved) as $write) {
			if ($write['schema'] === $schema) {
				return $write['object'];
			}
		}

		self::fail('nothing was written to ' . $schema);
	}//end savedFor()
}//end class
