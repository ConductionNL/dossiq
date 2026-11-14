<?php

/**
 * A digest is written as one workDigest object in the configured register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\DigestDispatcher;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\DigestDispatcher
 */
class DigestDispatcherTest extends TestCase {
	/**
	 * The digest the composer hands over.
	 *
	 * @var array<string, mixed>
	 */
	private const DIGEST = [
		'person' => 'alice',
		'day' => '2026-03-02',
		'waiting' => '3',
		'late' => 1,
		'named' => [['title' => 'Permit'], ['title' => 'Objection']],
	];

	/**
	 * An object service double that records saves and answers the given result.
	 *
	 * @param mixed $answer What saveObject returns.
	 *
	 * @return object The double; `saves` lists the calls.
	 */
	private function objectService(mixed $answer): object {
		return new class($answer) {
			/**
			 * The recorded saves.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer What saveObject returns.
			 */
			public function __construct(private readonly mixed $answer) {
			}//end __construct()

			/**
			 * Record one save.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): mixed {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];

				return $this->answer;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * A dispatcher over settings with the given object service and register.
	 *
	 * @param object|null $objects  The object service.
	 * @param string      $register The configured register.
	 *
	 * @return DigestDispatcher
	 */
	private function dispatcher(?object $objects, string $register = ' 5 '): DigestDispatcher {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->with('register')->willReturn($register);

		return new DigestDispatcher(settings: $settings);
	}//end dispatcher()

	/**
	 * The digest is saved with typed counts and a title summary, and the
	 * @self id of the saved object is returned.
	 *
	 * @return void
	 */
	public function testTheDigestIsSavedAndItsIdReturned(): void {
		$objects = $this->objectService(['@self' => ['id' => 'digest-1'], 'id' => 'other']);

		$this->assertSame('digest-1', $this->dispatcher($objects)->send(digest: self::DIGEST));
		$this->assertSame(
			[[
				'object' => ['person' => 'alice', 'day' => '2026-03-02', 'waiting' => 3, 'late' => 1, 'summary' => 'Permit, Objection'],
				'register' => '5',
				'schema' => 'workDigest',
				'uuid' => null,
			]],
			$objects->saves
		);
	}//end testTheDigestIsSavedAndItsIdReturned()

	/**
	 * Without an @self id the top-level id is returned; a serialisable entity is read too.
	 *
	 * @return void
	 */
	public function testTheIdFallsBackToTheTopLevelId(): void {
		$this->assertSame('digest-2', $this->dispatcher($this->objectService(['@self' => ['id' => ' '], 'id' => 'digest-2']))->send(self::DIGEST));

		$entity = new class implements \JsonSerializable {
			/**
			 * The serialised entity.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'digest-3'];
			}//end jsonSerialize()
		};
		$this->assertSame('digest-3', $this->dispatcher($this->objectService($entity))->send(['person' => 'a', 'day' => 'd', 'waiting' => 0, 'late' => 0]));
	}//end testTheIdFallsBackToTheTopLevelId()

	/**
	 * A missing object service or register refuses before anything is saved,
	 * and a save that returns nothing is an error.
	 *
	 * @return void
	 */
	public function testMissingConfigurationAndAFailedSaveThrow(): void {
		foreach ([[null, '5'], [$this->objectService(['id' => 'x']), '  ']] as [$objects, $register]) {
			try {
				$this->dispatcher($objects, $register)->send(self::DIGEST);
				$this->fail('a digest was written without a configured register');
			} catch (RuntimeException $e) {
				$this->assertSame('Dossiq cannot write a digest without a configured register.', $e->getMessage());
			}
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The digest was not written.');
		$this->dispatcher($this->objectService(null))->send(self::DIGEST);
	}//end testMissingConfigurationAndAFailedSaveThrow()
}//end class
