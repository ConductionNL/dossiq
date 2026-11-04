<?php

/**
 * Whether the caller may read an object, asked of OpenRegister with the caller's own rights.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An object is readable only when the caller's own read finds it; a failed read throws.
 *
 * @covers \OCA\Dossiq\Service\Access\ReadableObjects
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class ReadableObjectsTest extends TestCase {

	/**
	 * The service over a register.
	 *
	 * @param object $register The object service.
	 * @param bool   $signedIn Whether a caller is signed in.
	 *
	 * @return ReadableObjects
	 */
	private function readable(object $register, bool $signedIn = true): ReadableObjects {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? 'dossiq' : $default)
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true) ? $this->createMock(originalClassName: IUser::class) : null);

		return new ReadableObjects(settingsService: $settings, userSession: $session);
	}//end readable()

	/**
	 * A register with one case, one person and one company.
	 *
	 * @return InMemoryRegister
	 */
	private function register(): InMemoryRegister {
		$register = new InMemoryRegister();
		$register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Kapvergunning']);
		$register->seed(schema: 'brpPerson', uuid: 'p-1', row: ['masterEntityRef' => 'me-person']);
		$register->seed(schema: 'kvkCompany', uuid: 'k-1', row: ['masterEntityRef' => 'me-company']);

		return $register;
	}//end register()

	/**
	 * A stored object is readable; a missing id, an empty id and an unconfigured register are not.
	 *
	 * @return void
	 */
	public function testAStoredObjectIsReadableAndAMissingOneIsNot(): void {
		$readable = $this->readable(register: $this->register());

		self::assertTrue(condition: $readable->findsAsCaller(schema: 'case', id: ' case-1 '));
		self::assertFalse(condition: $readable->findsAsCaller(schema: 'case', id: 'case-2'));
		self::assertFalse(condition: $readable->findsAsCaller(schema: 'case', id: ''));
		self::assertFalse(condition: $readable->findsAsCaller(schema: 'brpPerson', id: 'case-1'));
	}//end testAStoredObjectIsReadableAndAMissingOneIsNot()

	/**
	 * An object the caller's rights hide is not readable, and the read never runs without those rights.
	 *
	 * @return void
	 */
	public function testAnObjectHiddenFromTheCallerIsNotReadable(): void {
		$register = $this->register();
		$register->hiddenFromCaller = ['case', 'brpPerson', 'kvkCompany'];
		$readable = $this->readable(register: $register);

		self::assertFalse(condition: $readable->findsAsCaller(schema: 'case', id: 'case-1'));
		self::assertSame(expected: [], actual: $readable->readableMasterEntities(refs: ['me-person', 'me-company']));
	}//end testAnObjectHiddenFromTheCallerIsNotReadable()

	/**
	 * A master entity is readable when a party the caller may read carries it, as a person or a company.
	 *
	 * @return void
	 */
	public function testAMasterEntityIsReadableThroughAPartyThatCarriesIt(): void {
		$readable = $this->readable(register: $this->register());

		self::assertSame(
			expected: ['me-person', 'me-company'],
			actual: $readable->readableMasterEntities(refs: ['me-person', 'me-unknown', 'me-company'])
		);
		self::assertSame(expected: [], actual: $readable->readableMasterEntities(refs: []));
	}//end testAMasterEntityIsReadableThroughAPartyThatCarriesIt()

	/**
	 * Without a signed-in caller nothing is readable, and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutACallerNothingIsReadable(): void {
		$readable = $this->readable(register: $this->register(), signedIn: false);

		self::assertFalse(condition: $readable->hasCaller());
		self::assertFalse(condition: $readable->findsAsCaller(schema: 'case', id: 'case-1'));
		self::assertSame(expected: [], actual: $readable->readableMasterEntities(refs: ['me-person']));
	}//end testWithoutACallerNothingIsReadable()

	/**
	 * A read that fails throws, so the caller can answer an outage rather than "not found".
	 *
	 * @return void
	 */
	public function testAFailedReadThrows(): void {
		$failing = new class {
			/**
			 * Always fails.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(): array {
				throw new RuntimeException('store down');
			}
		};

		$this->expectException(exception: RuntimeException::class);
		$this->readable(register: $failing)->findsAsCaller(schema: 'case', id: 'case-1');
	}//end testAFailedReadThrows()
	/**
	 * Many readable parties that share one reference do not push another reference out of the read.
	 *
	 * @return void
	 */
	public function testManyPartiesOnOneReferenceKeepTheOthersReadable(): void {
		$register = new InMemoryRegister();
		$register->pages = true;
		for ($i = 0; $i < 25; $i++) {
			$register->seed(schema: 'brpPerson', uuid: 'crowd-'.$i, row: ['masterEntityRef' => 'me-crowd']);
		}

		$register->seed(schema: 'brpPerson', uuid: 'p-1', row: ['masterEntityRef' => 'me-person']);

		self::assertSame(
			expected: ['me-crowd', 'me-person'],
			actual: $this->readable(register: $register)->readableMasterEntities(refs: ['me-crowd', 'me-person'])
		);
	}//end testManyPartiesOnOneReferenceKeepTheOthersReadable()

	/**
	 * A register without one party schema answers from the schemas it has, rather than failing.
	 *
	 * @return void
	 */
	public function testARegisterWithoutAPartySchemaReadsTheOthers(): void {
		$register = $this->register();
		$register->missingSchemas = ['kvkCompany'];

		self::assertSame(
			expected: ['me-person'],
			actual: $this->readable(register: $register)->readableMasterEntities(refs: ['me-person', 'me-company'])
		);
	}//end testARegisterWithoutAPartySchemaReadsTheOthers()
	/**
	 * A store that answers every party search with a full page for another reference ends the read
	 * at once: one search per party schema, and nothing readable.
	 *
	 * @return void
	 */
	public function testAFullPageOfOtherReferencesEndsTheRead(): void {
		$store = new class {
			/**
			 * How many searches ran.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * A full page whose rows all carry another reference.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(): array {
				$this->calls++;
				$filters = (func_get_args()[2] ?? []);

				return array_fill(0, (int)($filters['_limit'] ?? 10), ['masterEntityRef' => 'someone-else']);
			}
		};

		self::assertSame(expected: [], actual: $this->readable(register: $store)->readableMasterEntities(refs: ['me-1']));
		self::assertLessThanOrEqual(expected: 2, actual: $store->calls);
	}//end testAFullPageOfOtherReferencesEndsTheRead()

	/**
	 * A party search that fails for another reason than a missing schema still throws, so the caller answers 503.
	 *
	 * @return void
	 */
	public function testAFailedPartyReadThrows(): void {
		$failing = new class {
			/**
			 * Always fails.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(): array {
				throw new RuntimeException('store down');
			}
		};

		$this->expectException(exception: RuntimeException::class);
		$this->readable(register: $failing)->readableMasterEntities(refs: ['me-person']);
	}//end testAFailedPartyReadThrows()
}//end class
