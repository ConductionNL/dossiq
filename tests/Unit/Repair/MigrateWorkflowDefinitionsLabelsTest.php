<?php

/**
 * The basis workflow template reads its labels as text, and heals "Array".
 *
 * Found live on review-check, 11 October 2026 (N-2): every case type's basis
 * template was titled "Array — basis", and every move in the lifecycle
 * dialog was a button labelled "Array". The repair step cast translatable
 * titles and status names, which OpenRegister hands back as language maps.
 * These tests drive the real repair step over a store double.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateWorkflowDefinitions;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the backfill writes, and what it heals.
 *
 * @covers \OCA\Dossiq\Repair\MigrateWorkflowDefinitions
 * @covers \OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Workflow\BackfilledTemplateShape
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 */
class MigrateWorkflowDefinitionsLabelsTest extends TestCase {

	/**
	 * Every write the step made, as [method, payload].
	 *
	 * @var \ArrayObject<int, array{0: string, 1: string, 2: array<string, mixed>}>|null
	 */
	private ?\ArrayObject $writes = null;

	/**
	 * A case type stored with no basis template gets one named in text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function testANewBasisTemplateIsNamedInText(): void {
		$this->runStep(caseType: self::caseType(), versions: []);

		$template = $this->written(method: 'save', schema: '53')[0] ?? [];
		self::assertSame('Woo-verzoek (basis)', $template['title'] ?? null);
		self::assertSame(['Ontvangen', 'In behandeling'], array_column(json_decode((string)$template['steps'], true), 'title'));
		self::assertSame(['In behandeling', 'Afgehandeld'], array_column(json_decode((string)$template['transitions'], true), 'label'));
	}//end testANewBasisTemplateIsNamedInText()

	/**
	 * A basis template stored with "Array" labels is healed in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function testABasisTemplateStoredWithArrayLabelsIsHealed(): void {
		$this->runStep(caseType: self::caseType(pinned: true), versions: [self::brokenTemplate()]);

		$patches = $this->written(method: 'patch', schema: '53');
		self::assertCount(1, $patches, 'One partial write per broken template.');
		$patch = $patches[0];
		self::assertSame('Woo-verzoek (basis)', $patch['title']);
		$steps = json_decode((string)$patch['steps'], true);
		self::assertSame('Ontvangen', $steps[0]['title']);
		self::assertSame('De aanvraag is binnen', $steps[0]['description']);
		self::assertSame('step-1', $steps[0]['id'], 'The step keeps its id, so nothing that points at it breaks.');
		$moves = json_decode((string)$patch['transitions'], true);
		self::assertSame('In behandeling', $moves[0]['label']);
		self::assertSame('move-1', $moves[0]['id']);
		self::assertSame([], $this->written(method: 'save', schema: '53'), 'No second template is made.');
	}//end testABasisTemplateStoredWithArrayLabelsIsHealed()

	/**
	 * A template the administrator wrote is not touched, even with "Array" in it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function testATemplateTheBackfillDidNotWriteIsLeftAlone(): void {
		$template = array_merge(self::brokenTemplate(), ['description' => 'Mijn eigen flow']);
		$this->runStep(caseType: self::caseType(pinned: true), versions: [$template]);

		self::assertSame([], $this->written(method: 'patch', schema: '53'));
	}//end testATemplateTheBackfillDidNotWriteIsLeftAlone()

	/**
	 * A case type whose only definition is a draft still gets its basis template.
	 *
	 * A package may ship a flow as a draft for an administrator to finish. A
	 * draft is offered to nobody, so a case type holding nothing else has no
	 * flow a case can follow until the backfill writes one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function testACaseTypeHoldingOnlyADraftStillGetsItsBasisTemplate(): void {
		$this->runStep(caseType: self::caseType(), versions: [self::template(lifecycle: 'draft')]);

		$saved = $this->written(method: 'save', schema: '53');
		self::assertCount(1, $saved, 'The basis template is written beside the draft.');
		self::assertSame('Woo-verzoek (basis)', $saved[0]['title'] ?? null);
		self::assertSame('published', $saved[0]['lifecycleStatus'] ?? null);
	}//end testACaseTypeHoldingOnlyADraftStillGetsItsBasisTemplate()

	/**
	 * A case type that holds a published or a deprecated definition gets no basis template.
	 *
	 * Deprecating the last definition is something an administrator did, so
	 * the backfill does not answer it with a new one.
	 *
	 * @param string $lifecycle The lifecycle of the definition the case type holds.
	 *
	 * @return void
	 *
	 * @dataProvider lifecyclesThatAreNotADraft
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function testACaseTypeHoldingMoreThanDraftsIsLeftAlone(string $lifecycle): void {
		$this->runStep(
			caseType: self::caseType(),
			versions: [self::template(lifecycle: 'draft'), self::template(lifecycle: $lifecycle)]
		);

		self::assertSame([], $this->written(method: 'save', schema: '53'));
	}//end testACaseTypeHoldingMoreThanDraftsIsLeftAlone()

	/**
	 * The lifecycles that are not a draft.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function lifecyclesThatAreNotADraft(): array {
		return ['published' => ['published'], 'deprecated' => ['deprecated']];
	}//end lifecyclesThatAreNotADraft()

	/**
	 * Run the real step over a store double.
	 *
	 * @param array<string, mixed>             $caseType The one case type.
	 * @param array<int, array<string, mixed>> $versions Its stored templates.
	 *
	 * @return void
	 */
	private function runStep(array $caseType, array $versions): void {
		$rows = [
			'28' => [$caseType],
			'29' => [
				['id' => 's1', 'caseType' => 'ct-woo', 'order' => 1, 'name' => ['nl' => 'Ontvangen', 'en' => 'Received'], 'description' => ['nl' => 'De aanvraag is binnen']],
				['id' => 's2', 'caseType' => 'ct-woo', 'order' => 2, 'name' => ['nl' => 'In behandeling', 'en' => 'In progress']],
				['id' => 's3', 'caseType' => 'ct-woo', 'order' => 3, 'name' => ['nl' => 'Afgehandeld'], 'isFinal' => true],
			],
			'35' => [],
		];

		$writes = new \ArrayObject();
		$this->writes = $writes;
		$objectService = new class($rows, $writes) {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rows   Rows by schema.
			 * @param \ArrayObject<int, mixed>                        $writes Where each write lands.
			 */
			public function __construct(private array $rows, private \ArrayObject $writes) {
			}//end __construct()

			/**
			 * Search one schema, by `caseType` when the query names one.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$schema = (string)($query['@self']['schema'] ?? '');
				$caseType = ($query['caseType'] ?? null);

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => $caseType === null || ($row['caseType'] ?? null) === $caseType
					)
				);
			}//end searchObjects()

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object   The payload.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The payload, with an id.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->writes->append(['save', $schema, $object]);
				return array_merge($object, ['id' => ($uuid ?? 'new-template')]);
			}//end saveObject()

			/**
			 * Record a partial write.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The fields.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The fields.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$this->writes->append(['patch', $schema, $data]);
				return $data;
			}//end patchObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => [
				'register' => '20',
				'case_type_schema' => '28',
				'status_type_schema' => '29',
				'workflow_template_schema' => '53',
				'case_schema' => '35',
			][$key] ?? ''
		);

		$workflow = $this->createMock(WorkflowDefinitionService::class);
		$workflow->method('listVersions')->willReturn($versions);

		$step = new MigrateWorkflowDefinitions(
			settingsService: $settings,
			workflowService: $workflow,
			logger: $this->createMock(LoggerInterface::class),
			lifecycle: new WorkflowLifecycleGuard(
				$this->createMock(WorkflowDefinitionRepository::class),
				$this->createMock(LoggerInterface::class),
			),
		);
		$step->run($this->createMock(IOutput::class));
	}//end runStep()

	/**
	 * The payloads written to one schema by one method.
	 *
	 * @param string $method 'save' or 'patch'.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The payloads.
	 */
	private function written(string $method, string $schema): array {
		$payloads = [];
		foreach (($this->writes === null ? [] : $this->writes->getArrayCopy()) as [$kind, $target, $payload]) {
			if ($kind === $method && $target === $schema) {
				$payloads[] = $payload;
			}
		}

		return $payloads;
	}//end written()

	/**
	 * The Woo case type, its title stored as a language map.
	 *
	 * @param bool $pinned Whether it already names a template.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private static function caseType(bool $pinned = false): array {
		$caseType = ['id' => 'ct-woo', 'title' => ['nl' => 'Woo-verzoek', 'en' => 'FOI request']];
		if ($pinned === true) {
			$caseType['workflowDefinition'] = 'tpl-1';
		}

		return $caseType;
	}//end caseType()

	/**
	 * A definition somebody else wrote, in the given lifecycle.
	 *
	 * @param string $lifecycle draft, published or deprecated.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function template(string $lifecycle): array {
		return [
			'id' => 'tpl-' . $lifecycle,
			'title' => 'Eigen flow',
			'caseType' => 'ct-woo',
			'lifecycleStatus' => $lifecycle,
			'isDraft' => ($lifecycle === 'draft'),
			'isActive' => ($lifecycle === 'published'),
			'steps' => '[]',
			'transitions' => '[]',
		];
	}//end template()

	/**
	 * A basis template as the old backfill stored it.
	 *
	 * @return array<string, mixed> The template.
	 */
	private static function brokenTemplate(): array {
		return [
			'id' => 'tpl-1',
			'title' => 'Array — basis',
			'description' => BackfilledTemplateLabels::MARKER,
			'caseType' => 'ct-woo',
			'steps' => json_encode([['id' => 'step-1', 'title' => 'Array', 'description' => 'Array', 'status' => 's1']]),
			'transitions' => json_encode([['id' => 'move-1', 'fromStatus' => 's1', 'toStatus' => 's2', 'label' => 'Array']]),
		];
	}//end brokenTemplate()
}//end class
