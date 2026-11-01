<?php

/**
 * PackageWriter golden test
 *
 * Runs every public method of the package writer over a set of store and
 * configuration behaviours, and compares every object-service call (by
 * parameter name), every settings read, every log line and every answer with
 * a recorded golden file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * A stored row as an entity answers it.
 */
final class GoldenPackageEntity {
	/**
	 * Constructor.
	 *
	 * @param string $uuid The uuid.
	 */
	public function __construct(private string $uuid) {
	}//end __construct()

	/**
	 * The uuid.
	 *
	 * @return string
	 */
	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()
}//end class

/**
 * A stored row as a serialisable object answers it.
 */
final class GoldenPackageSerialisable {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	public function __construct(private array $row) {
	}//end __construct()

	/**
	 * The row.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}//end jsonSerialize()
}//end class

/**
 * An object service answering from a behaviour and logging every call by parameter name.
 */
final class GoldenPackageObjects {
	/**
	 * How many rows were saved, for generated ids.
	 *
	 * @var integer
	 */
	private int $saves = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $behaviour How the store answers.
	 * @param array<int, mixed>    $log       The shared call log.
	 */
	public function __construct(
		private array $behaviour,
		private array &$log,
	) {
	}//end __construct()

	/**
	 * Find one object.
	 *
	 * @param int|string $id            The id.
	 * @param mixed      $_extend       Ignored.
	 * @param bool       $files         Ignored.
	 * @param int|string $register      The register.
	 * @param int|string $schema        The schema.
	 * @param bool       $_rbac         The access check.
	 * @param bool       $_multitenancy The tenant check.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(
		int|string $id,
		mixed $_extend = null,
		bool $files = false,
		int|string $register = '',
		int|string $schema = '',
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ?array {
		$this->log[] = ['find' => compact('id', 'register', 'schema', '_rbac', '_multitenancy')];
		if (in_array((string)$id, ($this->behaviour['findThrows'] ?? []), true) === true) {
			throw new RuntimeException('find failed for ' . $id);
		}

		return ($this->behaviour['held'][(string)$schema][(string)$id] ?? null);
	}//end find()

	/**
	 * Search by slug: every row of the schema equal on each filter that is not paging.
	 *
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param array<string, mixed> $filters       The filters.
	 * @param bool                 $_rbac         The access check.
	 * @param bool                 $_multitenancy The tenant check.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(
		string $register,
		string $schema,
		array $filters = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->log[] = ['searchObjectsBySlug' => compact('register', 'schema', 'filters', '_rbac', '_multitenancy')];
		$found = [];
		foreach (($this->behaviour['search'][$schema] ?? []) as $row) {
			$equal = true;
			foreach ($filters as $key => $value) {
				if (str_starts_with((string)$key, '_') === false && ($row[$key] ?? null) !== $value) {
					$equal = false;
				}
			}

			if ($equal === true) {
				$found[] = $row;
			}
		}

		return $found;
	}//end searchObjectsBySlug()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object        The fields.
	 * @param int|string           $register      The register.
	 * @param int|string           $schema        The schema.
	 * @param string|null          $uuid          The uuid.
	 * @param bool                 $_rbac         The access check.
	 * @param bool                 $_multitenancy The tenant check.
	 *
	 * @return mixed
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): mixed {
		$this->log[] = ['saveObject' => compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy')];
		$this->saves += 1;
		$id = ($uuid ?? ('new-' . $this->saves));
		if (in_array((string)$schema, ($this->behaviour['saveThrows'] ?? []), true) === true
			|| in_array($id, ($this->behaviour['saveThrows'] ?? []), true) === true
		) {
			throw new RuntimeException('save refused for ' . $id);
		}

		return match ($this->behaviour['answer'][$id] ?? 'array') {
			'entity' => new GoldenPackageEntity(uuid: ' ' . $id . ' '),
			'serialisable' => new GoldenPackageSerialisable(row: ['@self' => ['uuid' => $id]]),
			'self' => ['@self' => ['uuid' => $id]],
			'blankSelf' => ['@self' => ['uuid' => ' '], 'id' => $id . '-plain'],
			'null' => null,
			default => $object + ['id' => $id],
		};
	}//end saveObject()

	/**
	 * Delete one object.
	 *
	 * @param string     $uuid     The uuid.
	 * @param int|string $register The register.
	 *
	 * @return bool
	 */
	public function deleteObject(string $uuid, int|string $register = ''): bool {
		$this->log[] = ['deleteObject' => compact('uuid', 'register')];
		if (in_array($uuid, ($this->behaviour['deleteThrows'] ?? []), true) === true) {
			throw new RuntimeException('delete refused for ' . $uuid);
		}

		return true;
	}//end deleteObject()
}//end class

/**
 * Golden test for PackageWriter.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageWriter
 * @uses \OCA\Dossiq\Service\CaseDefinition\AdoptedCaseTypeFill
 * @uses \OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSeedRules
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PackageWriterGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls each case makes: [method, named arguments, behaviour].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$caseType = ['id' => 'ct-1', 'identifier' => 'melding', 'title' => 'Melding', 'description' => 'Een melding'];
		$statuses = [
			['@self' => ['uuid' => 'st-1', 'register' => 9], 'caseType' => 'ct-1', 'name' => 'Nieuw'],
			'not a row',
			['id' => ' st-2 ', 'caseType' => 'ct-1', 'name' => 'Klaar'],
			['caseType' => 'ct-1', 'name' => 'Zonder id'],
		];
		$component = ['caseType' => $caseType, 'statusTypes' => $statuses, 'roleTypes' => []];
		$all = ['caseType', 'statusTypes', 'roleTypes'];
		$collections = static fn (string $strategy, array $data = []): array => [
			'component' => 'case-type',
			'data' => ($data === [] ? $component : $data),
			'collections' => $all,
			'strategy' => $strategy,
		];
		$heldAll = ['held' => ['caseType' => ['ct-1' => ['id' => 'ct-1']], 'statusType' => ['st-1' => ['id' => 'st-1'], 'st-2' => ['id' => 'st-2']]]];

		$groupCase = ['schemaKey' => 'case_type_schema', 'rows' => [$caseType]];
		$groupStatus = ['schemaKey' => 'status_type_schema', 'rows' => [['id' => 'st-1', 'caseType' => 'ct-1', 'name' => 'Nieuw', 'color' => 'blue'], ['caseType' => 'ct-1', 'name' => 'Klaar']]];
		$groupSeed = ['schemaKey' => 'seed_schema', 'register' => 'other', 'schema' => 'tag', 'rows' => [['id' => 'tag-1', 'label' => 'A', 'enabled' => true]]];
		$groupFlow = ['schemaKey' => 'workflow_template_schema', 'rows' => [['id' => 'wf-1', 'caseType' => 'ct-1', 'steps' => '["ct-1","st-1"]']]];
		$package = static fn (array ...$groups): array => ['package' => 'jeugdwet', 'groups' => $groups];
		$adoptCase = ['search' => ['caseType' => [['id' => 'ct-old', 'identifier' => 'melding', 'title' => 'Melding']]]];
		$adoptStatus = ['search' => ['caseType' => $adoptCase['search']['caseType'], 'statusType' => [['id' => 'st-old', 'caseType' => 'ct-old', 'name' => 'Nieuw']]]];

		$adopted = [
			['schemaKey' => 'case_type_schema', 'schema' => 'caseType', 'register' => 'dossiq', 'id' => 'ct-old', 'row' => ['@self' => ['uuid' => 'ct-old'], 'id' => 'ct-old', 'title' => 'Melding', 'description' => 'Een melding'], 'fill' => ['description']],
			['schemaKey' => 'status_type_schema', 'schema' => 'statusType', 'register' => 'dossiq', 'id' => 'st-old', 'row' => ['id' => 'st-old'], 'fill' => []],
			['schemaKey' => 'case_type_schema', 'schema' => 'caseType', 'register' => 'dossiq', 'id' => 'ct-bad', 'row' => ['title' => 'Bad'], 'fill' => ['title']],
		];
		$written = [
			['schemaKey' => 'seed_schema', 'schema' => 'tag', 'register' => 'other', 'id' => 'tag-1', 'row' => []],
			['schemaKey' => 'case_type_schema', 'schema' => 'caseType', 'id' => 'ct-1', 'row' => []],
			['schemaKey' => 'case_type_schema', 'schema' => 'caseType', 'register' => 'dossiq', 'id' => '', 'row' => []],
		];

		return [
			'collections / no object service' => ['writeCollections', $collections('skip'), ['noObjectService' => true]],
			'collections / no register' => ['writeCollections', $collections('skip'), ['config' => ['register' => '']]],
			'collections / all new' => ['writeCollections', $collections('skip'), []],
			'collections / all held, skip' => ['writeCollections', $collections('skip'), $heldAll],
			'collections / all held, overwrite' => ['writeCollections', $collections('overwrite'), $heldAll],
			'collections / some held, overwrite' => ['writeCollections', $collections('overwrite'), ['held' => ['statusType' => ['st-1' => ['id' => 'st-1']]]]],
			'collections / some held, skip' => ['writeCollections', $collections('skip'), ['held' => ['statusType' => ['st-1' => ['id' => 'st-1']]]]],
			'collections / only held rows, skip' => ['writeCollections', $collections('skip', ['caseType' => $caseType, 'statusTypes' => [$statuses[0]]]), $heldAll],
			'collections / only held rows, overwrite' => ['writeCollections', $collections('overwrite', ['caseType' => $caseType, 'statusTypes' => [$statuses[0]]]), $heldAll],
			'collections / held reads as empty' => ['writeCollections', $collections('skip'), ['held' => ['caseType' => ['ct-1' => []]]]],
			'collections / nothing to write' => ['writeCollections', $collections('skip', ['caseType' => [], 'statusTypes' => 'none']), []],
			'collections / no collections' => ['writeCollections', ['collections' => []] + $collections('skip'), []],
			'collections / a list case type' => ['writeCollections', $collections('skip', ['caseType' => [$caseType, 'x', ['title' => 'Tweede']]]), []],
			'collections / no schema for the second collection' => ['writeCollections', $collections('skip'), ['config' => ['status_type_schema' => '']]],
			'collections / save refused midway' => ['writeCollections', $collections('skip'), ['saveThrows' => ['st-2']]],
			'collections / save refused, roll-back refused' => ['writeCollections', $collections('skip'), ['saveThrows' => ['new-4'], 'deleteThrows' => ['st-1']]],
			'collections / find raises' => ['writeCollections', $collections('overwrite'), ['findThrows' => ['ct-1']] + $heldAll],
			'collections / store answers vary' => ['writeCollections', $collections('overwrite'), ['answer' => ['ct-1' => 'entity', 'st-1' => 'serialisable', 'st-2' => 'self', 'new-4' => 'null']] + ['held' => ['statusType' => ['st-1' => ['id' => 'st-1']]]]],
			'collections / blank self uuid, then refused' => ['writeCollections', $collections('skip'), ['answer' => ['ct-1' => 'blankSelf', 'st-1' => 'null'], 'saveThrows' => ['new-4']]],
			'package / no object service' => ['writePackage', $package($groupCase), ['noObjectService' => true]],
			'package / no register' => ['writePackage', $package($groupCase), ['config' => ['register' => '']]],
			'package / no groups' => ['writePackage', $package(), []],
			'package / all new' => ['writePackage', $package($groupCase, $groupStatus, $groupSeed, $groupFlow), []],
			'package / held rows' => ['writePackage', $package($groupCase, $groupStatus, $groupSeed), ['held' => ['caseType' => ['ct-1' => ['id' => 'ct-1']], 'tag' => ['tag-1' => ['id' => 'tag-1']]]]],
			'package / find raises' => ['writePackage', $package($groupCase), ['findThrows' => ['ct-1']]],
			'package / no schema for a group' => ['writePackage', $package($groupSeed, $groupCase, ['schemaKey' => 'missing_schema', 'rows' => [['id' => 'x']]]), []],
			'package / a group schema of its own, empty' => ['writePackage', $package(['schema' => ''] + $groupSeed), []],
			'package / save refused, undo in each register' => ['writePackage', $package($groupSeed, $groupCase, $groupStatus), ['saveThrows' => ['new-4'], 'deleteThrows' => ['tag-1']]],
			'package / adopts a case type and its children' => ['writePackage', $package($groupCase, $groupStatus, $groupFlow), $adoptStatus],
			'package / adopted case type lacks nothing' => ['writePackage', $package(['schemaKey' => 'case_type_schema', 'rows' => [['id' => 'ct-1', 'identifier' => 'melding', 'title' => 'Melding']]]), $adoptCase],
			'package / adopts without a package id' => ['writePackage', $package(['schemaKey' => 'case_type_schema', 'rows' => [['identifier' => 'melding', 'title' => 'Melding', 'description' => 'D']]], $groupStatus), $adoptStatus],
			'package / two case types match' => ['writePackage', $package($groupCase), ['search' => ['caseType' => [['id' => 'ct-a', 'identifier' => 'melding'], ['id' => 'ct-b', 'identifier' => 'melding']]]]],
			'package / match without an id is written' => ['writePackage', $package($groupCase), ['search' => ['caseType' => [['identifier' => 'melding', 'title' => 'Melding']]]]],
			'package / adopts on its match' => ['writePackage', $package(['match' => ['label', 'enabled']] + $groupSeed), ['search' => ['tag' => [['id' => 'tag-old', 'label' => 'A', 'enabled' => 'true'], ['id' => 'tag-x', 'label' => 'A', 'enabled' => 'false']]]]],
			'package / match finds nothing' => ['writePackage', $package(['match' => ['label']] + $groupSeed), ['search' => ['tag' => [['id' => 'tag-old', 'label' => 'B']]]]],
			'package / adoption then failure' => ['writePackage', $package($groupCase, $groupStatus, $groupFlow), $adoptCase + ['saveThrows' => ['workflowTemplate']]],
			'package / store answers vary' => ['writePackage', $package($groupCase, $groupStatus), ['answer' => ['ct-1' => 'entity', 'st-1' => 'serialisable', 'new-2' => 'null']]],
			'undo / rows in their registers' => ['undo', ['written' => $written], ['deleteThrows' => ['ct-1']]],
			'undo / no object service' => ['undo', ['written' => $written], ['noObjectService' => true]],
			'undo / nothing' => ['undo', ['written' => []], []],
			'fill / adopted rows' => ['fillAdopted', ['adopted' => $adopted], ['saveThrows' => ['ct-bad']]],
			'fill / no object service' => ['fillAdopted', ['adopted' => $adopted], ['noObjectService' => true]],
		];
	}//end cases()

	/**
	 * Every case matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $behaviour]) {
			$log = [];
			$writer = $this->writer(behaviour: $behaviour, log: $log);
			try {
				$answer = $writer->$method(...$args);
			} catch (Throwable $e) {
				$answer = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['answer' => $answer, 'calls' => $log];
		}

		$this->assertMatchesGolden(name: 'package-writer', value: $actual);
	}//end testEveryCaseMatchesTheGoldenRecord()

	/**
	 * The writer over recording doubles.
	 *
	 * @param array<string, mixed> $behaviour How the collaborators answer.
	 * @param array<int, mixed>    $log       The shared call log.
	 *
	 * @return PackageWriter
	 */
	private function writer(array $behaviour, array &$log): PackageWriter {
		$config = array_merge(
			[
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'status_type_schema' => 'statusType',
				'role_type_schema' => 'roleType',
				'workflow_template_schema' => 'workflowTemplate',
			],
			($behaviour['config'] ?? [])
		);
		$objects = new GoldenPackageObjects(behaviour: $behaviour, log: $log);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use ($behaviour, $objects, &$log): ?object {
				$log[] = 'settings getObjectService';
				return (($behaviour['noObjectService'] ?? false) === true) ? null : $objects;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($config, &$log): string {
				$log[] = 'settings getConfigValue ' . $key;
				return ($config[$key] ?? $default);
			}
		);

		$logger = $this->logger(log: $log);

		return new PackageWriter(logger: $logger, settings: $settings, matcher: new ExistingRowMatcher(logger: $logger));
	}//end writer()

	/**
	 * A logger writing into the call log.
	 *
	 * @param array<int, mixed> $log The shared call log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(array &$log): AbstractLogger {
		return new class ($log) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $log The shared call log.
			 */
			public function __construct(private array &$log) {
			}//end __construct()

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log.' . (string)$level => ['message' => (string)$message, 'context' => $context]];
			}//end log()
		};
	}//end logger()
}//end class
