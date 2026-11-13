<?php

/**
 * SubCaseDeriver golden test
 *
 * Pins what deriving a sub-case reads, saves, records, logs and answers,
 * across the configuration, a parent that cannot be read, a relation service
 * that is absent or fails at each step, and the shapes its answers take.
 *
 * The fakes carry the signatures of OpenRegister's ObjectRelationService and
 * object service, and find() throws DoesNotExistException for a missing
 * object, as OpenRegister does.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Deelzaak
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Deelzaak;

use OCA\Dossiq\Service\Deelzaak\SubCaseDeriver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Service\Relation\ParentInheritance;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for SubCaseDeriver::derive().
 *
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseDeriver
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseStore
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseLink
 * @uses   \OCA\Dossiq\Service\Cases\CaseMergeStore
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class SubCaseDeriverGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call a collaborator received, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * A value as the golden records it: an entity by its label.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The recorded form.
	 */
	public static function shown(mixed $value): mixed {
		if (is_object($value) === true) {
			return ($value->label ?? 'object');
		}

		if (is_array($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::shown(value: $item), $value);
		}

		return $value;
	}//end shown()

	/**
	 * An entity with a label, a schema and optionally jsonSerialize().
	 *
	 * @param string               $label  How the golden names it.
	 * @param array<string, mixed> $entity `schema` (a value, or 'throws') and `json` (data, or absent).
	 *
	 * @return object The entity.
	 */
	private static function entity(string $label, array $entity): object {
		if (array_key_exists('json', $entity) === false) {
			return new class($label) {
				/**
				 * Constructor.
				 *
				 * @param string $label How the golden names it.
				 */
				public function __construct(public string $label) {
				}//end __construct()
			};
		}

		return new class($label, $entity) {
			/**
			 * Constructor.
			 *
			 * @param string               $label  How the golden names it.
			 * @param array<string, mixed> $entity The schema and data.
			 */
			public function __construct(public string $label, private array $entity) {
			}//end __construct()

			/**
			 * The schema id, as an ObjectEntity answers it.
			 *
			 * @return mixed The schema id.
			 */
			public function getSchema(): mixed {
				if (($this->entity['schema'] ?? null) === 'throws') {
					throw new RuntimeException('no schema');
				}

				return ($this->entity['schema'] ?? null);
			}//end getSchema()

			/**
			 * The data.
			 *
			 * @return mixed The data.
			 */
			public function jsonSerialize(): mixed {
				return $this->entity['json'];
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * Derive one sub-case against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator.
	 *
	 * @return array<string, mixed> The answer or exception, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$test = $this;
		$logger = new class extends AbstractLogger {
			/**
			 * The lines logged.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

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
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$parent = ($world['parent'] ?? self::entity(label: 'parent-entity', entity: ['schema' => 7, 'json' => ['title' => 'Parent']]));
		$objects = new class($test, $world, $parent) {
			/**
			 * Constructor.
			 *
			 * @param SubCaseDeriverGoldenTest $test   Where calls are recorded.
			 * @param array<string, mixed>     $world  The answers.
			 * @param mixed                    $parent What find() answers for the parent.
			 */
			public function __construct(private SubCaseDeriverGoldenTest $test, private array $world, private mixed $parent) {
			}//end __construct()

			/**
			 * OpenRegister's find(): throws for an object that does not exist.
			 *
			 * @param string|int  $id       The id.
			 * @param mixed       $register The register.
			 * @param mixed       $schema   The schema.
			 *
			 * @return mixed The object.
			 */
			public function find(string|int $id, mixed $register = null, mixed $schema = null): mixed {
				$this->test->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if ($id !== 'parent-1') {
					throw new DoesNotExistException('Object not found');
				}

				return $this->parent;
			}//end find()

			/**
			 * OpenRegister's saveObject().
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return mixed The saved object.
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null): mixed {
				$this->test->calls[] = [
					'saveObject',
					['object' => SubCaseDeriverGoldenTest::shown(value: $object), 'register' => $register, 'schema' => $schema],
				];
				if (($this->world['save'] ?? null) === 'throws') {
					throw new RuntimeException('the store refused it');
				}

				return ($this->world['saved'] ?? $object);
			}//end saveObject()
		};

		$relations = null;
		if (($world['relations'] ?? true) === true) {
			$relations = new class($test, $world) {
				/**
				 * Constructor.
				 *
				 * @param SubCaseDeriverGoldenTest $test  Where calls are recorded.
				 * @param array<string, mixed>     $world The answers.
				 */
				public function __construct(private SubCaseDeriverGoldenTest $test, private array $world) {
				}//end __construct()

				/**
				 * ObjectRelationService::declarationFor().
				 *
				 * @param integer|null $schemaId The schema holding the property.
				 * @param string       $property The property name.
				 * @param string       $language The BCP-47 tag.
				 *
				 * @return array<string, mixed>|null The declaration.
				 */
				public function declarationFor(?int $schemaId, string $property, string $language = 'nl'): ?array {
					$named = ['schemaId' => $schemaId, 'property' => $property];
					if ($language !== 'nl') {
						$named['language'] = $language;
					}

					$this->test->calls[] = ['declarationFor', $named];
					if (($this->world['declaration'] ?? null) === 'throws') {
						throw new RuntimeException('no declaration');
					}

					if (array_key_exists('declaration', $this->world) === true) {
						return $this->world['declaration'];
					}

					return ['type' => 'sub-case', 'inherits' => ['confidentiality' => 'confidentiality']];
				}//end declarationFor()

				/**
				 * ObjectRelationService::inherit().
				 *
				 * @param ParentInheritance $request The parent, the child so far, and role to property name.
				 *
				 * @return array<string, mixed> The data and what was inherited.
				 */
				public function inherit(ParentInheritance $request): array {
					$childData = $request->childData;
					$this->test->calls[] = [
						'inherit',
						SubCaseDeriverGoldenTest::shown(value: ['parent' => $request->parent, 'childData' => $childData, 'inherits' => $request->inherits]),
					];
					if (array_key_exists('applied', $this->world) === true) {
						return $this->world['applied'];
					}

					return [
						'data' => $childData + ['confidentiality' => 'secret'],
						'inherited' => ['confidentiality' => ['property' => 'confidentiality', 'value' => 'secret']],
					];
				}//end inherit()

				/**
				 * ObjectRelationService::recordDerivation().
				 *
				 * @param object               $parent       The parent object.
				 * @param object               $child        The created child.
				 * @param string|null          $relationType The vocabulary key.
				 * @param array<string, mixed> $inherited    What the child took.
				 * @param string|null          $entry        The entry it came out of.
				 *
				 * @return object The row.
				 */
				public function recordDerivation(
					object $parent,
					object $child,
					?string $relationType = null,
					array $inherited = [],
					?string $entry = null,
				): object {
					$this->test->calls[] = [
						'recordDerivation',
						SubCaseDeriverGoldenTest::shown(
							value: array_filter(
								['parent' => $parent, 'child' => $child, 'relationType' => $relationType, 'inherited' => $inherited, 'entry' => $entry],
								static fn (mixed $value, string $name): bool => ($name !== 'entry' || $value !== null),
								ARRAY_FILTER_USE_BOTH
							)
						),
					];
					if (($this->world['record'] ?? null) === 'throws') {
						throw new RuntimeException('no provenance');
					}

					return $child;
				}//end recordDerivation()
			};
		}//end if

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			function () use ($world, $objects): ?object {
				$this->calls[] = ['getObjectService', []];
				if (($world['objectService'] ?? true) === false) {
					return null;
				}

				return $objects;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key, string $default = '') use ($world): string {
				$this->calls[] = ['getConfigValue', ['key' => $key, 'default' => $default]];

				return ($world['config'][$key] ?? ['register' => 'reg-1', 'case_schema' => 'case'][$key] ?? $default);
			}
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			function (string $class) use ($relations): ?object {
				$this->calls[] = ['getOpenRegisterClass', ['class' => $class]];

				return $relations;
			}
		);

		$deriver = new SubCaseDeriver(settingsService: $settings, logger: $logger);
		try {
			$answer = self::shown(
				value: $deriver->derive(
					parentCaseUuid: ($world['parentUuid'] ?? 'parent-1'),
					childData: ($world['child'] ?? ['title' => 'Child'])
				)
			);
		} catch (\Throwable $thrown) {
			$answer = ['thrown' => get_class($thrown)];
		}

		return ['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines];
	}//end observe()

	/**
	 * Every path through derive(), across its configuration and collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenDerive(): void {
		$child = static fn (string $label): object => self::entity(label: $label, entity: ['json' => ['id' => 'child-1', 'title' => 'Child']]);
		$parentOn = static fn (mixed $schema): object => self::entity(label: 'parent-entity', entity: ['schema' => $schema, 'json' => []]);
		$worlds = [
			'derived' => ['saved' => $child('child-entity')],
			'no parent named' => ['parentUuid' => ''],
			'no object service' => ['objectService' => false],
			'no register' => ['config' => ['register' => '']],
			'no case schema' => ['config' => ['case_schema' => '']],
			'parent does not exist' => ['parentUuid' => 'parent-gone'],
			'parent read as an array' => ['parent' => ['id' => 'parent-1']],
			'relation service unavailable' => ['relations' => false, 'saved' => $child('child-entity')],
			'schema id as numeric text' => ['parent' => $parentOn('12'), 'saved' => $child('child-entity')],
			'schema id not numeric' => ['parent' => $parentOn('case'), 'saved' => $child('child-entity')],
			'schema unreadable' => ['parent' => $parentOn('throws'), 'saved' => $child('child-entity')],
			'declaration unreadable' => ['declaration' => 'throws', 'saved' => $child('child-entity')],
			'no declaration' => ['declaration' => null, 'saved' => $child('child-entity')],
			'declaration without type or inherits' => ['declaration' => ['label' => 'x'], 'saved' => $child('child-entity')],
			'declared type not text' => ['declaration' => ['type' => 5, 'inherits' => []], 'saved' => $child('child-entity')],
			'inheritance answers nothing' => ['applied' => [], 'saved' => $child('child-entity')],
			'inheritance answers data only' => ['applied' => ['data' => ['title' => 'Other', 'parentCase' => 'elsewhere']], 'saved' => $child('child-entity')],
			'inherited not a list' => ['applied' => ['data' => ['title' => 'Child'], 'inherited' => 'all'], 'saved' => $child('child-entity')],
			'inherited not a list, no relation service' => ['relations' => false, 'applied' => ['inherited' => 'all']],
			'child names another parent' => ['child' => ['title' => 'Child', 'parentCase' => 'other'], 'saved' => $child('child-entity')],
			'save fails' => ['save' => 'throws'],
			'saved as an array' => [],
			'saved without jsonSerialize' => ['saved' => self::entity(label: 'bare-entity', entity: [])],
			'saved entity serialises to text' => ['saved' => self::entity(label: 'text-entity', entity: ['json' => 'text'])],
			'provenance fails' => ['record' => 'throws', 'saved' => $child('child-entity')],
			'declared type not text, saved as an array' => ['declaration' => ['type' => 5, 'inherits' => []]],
			'inherited not a list, saved as an array' => ['applied' => ['data' => ['title' => 'Child'], 'inherited' => 'all']],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'sub-case-deriver', value: $observed);
	}//end testGoldenDerive()
}//end class
