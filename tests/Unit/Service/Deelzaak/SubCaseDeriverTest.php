<?php

/**
 * SubCaseDeriver Unit Tests
 *
 * A sub-case is derived, not saved beside its parent. These cover what that
 * buys: the child takes the access-bearing values the case schema declares it
 * takes, it does not take one it already carries, what it took is recorded,
 * and an OpenRegister that cannot be reached is reported rather than passed off
 * as a derive that inherited nothing.
 *
 * The fakes below carry the SIGNATURES of the OpenRegister methods they stand
 * in for, copied from `ObjectRelationService` and `ObjectServiceInterface` on
 * openregister `development` (openregister#3764). A double that invents a
 * method the real class lacks can only ever pass, so nothing here is invented:
 * `declarationFor`, `applyInheritance` and `recordDerivation` are real, and
 * `applyInheritance` implements the rule OpenRegister implements, so a change
 * to that rule shows up here as a disagreement rather than as silence.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Deelzaak
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Deelzaak;

use OCA\Dossiq\Service\Deelzaak\SubCaseDeriver;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SubCaseDeriver.
 *
 * @covers \OCA\Dossiq\Service\Deelzaak\SubCaseDeriver
 */
class SubCaseDeriverTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The stand-in for OpenRegister's relation service, or null.
	 *
	 * @var object|null
	 */
	private ?object $relations = null;

	/**
	 * What the last saveObject() was handed.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);
	}//end setUp()

	/**
	 * The parent case, as an entity with the two accessors the derive uses.
	 *
	 * @param array<string, mixed> $object The parent's data.
	 *
	 * @return object The entity.
	 */
	private function parent(array $object): object {
		return new class($object) {
			/**
			 * @param array<string, mixed> $object The parent's data.
			 */
			public function __construct(private array $object) {
			}//end __construct()

			/**
			 * The parent's data, as OpenRegister's ObjectEntity answers it.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->object;
			}//end getObject()

			/**
			 * The schema holding the property that names the link.
			 *
			 * @return int
			 */
			public function getSchema(): int {
				return 7;
			}//end getSchema()
		};
	}//end parent()

	/**
	 * Wire the deriver to a parent and an optional relation service.
	 *
	 * @param array<string, mixed> $parent The parent's data.
	 * @param array<string, string> $inherits What parentCase declares.
	 * @param bool $withRelations Whether OpenRegister's relation service answers.
	 *
	 * @return SubCaseDeriver The deriver.
	 */
	private function makeDeriver(array $parent, array $inherits, bool $withRelations = true): SubCaseDeriver {
		$entity = $this->parent($parent);
		$saved  = &$this->saved;

		$this->settingsService->method('getObjectService')->willReturn(
			new class($entity, $saved) {
				/**
				 * @param object $entity The parent entity.
				 * @param array<string, mixed> $saved What was saved, by reference.
				 */
				public function __construct(private object $entity, private array &$saved) {
				}//end __construct()

				/**
				 * Mimic OR find().
				 *
				 * @param string $id Object UUID.
				 * @param mixed $register Ignored.
				 * @param mixed $schema Ignored.
				 *
				 * @return object|null
				 */
				public function find(string $id, $register = null, $schema = null): ?object {
					return ($id === 'parent') ? $this->entity : null;
				}//end find()

				/**
				 * Mimic OR saveObject().
				 *
				 * @param array<string, mixed> $object Object to persist.
				 * @param mixed $register Ignored.
				 * @param mixed $schema Ignored.
				 *
				 * @return object
				 */
				public function saveObject(array $object, $register = null, $schema = null): object {
					$this->saved = $object;

					return new class($object) {
						/**
						 * @param array<string, mixed> $object The saved object.
						 */
						public function __construct(private array $object) {
						}//end __construct()

						/**
						 * The saved object.
						 *
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->object;
						}//end jsonSerialize()
					};
				}//end saveObject()
			}
		);

		if ($withRelations === true) {
			$this->relations = new class($inherits) {
				/**
				 * What recordDerivation() was handed.
				 *
				 * @var array<string, mixed>|null
				 */
				public ?array $recorded = null;

				/**
				 * @param array<string, string> $inherits Role to property name.
				 */
				public function __construct(private array $inherits) {
				}//end __construct()

				/**
				 * Mimic ObjectRelationService::declarationFor().
				 *
				 * @param int|null $schemaId The schema holding the property.
				 * @param string $property The property name.
				 * @param string $language The BCP-47 tag.
				 *
				 * @return array<string, mixed>|null
				 */
				public function declarationFor(?int $schemaId, string $property, string $language = 'nl'): ?array {
					if ($schemaId !== 7 || $property !== SubCaseDeriver::PARENT_PROPERTY) {
						return null;
					}

					return ['type' => 'deelzaak', 'inherits' => $this->inherits];
				}//end declarationFor()

				/**
				 * Mimic ObjectRelationService::applyInheritance(), rule for rule.
				 *
				 * @param object $parent The parent object.
				 * @param array<string, mixed> $childData The child so far.
				 * @param array<string, string> $inherits Role to property name.
				 *
				 * @return array{data: array<string, mixed>, inherited: array<string, mixed>}
				 */
				public function applyInheritance(object $parent, array $childData, array $inherits): array {
					$parentData = $parent->getObject();
					$record = [];
					foreach ($inherits as $role => $property) {
						$value = ($parentData[$property] ?? null);
						if ($value === null || $value === '') {
							continue;
						}

						// A value the child already carries is the child's own.
						$own = ($childData[$property] ?? null);
						if ($own !== null && $own !== '') {
							continue;
						}

						$childData[$property] = $value;
						$record[$role] = ['property' => $property, 'value' => $value];
					}

					return ['data' => $childData, 'inherited' => $record];
				}//end applyInheritance()

				/**
				 * Mimic ObjectRelationService::recordDerivation().
				 *
				 * @param object $parent The parent object.
				 * @param object $child The created child.
				 * @param string|null $relationType The vocabulary key.
				 * @param array<string, mixed> $inherited What the child took.
				 * @param string|null $entry The entry it came out of.
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
					$this->recorded = ['type' => $relationType, 'inherited' => $inherited];

					return $child;
				}//end recordDerivation()
			};
		} else {
			$this->relations = null;
		}//end if

		$this->settingsService->method('getOpenRegisterClass')->willReturn($this->relations);

		return new SubCaseDeriver(
			settingsService: $this->settingsService,
			logger: $this->logger,
		);
	}//end makeDeriver()

	/**
	 * A sub-case starts with the parent's confidentiality and handler, and the
	 * derivation records that it took them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function testTheChildTakesTheParentsAccessValuesAndItIsRecorded(): void {
		$deriver = $this->makeDeriver(
			parent: ['confidentiality' => 'zaakvertrouwelijk', 'assignee' => 'hbakker'],
			inherits: ['confidentiality' => 'confidentiality', 'responsible' => 'assignee'],
		);

		$result = $deriver->derive(parentCaseUuid: 'parent', childData: ['title' => 'Deelzaak']);

		$this->assertTrue($result['ok']);
		$this->assertTrue($result['inheritanceApplied']);
		$this->assertSame('zaakvertrouwelijk', $this->saved['confidentiality']);
		$this->assertSame('hbakker', $this->saved['assignee']);
		$this->assertSame('parent', $this->saved[SubCaseDeriver::PARENT_PROPERTY]);
		$this->assertSame('deelzaak', $this->relations->recorded['type']);
		$this->assertSame(
			'zaakvertrouwelijk',
			$this->relations->recorded['inherited']['confidentiality']['value']
		);
	}//end testTheChildTakesTheParentsAccessValuesAndItIsRecorded()

	/**
	 * A value the child already carries is the child's own.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function testAValueTheChildAlreadyCarriesIsNotOverwritten(): void {
		$deriver = $this->makeDeriver(
			parent: ['confidentiality' => 'zaakvertrouwelijk'],
			inherits: ['confidentiality' => 'confidentiality'],
		);

		$result = $deriver->derive(
			parentCaseUuid: 'parent',
			childData: ['title' => 'Deelzaak', 'confidentiality' => 'geheim']
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('geheim', $this->saved['confidentiality']);
		$this->assertSame([], $this->relations->recorded['inherited']);
	}//end testAValueTheChildAlreadyCarriesIsNotOverwritten()

	/**
	 * Only a value the parent actually carries is inherited.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function testAnAbsentParentValueIsNotInherited(): void {
		$deriver = $this->makeDeriver(
			parent: ['confidentiality' => 'intern'],
			inherits: ['confidentiality' => 'confidentiality', 'responsible' => 'assignee'],
		);

		$deriver->derive(parentCaseUuid: 'parent', childData: ['title' => 'Deelzaak']);

		$this->assertSame('intern', $this->saved['confidentiality']);
		$this->assertArrayNotHasKey('assignee', $this->saved);
		$this->assertArrayNotHasKey('responsible', $this->relations->recorded['inherited']);
	}//end testAnAbsentParentValueIsNotInherited()

	/**
	 * An OpenRegister that cannot answer is reported, not passed off as a
	 * derive that happened to inherit nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function testAnUnreachableRelationServiceIsReported(): void {
		$deriver = $this->makeDeriver(
			parent: ['confidentiality' => 'zaakvertrouwelijk'],
			inherits: ['confidentiality' => 'confidentiality'],
			withRelations: false,
		);

		$result = $deriver->derive(parentCaseUuid: 'parent', childData: ['title' => 'Deelzaak']);

		$this->assertTrue($result['ok']);
		$this->assertFalse($result['inheritanceApplied']);
		$this->assertSame([], $result['inherited']);
		$this->assertArrayNotHasKey('confidentiality', $this->saved);
		$this->assertSame('parent', $this->saved[SubCaseDeriver::PARENT_PROPERTY]);
	}//end testAnUnreachableRelationServiceIsReported()

	/**
	 * A parent that cannot be read derives nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function testAnUnreadableParentRefuses(): void {
		$deriver = $this->makeDeriver(parent: [], inherits: []);

		$result = $deriver->derive(parentCaseUuid: 'missing', childData: ['title' => 'Deelzaak']);

		$this->assertFalse($result['ok']);
		$this->assertSame('parent_not_found', $result['reason']);
		$this->assertSame([], $this->saved);
	}//end testAnUnreadableParentRefuses()
}//end class
