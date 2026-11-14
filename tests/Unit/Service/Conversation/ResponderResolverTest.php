<?php

/**
 * Tests for the responder resolver.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Conversation
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Conversation;


use OCA\Dossiq\Service\Conversation\ResponderResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A case type's responders resolve to user ids, or the resolution names what did not.
 *
 * @covers \OCA\Dossiq\Service\Conversation\ResponderResolver
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ResponderResolverTest extends TestCase {

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The configuration the resolver reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'case_type_schema' => 'caseType'];

	/**
	 * The user manager.
	 *
	 * @var IUserManager|MockObject
	 */
	private IUserManager $userManager;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The groups the group manager answers for, by id.
	 *
	 * @var array<string, array<string>>
	 */
	private array $groups = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['anna', 'bob'], true)
		);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('get')->willReturnCallback(
			function (string $gid): ?IGroup {
				if (array_key_exists($gid, $this->groups) === false) {
					return null;
				}

				$users = [];
				foreach ($this->groups[$gid] as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$users[] = $user;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('getUsers')->willReturn($users);

				return $group;
			}
		);
	}//end setUp()

	/**
	 * The resolver under test.
	 *
	 * @param bool $withRegister Whether OpenRegister is available.
	 *
	 * @return ResponderResolver
	 */
	private function resolver(bool $withRegister = true): ResponderResolver {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($withRegister === true ? $this->register : null));
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new ResponderResolver(
			settingsService: $settings,
			userManager: $this->userManager,
			groupManager: $this->groupManager,
		);
	}//end resolver()

	/**
	 * Seed one case type with these responders.
	 *
	 * @param mixed $responders What the case type carries.
	 *
	 * @return void
	 */
	private function caseType(mixed $responders): void {
		$this->register->seed(schema: 'caseType', uuid: 'type-1', row: ['title' => 'Melding', 'responders' => $responders]);
	}//end caseType()

	/**
	 * The refusal a case type without responders gets.
	 *
	 * @var array{ok: bool, users: array<string>, unresolved: array<string>}
	 */
	private const NONE_DECLARED = ['ok' => false, 'users' => [], 'unresolved' => ['(none declared)']];

	/**
	 * A case that names no case type has nobody to pull in.
	 *
	 * @return void
	 */
	public function testAnEmptyCaseTypeDeclaresNobody(): void {
		$this->assertSame(self::NONE_DECLARED, $this->resolver()->resolve(caseTypeId: ''));
	}//end testAnEmptyCaseTypeDeclaresNobody()

	/**
	 * Without OpenRegister nothing can be read, so nobody is declared.
	 *
	 * @return void
	 */
	public function testNoObjectServiceDeclaresNobody(): void {
		$this->caseType(responders: ['anna']);

		$this->assertSame(self::NONE_DECLARED, $this->resolver(withRegister: false)->resolve(caseTypeId: 'type-1'));
	}//end testNoObjectServiceDeclaresNobody()

	/**
	 * Without a configured case type schema nobody is declared.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaDeclaresNobody(): void {
		$this->caseType(responders: ['anna']);
		$this->config['case_type_schema'] = '';

		$this->assertSame(self::NONE_DECLARED, $this->resolver()->resolve(caseTypeId: 'type-1'));
	}//end testAnUnconfiguredSchemaDeclaresNobody()

	/**
	 * A case type that does not exist declares nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeDeclaresNobody(): void {
		$this->assertSame(self::NONE_DECLARED, $this->resolver()->resolve(caseTypeId: 'missing'));
	}//end testAnUnknownCaseTypeDeclaresNobody()

	/**
	 * Responders that are not a list, or only blank names, declare nobody.
	 *
	 * @return void
	 */
	public function testNonListOrBlankRespondersDeclareNobody(): void {
		$this->caseType(responders: 'anna');
		$this->assertSame(self::NONE_DECLARED, $this->resolver()->resolve(caseTypeId: 'type-1'));

		$this->caseType(responders: ['  ', '']);
		$this->assertSame(self::NONE_DECLARED, $this->resolver()->resolve(caseTypeId: 'type-1'));
	}//end testNonListOrBlankRespondersDeclareNobody()

	/**
	 * Users, prefixed groups and bare groups resolve, trimmed and each user once, in order.
	 *
	 * @return void
	 */
	public function testUsersAndGroupsResolveToDistinctUserIds(): void {
		$this->groups = ['wijkteam' => ['carla', 'anna'], 'balie' => ['dirk', 'carla']];
		$this->caseType(responders: [' anna ', 'group:wijkteam', 'balie', 'anna']);

		$this->assertSame(
			['ok' => true, 'users' => ['anna', 'carla', 'dirk'], 'unresolved' => []],
			$this->resolver()->resolve(caseTypeId: 'type-1')
		);
	}//end testUsersAndGroupsResolveToDistinctUserIds()

	/**
	 * A name that resolves to nobody refuses the whole set and is named.
	 *
	 * @return void
	 */
	public function testUnresolvedNamesRefuseAndAreNamed(): void {
		$this->groups = ['leeg' => []];
		$this->caseType(responders: ['anna', 'ghost', 'group:leeg', 'group:']);

		$this->assertSame(
			['ok' => false, 'users' => [], 'unresolved' => ['ghost', 'group:leeg', 'group:']],
			$this->resolver()->resolve(caseTypeId: 'type-1')
		);
	}//end testUnresolvedNamesRefuseAndAreNamed()

	/**
	 * A prefixed group is looked up as a group even when a user has the same id.
	 *
	 * @return void
	 */
	public function testAPrefixedNameIsAlwaysAGroup(): void {
		$this->groups = ['anna' => ['erik']];
		$this->caseType(responders: ['group:anna']);

		$this->assertSame(
			['ok' => true, 'users' => ['erik'], 'unresolved' => []],
			$this->resolver()->resolve(caseTypeId: 'type-1')
		);
	}//end testAPrefixedNameIsAlwaysAGroup()

}//end class
