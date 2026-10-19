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
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
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
	 *
	 * @return ExampleDataFragments The reader.
	 */
	private function fragments(string $appDir, ?string $uid): ExampleDataFragments {
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

		return new ExampleDataFragments(appManager: $apps, userSession: $session, time: $time);

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
}//end class
