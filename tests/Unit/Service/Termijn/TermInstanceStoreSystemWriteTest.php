<?php

/**
 * A term written with nobody signed in is written as the system.
 *
 * Rehearsal R3: the example set install created its cases from occ, with no
 * user, and every one of them logged "TermInstanceStore persist failed:
 * User 'Anonymous' does not have permission to 'create' objects in schema
 * 'Term instance'", so those cases had no term.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Termijn
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Saves a term through an object service that refuses an anonymous create.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class TermInstanceStoreSystemWriteTest extends TestCase {

	/**
	 * An object service that refuses a write outside the system context, as
	 * OpenRegister refuses "Anonymous", and counts the system runs.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return new class {
			/**
			 * Whether an operation runs as the system right now.
			 *
			 * @var bool
			 */
			public bool $inSystem = false;

			/**
			 * How many operations ran as the system.
			 *
			 * @var int
			 */
			public int $systemRuns = 0;

			/**
			 * Run an operation as the system.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;
				$this->inSystem = true;
				try {
					return $operation();
				} finally {
					$this->inSystem = false;
				}
			}

			/**
			 * Save an object; refused outside the system context.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): array {
				if ($this->inSystem === false) {
					throw new RuntimeException("User 'Anonymous' does not have permission to 'create' objects in schema 'Term instance'");
				}

				return $object + ['id' => 'term-1'];
			}
		};
	}//end objectService()

	/**
	 * The store over that service, with this user signed in.
	 *
	 * @param object     $objectService The object service.
	 * @param IUser|null $user          The signed-in user, or null.
	 *
	 * @return TermInstanceStore
	 */
	private function store(object $objectService, ?IUser $user): TermInstanceStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ['register' => 'dossiq', 'termijn_instance_schema' => 'termInstance'][$key] ?? ''
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TermInstanceStore(settingsService: $settings, logger: new NullLogger(), userSession: $session);
	}//end store()

	/**
	 * With nobody signed in, the term is saved as the system.
	 *
	 * @return void
	 */
	public function testATermWrittenByNobodyIsWrittenAsTheSystem(): void {
		$objectService = $this->objectService();

		$saved = $this->store(objectService: $objectService, user: null)->save(schemaConfigKey: 'termijn_instance_schema', object: ['case' => 'case-1']);

		self::assertSame('term-1', $saved['id'] ?? null);
		self::assertSame(1, $objectService->systemRuns);
	}//end testATermWrittenByNobodyIsWrittenAsTheSystem()

	/**
	 * Control: a signed-in user's write is never elevated.
	 *
	 * @return void
	 */
	public function testASignedInUsersTermIsWrittenAsThatUser(): void {
		$objectService = $this->objectService();

		$saved = $this->store(objectService: $objectService, user: $this->createMock(IUser::class))->save(schemaConfigKey: 'termijn_instance_schema', object: ['case' => 'case-1']);

		self::assertNull($saved);
		self::assertSame(0, $objectService->systemRuns);
	}//end testASignedInUsersTermIsWrittenAsThatUser()
}//end class
