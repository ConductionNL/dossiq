<?php

/**
 * CaseTypeCopyService golden test
 *
 * Runs copy(), copyReport() and newVersion() over a set of object-service
 * behaviours (a clean store, failing reads and writes, wrapped and malformed
 * list answers) and compares every object-service call, every log line and
 * every answer with a recorded golden file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/zaaktype-copy/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload;
use OCA\Dossiq\Service\CaseType\DerivedCaseTypeReferences;
use OCA\Dossiq\Service\CaseTypeCopyService;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden test for CaseTypeCopyService.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\CaseTypeCopyService
 *
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeChildCopy
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeCopyTarget
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeDerivation
 * @uses \OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\CaseType\DerivedCaseTypeReferences
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Cases\CaseMergeStore
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseTypeCopyServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/case-type-copy.json';

	/**
	 * The behaviours each gesture runs against.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function behaviours(): array {
		return [
			'clean store' => [],
			'no object service' => ['noObjectService' => true],
			'no register' => ['config' => ['register' => '']],
			'no case type schema' => ['config' => ['case_type_schema' => '']],
			'no role type schema' => ['config' => ['role_type_schema' => '']],
			'no workflow schema' => ['config' => ['workflow_template_schema' => '']],
			'source missing' => ['source' => 'ct-404'],
			'empty source id' => ['source' => ''],
			'find throws' => ['findThrows' => true],
			'case type write fails' => ['saveThrows' => ['caseType']],
			'created without id' => ['createdWithoutId' => true],
			'one child write fails' => ['saveThrows' => ['documentType', 'workflowTemplate']],
			'a child list fails' => ['findAllThrows' => ['resultType']],
			'wrapped list answer' => ['wrapResults' => true],
			'malformed list answer' => ['malformedResults' => ['roleType']],
			'a status copy without id' => ['childWithoutId' => ['statusType']],
		];
	}//end behaviours()

	/**
	 * Every gesture over every behaviour matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryGestureMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach (self::behaviours() as $name => $behaviour) {
			foreach (['copy', 'copyReport', 'newVersion'] as $gesture) {
				$actual[$name . ' / ' . $gesture] = $this->runGesture(behaviour: $behaviour, gesture: $gesture);
			}
		}

		// A duplicate's identifier carries a random suffix.
		$json = preg_replace('/-copy-[0-9a-f]{8}/', '-copy-<random>', json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) . "\n";
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('Recorded the golden file; run again without GOLDEN_RECORD to compare.');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($json, true));
	}//end testEveryGestureMatchesTheGoldenRecord()

	/**
	 * Run one gesture against one behaviour and record what happened.
	 *
	 * @param array<string, mixed> $behaviour How the object service behaves.
	 * @param string               $gesture   The public method to call.
	 *
	 * @return array<string, mixed>
	 */
	private function runGesture(array $behaviour, string $gesture): array {
		$config = array_merge(
			[
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'status_type_schema' => 'statusType',
				'result_type_schema' => 'resultType',
				'role_type_schema' => 'roleType',
				'property_definition_schema' => 'propertyDefinition',
				'document_type_schema' => 'documentType',
				'decision_type_schema' => 'decisionType',
				'workflow_template_schema' => 'workflowTemplate',
			],
			($behaviour['config'] ?? [])
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$objects = new GoldenCopyObjectService(behaviour: $behaviour);
		$settings->method('getObjectService')->willReturn(($behaviour['noObjectService'] ?? false) === true ? null : $objects);

		$logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $lines = [];

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

		$service = new CaseTypeCopyService(
			settingsService: $settings,
			store: new CaseTypeStore($settings),
			payloads: new DerivedCaseTypePayload(),
			references: new DerivedCaseTypeReferences(store: new CaseTypeStore($settings), logger: $logger),
			logger: $logger
		);

		$answer = $service->{$gesture}((string)($behaviour['source'] ?? 'ct-1'));

		return ['answer' => $answer, 'calls' => $objects->calls, 'log' => $logger->lines];
	}//end runGesture()
}//end class

/**
 * An in-memory object service that records every call and fails on request.
 */
class GoldenCopyObjectService {

