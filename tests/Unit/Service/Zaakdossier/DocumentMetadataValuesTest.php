<?php

/**
 * Document Metadata Values Test
 *
 * Pins how a document's stored metadata is read back: the direction falls
 * back to internal, keywords are cleaned, cut and de-duplicated, an access
 * exception exists only when it names a user or group, and each name in an
 * access exception is shown by its display name where one is known.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\AccessExceptionNames;
use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the document metadata value helpers.
 */
#[CoversClass(InformatieobjectMetadataNormaliser::class)]
#[CoversClass(AccessExceptionValue::class)]
#[CoversClass(AccessExceptionNames::class)]
final class DocumentMetadataValuesTest extends TestCase {
	/**
	 * Stored directions and what they read as.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function directions(): array {
		return [
			'incoming'       => ['incoming', 'incoming'],
			'outgoing'       => [' outgoing ', 'outgoing'],
			'internal'       => ['internal', 'internal'],
			'other casing'   => ['Incoming', 'internal'],
			'unknown'        => ['sideways', 'internal'],
			'empty'          => ['', 'internal'],
			'not a string'   => [1, 'internal'],
			'absent'         => [null, 'internal'],
		];
	}//end directions()

	/**
	 * A direction is one of the three known values, trimmed, else internal.
	 *
	 * @param mixed  $value    The stored value.
	 * @param string $expected The direction.
	 *
	 * @return void
	 */
	#[DataProvider('directions')]
	public function testDirection(mixed $value, string $expected): void {
		self::assertSame(expected: $expected, actual: (new InformatieobjectMetadataNormaliser())->direction(value: $value));
	}//end testDirection()

	/**
	 * Keywords are trimmed strings or numbers, cut at 64 characters, without blanks or repeats, in order.
	 *
	 * @return void
	 */
	public function testKeywords(): void {
		$long = str_repeat('ü', 70);

		$keywords = (new InformatieobjectMetadataNormaliser())->keywords(
			value: [' bouw ', 'bouw', '', '  ', 2026, 3.5, null, ['nested'], true, $long, 'milieu']
		);

		self::assertSame(expected: ['bouw', '2026', '3.5', str_repeat('ü', 64), 'milieu'], actual: $keywords);
	}//end testKeywords()

	/**
	 * Keywords that are not a list read as none.
	 *
	 * @return void
	 */
	public function testKeywordsNotAListAreNone(): void {
		self::assertSame(expected: [], actual: (new InformatieobjectMetadataNormaliser())->keywords(value: 'bouw, milieu'));
	}//end testKeywordsNotAListAreNone()

	/**
	 * An access exception keeps its cleaned users and groups and its bookkeeping, as strings.
	 *
	 * @return void
	 */
	public function testAccessExceptionIsCleaned(): void {
		$exception = (new AccessExceptionValue())->exceptionOf(
			record: [
				'accessException' => [
					'users'  => [' alice ', 'alice', '', 42, 4.2, null],
					'groups' => 'juristen',
					'reason' => 'Privacy',
					'setBy'  => 'bob',
					'setAt'  => '2026-11-12T10:00:00+01:00',
				],
			]
		);

		self::assertSame(
			expected: ['users' => ['alice', '42'], 'groups' => ['juristen'], 'reason' => 'Privacy', 'setBy' => 'bob', 'setAt' => '2026-11-12T10:00:00+01:00'],
			actual: $exception
		);
	}//end testAccessExceptionIsCleaned()

	/**
	 * Without any user or group there is no access exception.
	 *
	 * @return void
	 */
	public function testNoAccessExceptionWithoutUsersOrGroups(): void {
		$value = new AccessExceptionValue();

		self::assertNull(actual: $value->exceptionOf(record: []));
		self::assertNull(actual: $value->exceptionOf(record: ['accessException' => 'alice']));
		self::assertNull(actual: $value->exceptionOf(record: ['accessException' => ['users' => [' '], 'groups' => [], 'reason' => 'x']]));
		self::assertSame(
			expected: ['users' => [], 'groups' => ['g'], 'reason' => '', 'setBy' => '', 'setAt' => ''],
			actual: $value->exceptionOf(record: ['accessException' => ['groups' => ['g']]])
		);
	}//end testNoAccessExceptionWithoutUsersOrGroups()

	/**
	 * Each user and group in an access exception is named by its display name, falling back to its id;
	 * documents without one are returned unchanged.
	 *
	 * @return void
	 */
	public function testAccessExceptionNames(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ($uid === 'alice' ? 'Alice de Vries' : null));
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('Juristen');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'juristen' ? $group : null));

		$documents = [
			['id' => 'd1', 'accessException' => ['users' => ['alice', 'ghost'], 'groups' => ['juristen', 'gone']]],
			['id' => 'd2'],
		];

		self::assertSame(
			expected: [
				['id' => 'd1', 'accessException' => ['users' => ['alice', 'ghost'], 'groups' => ['juristen', 'gone']], 'accessExceptionNames' => ['Alice de Vries', 'ghost', 'Juristen', 'gone']],
				['id' => 'd2'],
			],
			actual: (new AccessExceptionNames(userManager: $users, groupManager: $groups))->withNames(documents: $documents)
		);
	}//end testAccessExceptionNames()
}//end class
