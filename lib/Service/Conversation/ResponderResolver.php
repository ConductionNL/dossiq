<?php

/**
 * Dossiq responder resolver.
 *
 * Turns the responders a case type names into the user ids a working channel
 * opens with. A responder is named as a user id or as a group; a group is
 * expanded to its members.
 *
 * Per ADR-102 a name that does not resolve is a refusal with a status, not a
 * silent omission: a crisis channel missing the one person who had to be in
 * it is worse than no channel, because everyone in it believes the right
 * people are there.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Conversation
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
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Resolves the responders a case type names to user ids.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class ResponderResolver {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema configuration.
	 * @param IUserManager    $userManager     Resolves a responder named as a user.
	 * @param IGroupManager   $groupManager    Resolves a responder named as a group.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Resolve the responders a case type names.
	 *
	 * @param string $caseTypeId Case type UUID or slug, empty when the case names none.
	 *
	 * @return array{ok: bool, users: array<string>, unresolved: array<string>}
	 *         The user ids to pull in, and the names that did not resolve.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function resolve(string $caseTypeId): array {
		$declared = $this->declaredResponders(caseTypeId: $caseTypeId);
		if ($declared === []) {
			// A case type that names nobody has nobody to pull in, which is a
			// configuration that cannot stand up a channel. Refuse, and name it.
			return ['ok' => false, 'users' => [], 'unresolved' => ['(none declared)']];
		}

		$users = [];
		$unresolved = [];

		foreach ($declared as $responder) {
			$members = $this->membersOf(responder: $responder);
			if ($members === []) {
				$unresolved[] = $responder;
				continue;
			}

			foreach ($members as $member) {
				if (in_array($member, $users, true) === false) {
					$users[] = $member;
				}
			}
		}

		if ($unresolved !== []) {
			return ['ok' => false, 'users' => [], 'unresolved' => $unresolved];
		}

		return ['ok' => true, 'users' => $users, 'unresolved' => []];
	}//end resolve()

	/**
	 * The responders a case type declares, as written.
	 *
	 * @param string $caseTypeId Case type UUID or slug.
	 *
	 * @return array<string> The declared names.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function declaredResponders(string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		$caseType = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseTypeId,
		);

		if ($caseType === null) {
			return [];
		}

		$responders = ($caseType['responders'] ?? []);
		if (is_array($responders) === false) {
			return [];
		}

		$names = [];
		foreach ($responders as $responder) {
			$name = trim((string)$responder);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end declaredResponders()

	/**
	 * The users one declared responder stands for.
	 *
	 * @param string $responder A user id, or `group:<gid>`, or a bare group id.
	 *
	 * @return array<string> User ids, empty when the name does not resolve.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function membersOf(string $responder): array {
		if (str_starts_with($responder, 'group:') === true) {
			return $this->groupMembers(groupId: substr($responder, 6));
		}

		if ($this->userManager->userExists($responder) === true) {
			return [$responder];
		}

		return $this->groupMembers(groupId: $responder);
	}//end membersOf()

	/**
	 * The members of a group.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return array<string> User ids, empty when the group is absent or empty.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function groupMembers(string $groupId): array {
		if ($groupId === '') {
			return [];
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return [];
		}

		$members = [];
		foreach ($group->getUsers() as $user) {
			$members[] = $user->getUID();
		}

		return $members;
	}//end groupMembers()

}//end class