	/**
	 * Every call, by method and named argument.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Objects by id, with their schema.
	 *
	 * @var array<string, array{schema: string, data: array<string, mixed>}>
	 */
	private array $store = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $behaviour How the service behaves.
	 */
	public function __construct(
		private readonly array $behaviour,
	) {
		$rows = [
			['caseType', ['id' => 'ct-1', 'title' => 'Source type', 'identifier' => 'CT-1', 'isDraft' => false, 'publicationRequired' => true, 'publicationText' => 'x', 'workflowDefinition' => 'wf-1', 'relatedCaseTypes' => ['ct-9'], 'initialStatus' => 'st-1', 'version' => 2, '@self' => ['id' => 'ct-1']]],
			['statusType', ['id' => 'st-1', 'caseType' => 'ct-1', 'name' => 'Received', 'order' => 1]],
			['statusType', ['id' => 'st-2', 'caseType' => 'ct-1', 'name' => ['nl' => 'Besluit', 'en' => 'Decision'], 'order' => 2]],
			['statusType', ['id' => 'st-9', 'caseType' => 'other', 'name' => 'Decoy']],
			['resultType', ['id' => 'rt-1', 'caseType' => 'ct-1', 'name' => 'Granted', '@self' => ['id' => 'rt-1']]],
			['roleType', ['id' => 'ro-1', 'caseType' => 'ct-1', 'name' => 'Handler']],
			['propertyDefinition', ['id' => 'pd-1', 'caseType' => 'ct-1', 'name' => 'size']],
			['documentType', ['id' => 'dt-1', 'caseType' => 'ct-1', 'title' => 'Drawing']],
			['documentType', ['id' => '', 'caseType' => 'ct-1']],
			['decisionType', ['id' => 'de-1', 'caseType' => 'ct-1', 'name' => 'Grant']],
			['workflowTemplate', ['id' => 'wf-1', 'caseType' => 'ct-1', 'title' => 'Route', 'version' => 3]],
		];
		foreach ($rows as $index => [$schema, $data]) {
			$this->store[($data['id'] === '' ? 'blank-' . $index : $data['id'])] = ['schema' => $schema, 'data' => $data];
		}
	}//end __construct()

	/**
	 * Find one object.
	 *
	 * @param string $id       The id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, $register = null, $schema = null): ?array {
		$this->calls[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
		if (($this->behaviour['findThrows'] ?? false) === true) {
			throw new \RuntimeException('find failed');
		}

		$entry = ($this->store[$id] ?? null);
		if ($entry === null || $entry['schema'] !== $schema) {
			return null;
		}

		return $entry['data'];
	}//end find()

	/**
	 * List objects of one schema matching the filters.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return mixed
	 */
	public function findAll(array $config = []) {
		$this->calls[] = ['findAll' => $config];
		$schema = ($config['filters']['schema'] ?? '');
		if (in_array($schema, ($this->behaviour['findAllThrows'] ?? []), true) === true) {
			throw new \RuntimeException('list failed');
		}

		if (in_array($schema, ($this->behaviour['malformedResults'] ?? []), true) === true) {
			return 'not a list';
		}

		$results = [];
		foreach ($this->store as $entry) {
			if ($entry['schema'] === $schema && ($entry['data']['caseType'] ?? null) === ($config['filters']['caseType'] ?? null)) {
				$results[] = $entry['data'];
			}
		}

		if (($this->behaviour['wrapResults'] ?? false) === true) {
			return ['results' => $results, 'total' => count($results)];
		}

		return $results;
	}//end findAll()

	/**
	 * Save an object; one without an id is created.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param mixed                $register The register.
	 * @param mixed                $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, $register = null, $schema = null): array {
		$this->calls[] = ['saveObject' => ['object' => $object, 'register' => $register, 'schema' => $schema]];
		if (in_array($schema, ($this->behaviour['saveThrows'] ?? []), true) === true) {
			throw new \RuntimeException('write failed for ' . $schema);
		}

		$id = (string)($object['id'] ?? ('new-' . count($this->calls)));
		if ($schema === 'caseType' && ($this->behaviour['createdWithoutId'] ?? false) === true) {
			return array_diff_key($object, ['id' => true]);
		}

		if (in_array($schema, ($this->behaviour['childWithoutId'] ?? []), true) === true) {
			return array_diff_key($object, ['id' => true]);
		}

		$object['id'] = $id;
		$this->store[$id] = ['schema' => (string)$schema, 'data' => $object];

		return $object;
	}//end saveObject()
}//end class
