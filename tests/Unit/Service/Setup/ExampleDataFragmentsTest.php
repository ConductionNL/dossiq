<?php

/**
 * ExampleDataFragments Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Setup
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

namespace OCA\Dossiq\Tests\Unit\Service\Setup;

use DateTimeImmutable;
use OCA\Dossiq\Service\Setup\ExampleDataFragments;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExampleDataFragments.
 *
 * @covers \OCA\Dossiq\Service\Setup\ExampleDataFragments
 */
class ExampleDataFragmentsTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../../..';

	/**
	 * Build the reader over an app directory, a user and a fixed clock.
	 *
	 * @param string      $appDir The app directory.
	 * @param string|null $uid    The loading user, null for nobody.
	 * @param array<int, string> $groupIds The loading user's groups.
	 * @param SettingsService|null $settings The register, for stored references.
	 *
	 * @return ExampleDataFragments The reader.
	 */
	private function fragments(string $appDir, ?string $uid, array $groupIds = ['admin', 'vergunningen', 'team-woo'], ?SettingsService $settings = null): ExampleDataFragments {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($appDir);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-19T10:00:00+00:00'));

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($groupIds);

		return new ExampleDataFragments(appManager: $apps, userSession: $session, time: $time, groups: $groups, settingsService: $settings);

	}//end fragments()

	/**
	 * A temporary app directory holding one fragment.
	 *
	 * @param array<int, array<string, mixed>> $objects The fragment's objects.
	 *
	 * @return string The directory.
	 */
	private function appWith(array $objects): string {
		$dir = sys_get_temp_dir() . '/dossiq-fragments-' . uniqid();
		mkdir($dir . ExampleDataFragments::DIRECTORY, 0777, true);
		file_put_contents($dir . ExampleDataFragments::DIRECTORY . '/10-test.json', json_encode(['components' => ['objects' => $objects]]));

		return $dir;

	}//end appWith()

	/**
	 * The tokens are filled in for the user and the moment of loading.
	 *
	 * @return void
	 */
	public function testTokensAreFilledInAtLoad(): void {
		$dir = $this->appWith(
			[
				[
					'@self' => ['schema' => 'case', 'slug' => 'a'],
					'assignee' => '@me',
					'startDate' => '@today-3d',
					'deadline' => '@today+2d',
					'receivedAt' => '@now',
					'opened' => '@today',
					'title' => 'Plain @me text stays',
					'nested' => ['by' => '@me'],
				],
			]
		);

		$object = $this->fragments(appDir: $dir, uid: 'pieter')->objects()[0];

		$this->assertSame('pieter', $object['assignee']);
		$this->assertSame('2026-10-16', $object['startDate']);
		$this->assertSame('2026-10-21', $object['deadline']);
		$this->assertSame('2026-10-19T10:00:00+00:00', $object['receivedAt']);
		$this->assertSame('2026-10-19', $object['opened']);
		$this->assertSame('Plain @me text stays', $object['title']);
		$this->assertSame('pieter', $object['nested']['by']);

	}//end testTokensAreFilledInAtLoad()

	/**
	 * With nobody signed in, a `@me` property is left out rather than written empty.
	 *
	 * @return void
	 */
	public function testNobodySignedInLeavesTheAssigneeOut(): void {
		$dir = $this->appWith([['@self' => ['schema' => 'case', 'slug' => 'a'], 'assignee' => '@me', 'title' => 'x']]);

		$object = $this->fragments(appDir: $dir, uid: null)->objects()[0];

		$this->assertArrayNotHasKey('assignee', $object);
		$this->assertSame('x', $object['title']);

	}//end testNobodySignedInLeavesTheAssigneeOut()

	/**
	 * Every shipped fragment object names a schema the register declares,
	 * uses only declared properties, carries the required ones and keeps
	 * enum values inside the enum.
	 *
	 * OpenRegister drops a property its schema does not declare without a
	 * word, so an undeclared key here would be example data that silently
	 * never arrives.
	 *
	 * @return void
	 */
	public function testEveryShippedObjectFitsItsSchema(): void {
		$schemas = [];
		$files = array_merge([self::ROOT . '/lib/Settings/dossiq_register.json'], (glob(self::ROOT . '/lib/Settings/register.d/*.json') ?: []));
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			foreach ((array)($data['components']['schemas'] ?? []) as $slug => $schema) {
				$schemas[$slug]['properties'] = array_merge(
					(array)($schemas[$slug]['properties'] ?? []),
					(array)($schema['properties'] ?? [])
				);
				$schemas[$slug]['required'] = ($schemas[$slug]['required'] ?? (array)($schema['required'] ?? []));
			}
		}

		$objects = $this->fragments(appDir: self::ROOT, uid: 'pieter')->rawObjects();
		$this->assertNotEmpty($objects, 'the work pages need curated example data');

		$problems = [];
		foreach ($objects as $object) {
			$slug = (string)($object['@self']['schema'] ?? '');
			$name = $slug . ':' . (string)($object['@self']['slug'] ?? '');
			if (isset($schemas[$slug]) === false) {
				$problems[] = $name . ' names no declared schema';
				continue;
			}

			foreach ($object as $key => $value) {
				$property = ($schemas[$slug]['properties'][$key] ?? null);
				if ($key === '@self') {
					continue;
				}

				if ($property === null) {
					$problems[] = $name . ' sets undeclared property ' . $key;
					continue;
				}

				if (isset($property['enum']) === true && is_scalar($value) === true && in_array($value, $property['enum'], true) === false) {
					$problems[] = $name . ' sets ' . $key . ' to ' . (string)$value . ', outside its enum';
				}
			}

			foreach ((array)$schemas[$slug]['required'] as $required) {
				if (array_key_exists($required, $object) === false) {
					$problems[] = $name . ' lacks required ' . $required;
				}
			}
		}

		$this->assertSame([], $problems);

	}//end testEveryShippedObjectFitsItsSchema()
	/**
	 * `@myGroup` is the loader's first group other than admin, by id; with none it is left out.
	 *
	 * @return void
	 */
	public function testMyGroupIsTheLoadersFirstTeam(): void {
		$dir = $this->appWith([['@self' => ['schema' => 'case', 'slug' => 'a'], 'assignedGroup' => '@myGroup', 'title' => 'x']]);

		$this->assertSame('team-woo', $this->fragments(appDir: $dir, uid: 'pieter')->objects()[0]['assignedGroup']);
		$this->assertArrayNotHasKey('assignedGroup', $this->fragments(appDir: $dir, uid: 'pieter', groupIds: ['admin'])->objects()[0]);
		$this->assertArrayNotHasKey('assignedGroup', $this->fragments(appDir: $dir, uid: null)->objects()[0]);

	}//end testMyGroupIsTheLoadersFirstTeam()
	/**
	 * A token dropped from a list leaves no gap, so the list stays a JSON array.
	 *
	 * @return void
	 */
	public function testADroppedListElementLeavesNoGap(): void {
		$dir = $this->appWith([['@self' => ['schema' => 'case', 'slug' => 'a'], 'title' => 'x', 'tags' => ['first', '@myGroup', 'last']]]);

		$object = $this->fragments(appDir: $dir, uid: 'pieter', groupIds: ['admin'])->objects()[0];

		$this->assertSame(['first', 'last'], $object['tags']);
		$this->assertSame('["first","last"]', json_encode($object['tags']));

	}//end testADroppedListElementLeavesNoGap()

	/**
	 * A register holding stored objects, keyed by `<schema>:<slug>`.
	 *
	 * @param array<string, string> $stored The stored ids.
	 *
	 * @return SettingsService The settings, with an ObjectService over them.
	 */
	private function registerWith(array $stored): SettingsService {
		$objects = new class($stored) {
			/**
			 * Constructor.
			 *
			 * @param array<string, string> $stored The stored ids.
			 */
			public function __construct(private array $stored) {
			}

			/**
			 * Find a stored object by slug.
			 *
			 * @param string $id       The slug.
			 * @param mixed  $register The register.
			 * @param string $schema   The schema slug.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function find(string $id, mixed $register, string $schema): array {
				if (isset($this->stored[$schema . ':' . $id]) === false) {
					throw new DoesNotExistException('not stored');
				}

				return ['@self' => ['id' => $this->stored[$schema . ':' . $id], 'register' => $register]];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturn('dossiq');

		return $settings;

	}//end registerWith()

	/**
	 * A reference to a stored register object becomes its id; one to another fragment object is left for the importer.
	 *
	 * @return void
	 */
	public function testAReferenceToAStoredObjectBecomesItsId(): void {
		$dir = $this->appWith(
			[
				['@self' => ['schema' => 'case', 'slug' => 'a'], 'caseType' => '@ref:caseType:building-permit-type', 'status' => '@ref:statusType:bp-received'],
				['@self' => ['schema' => 'caseObject', 'slug' => 'b'], 'case' => '@ref:case:a', 'other' => '@ref:caseType:not-stored'],
			]
		);
		$settings = $this->registerWith(['caseType:building-permit-type' => 'uuid-type', 'statusType:bp-received' => 'uuid-status']);

		$objects = $this->fragments(appDir: $dir, uid: 'pieter', settings: $settings)->objects();

		$this->assertSame('uuid-type', $objects[0]['caseType']);
		$this->assertSame('uuid-status', $objects[0]['status']);
		$this->assertSame('@ref:case:a', $objects[1]['case']);
		$this->assertSame('@ref:caseType:not-stored', $objects[1]['other']);

	}//end testAReferenceToAStoredObjectBecomesItsId()

	/**
	 * The English demo cases ship as example data, and every type they name is found in the register.
	 *
	 * @return void
	 */
	public function testTheEnglishDemoCasesResolveTheirTypes(): void {
		$stored = [];
		$data = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/register.d/46-demo-cases-english.json'), true);
		foreach ($data['components']['objects'] as $object) {
			$stored[$object['@self']['schema'] . ':' . $object['@self']['slug']] = 'uuid-' . $object['@self']['slug'];
		}

		$objects = $this->fragments(appDir: self::ROOT, uid: 'pieter', settings: $this->registerWith($stored))->objects();
		$english = array_values(array_filter($objects, static fn (array $object): bool => str_starts_with((string)$object['@self']['slug'], 'case-') && ($object['@self']['schema'] === 'case')));

		$this->assertCount(10, $english);
		foreach ($english as $case) {
			$this->assertStringStartsWith('uuid-', $case['caseType'], $case['@self']['slug']);
			$this->assertStringStartsWith('uuid-', $case['status'], $case['@self']['slug']);
			$this->assertSame('pieter', $case['assignee']);
		}

	}//end testTheEnglishDemoCasesResolveTheirTypes()

	/**
	 * Objects an earlier load created are left out, and the slugs are listed.
	 *
	 * @return void
	 */
	public function testObjectsAnEarlierLoadCreatedAreLeftOut(): void {
		$dir = $this->appWith(
			[
				['@self' => ['schema' => 'case', 'slug' => 'a'], 'title' => 'x'],
				['@self' => ['schema' => 'case', 'slug' => 'b'], 'title' => 'y'],
			]
		);
		$fragments = $this->fragments(appDir: $dir, uid: 'pieter');

		$this->assertSame(['a', 'b'], $fragments->slugs());
		$this->assertSame(['b'], array_map(static fn (array $object): string => $object['@self']['slug'], $fragments->objects(skipSlugs: ['a'])));

	}//end testObjectsAnEarlierLoadCreatedAreLeftOut()
}//end class
