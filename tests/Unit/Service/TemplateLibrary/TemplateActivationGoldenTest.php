<?php

/**
 * Golden test for activating a case-type template.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\TemplateLibrary
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
 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\TemplateLibrary;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TemplateLibraryService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what activating a template saves, in what order, and what it answers.
 *
 * @covers \OCA\Dossiq\Service\TemplateLibraryService
 * @covers \OCA\Dossiq\Service\TemplateLibrary\TemplateActivation
 * @covers \OCA\Dossiq\Service\TemplateLibrary\TemplateRows
 */
class TemplateActivationGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every save, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every log line, in order.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $logs = [];

	/**
	 * Activate the given templates over an in-memory object store.
	 *
	 * @param array<string, array<string, mixed>|null> $templates Templates by id.
	 * @param bool                                     $store     Whether OpenRegister is there.
	 * @param string                                   $register  The configured register.
	 *
	 * @return TemplateLibraryService The service.
	 */
	private function service(array $templates, bool $store = true, string $register = 'dossiq'): TemplateLibraryService {
		$objects = new class ($this->saves) {
			/**
			 * @param array<int, array<string, mixed>> $saves The save log, written in place.
			 */
			public function __construct(private array &$saves) {
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return object The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): object {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				$uuid = 'uuid-' . count($this->saves);

				return new class ($uuid) {
					/**
					 * @param string $uuid The uuid.
					 */
					public function __construct(private string $uuid) {
					}

					/**
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($store === true ? $objects : null));
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? $register : 'schema:' . $key)
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = [$level, $message];
				}
			);
		}

		return new class ($settings, $logger, $templates) extends TemplateLibraryService {
			/**
			 * @param SettingsService                          $settings  Settings.
			 * @param LoggerInterface                          $logger    Logger.
			 * @param array<string, array<string, mixed>|null> $templates Templates by id.
			 */
			public function __construct(SettingsService $settings, LoggerInterface $logger, private array $templates) {
				parent::__construct(settingsService: $settings, logger: $logger);
			}

			/**
			 * @param string $templateId The template.
			 *
			 * @return array<string, mixed>|null The template.
			 */
			public function loadTemplate(string $templateId): ?array {
				return ($this->templates[$templateId] ?? null);
			}
		};
	}//end service()

	/**
	 * One activation, or the refusal it ends in.
	 *
	 * @param TemplateLibraryService $service    The service.
	 * @param string                 $templateId The template.
	 *
	 * @return array<string, mixed> The answer, the saves and the log lines.
	 */
	private function activate(TemplateLibraryService $service, string $templateId): array {
		$this->saves = [];
		$this->logs = [];
		try {
			$answer = $service->activateTemplate($templateId);
		} catch (\RuntimeException $e) {
			$answer = ['refused' => $e->getMessage()];
		}

		return ['answer' => $answer, 'saves' => $this->saves, 'logs' => $this->logs];
	}//end activate()

	/**
	 * Full, partial and refused activations, pinned whole.
	 *
	 * @return void
	 */
	public function testGoldenActivation(): void {
		$full = [
			'caseType' => ['title' => 'Permit'],
			'statusTypes' => [
				['name' => ' Received ', 'order' => 1],
				['name' => 'Decided', 'order' => 2],
				['order' => 3],
				['name' => 'Decided', 'order' => 4],
			],
			'propertyDefinitions' => [['name' => 'size'], ['name' => 'colour']],
			'documentTypes' => [['name' => 'letter']],
			'decisionTypes' => [['name' => 'grant'], ['name' => 'refuse']],
			'roleTypes' => [['name' => 'applicant']],
			'workflowTemplate' => [
				'title' => 'Flow',
				'transitions' => [
					['label' => 'decide', 'fromStatusName' => 'Received', 'toStatusName' => ' Decided ', 'fourEyes' => true],
					['label' => 'ghost', 'fromStatusName' => 'Received', 'toStatusName' => 'Nowhere'],
					['label' => 'from nowhere', 'fromStatusName' => 'Nowhere', 'toStatusName' => 'Decided'],
					'not an array',
					['fromStatusName' => '', 'toStatusName' => ''],
					['label' => 'back', 'fromStatusName' => 'Decided', 'toStatusName' => 'Received'],
				],
			],
		];
		$templates = [
			'full' => $full,
			'bare' => ['caseType' => []],
			'no transitions resolve' => ['caseType' => ['title' => 'X'], 'statusTypes' => [['name' => 'A']], 'workflowTemplate' => ['transitions' => [['fromStatusName' => 'A', 'toStatusName' => 'B']]]],
			'workflow not an array' => ['caseType' => ['title' => 'Y'], 'workflowTemplate' => 'flow'],
			'workflow without transitions' => ['caseType' => ['title' => 'Z'], 'workflowTemplate' => ['title' => 'empty']],
			'document' => ['body' => 'Dear ...'],
		];

		$service = $this->service(templates: $templates);
		$world = [];
		foreach (array_merge(array_keys($templates), ['missing']) as $id) {
			$world[$id] = $this->activate(service: $service, templateId: $id);
		}

		$world['no store'] = $this->activate(service: $this->service(templates: $templates, store: false), templateId: 'full');
		$world['no register'] = $this->activate(service: $this->service(templates: $templates, register: ''), templateId: 'full');

		$this->assertMatchesGolden('template-activation', $world);
	}//end testGoldenActivation()

	/**
	 * The shipped library lists one entry per template file, and each listed
	 * template loads by its id; an unknown id loads nothing.
	 *
	 * The wording of the shipped templates is not asserted: only that the list
	 * and the loader read the same files the same way.
	 *
	 * @return void
	 */
	public function testTheShippedLibraryListsAndLoadsTheSameTemplates(): void {
		$service = new TemplateLibraryService(settingsService: $this->createMock(SettingsService::class), logger: $this->createMock(LoggerInterface::class));
		$files = glob(dirname(__DIR__, 4) . '/lib/Settings/templates/*.json');

		$listed = $service->listTemplates();

		self::assertCount(count($files), $listed);
		self::assertSame(array_map('basename', $files), array_column($listed, 'file'));
		foreach ($listed as $entry) {
			$loaded = $service->loadTemplate(templateId: (string)$entry['id']);
			self::assertIsArray($loaded, (string)$entry['id']);
			self::assertSame($entry['title'], ($loaded['title'] ?? ''));
		}

		self::assertNull($service->loadTemplate(templateId: 'no-such-template'));
	}//end testTheShippedLibraryListsAndLoadsTheSameTemplates()
}//end class
