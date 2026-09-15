<?php

/**
 * Dossiq Talk conversation broker.
 *
 * The one seam between dossiq and Nextcloud Talk. `HearingService` reached
 * `OCP\Talk\IBroker` through `\OC::$server` inline, which made the hoorzitting
 * the only place in the app that could open a room and made that code
 * untestable. This class holds the lookup once, so any case-level conversation
 * uses the same broker the hearing already uses.
 *
 * dossiq ships no calling, no recorder and no signalling: this is a lookup and
 * a name, and Talk does the rest.
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

use OCA\Dossiq\AppInfo\Application;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Talk\IBroker;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens and closes Nextcloud Talk rooms on behalf of a case.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class TalkConversationBroker {

	/**
	 * Constructor.
	 *
	 * @param IServerContainer $container   Server container, asked for the Talk broker.
	 * @param IUserManager     $userManager Resolves a moderator's id to the user Talk wants.
	 * @param LoggerInterface  $logger      Logger.
	 */
	public function __construct(
		private readonly IServerContainer $container,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance can hold a conversation at all.
	 *
	 * An instance without Talk offers no conversation affordance, and the case
	 * says why rather than showing a button that cannot work.
	 *
	 * @return bool True when Talk is installed and its broker answers.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function isAvailable(): bool {
		return $this->broker() !== null;
	}//end isAvailable()

	/**
	 * Open one Talk room.
	 *
	 * Moderators are named as user ids, because that is what a case type and
	 * a case field hold. Talk's broker wants `IUser` objects, so they are
	 * resolved here: an id nothing answers to is dropped rather than failing
	 * the room, since the responder resolution that produced the list has
	 * already refused on a name it could not resolve.
	 *
	 * @param string        $name       Room name, shown in Talk.
	 * @param array<string> $moderators User ids that moderate the room.
	 *
	 * @return array{id: string, url: string}|null The room's id and absolute
	 *                                             URL, or null when Talk is
	 *                                             absent or refused the room.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function createRoom(string $name, array $moderators = []): ?array {
		$broker = $this->broker();
		if ($broker === null) {
			return null;
		}

		try {
			$room = $broker->createConversation(
				name: $name,
				moderators: $this->resolveModerators(moderators: $moderators),
				options: $broker->newConversationOptions(),
			);

			return [
				'id' => $room->getId(),
				'url' => $room->getAbsoluteUrl(),
			];
		} catch (Throwable $e) {
			$this->logger->warning(
				'Talk refused a room named "' . $name . '": ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);

			return null;
		}//end try
	}//end createRoom()

	/**
	 * Close one Talk room.
	 *
	 * A working channel that outlives its case becomes a place people talk
	 * about a closed case with no record, so closing the case closes the room.
	 * What was said is filed on the case before this is called.
	 *
	 * @param string $roomId The Talk conversation id.
	 *
	 * @return bool True when Talk closed the room.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function closeRoom(string $roomId): bool {
		$broker = $this->broker();
		if ($broker === null) {
			return false;
		}

		try {
			$broker->deleteConversation(id: $roomId);

			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Talk refused to close room "' . $roomId . '": ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);

			return false;
		}//end try
	}//end closeRoom()

	/**
	 * Turn moderator user ids into the users Talk's broker signs for.
	 *
	 * @param array<string> $moderators User ids.
	 *
	 * @return array<IUser> The users that exist.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function resolveModerators(array $moderators): array {
		$users = [];
		foreach ($moderators as $moderator) {
			$user = $this->userManager->get($moderator);
			if ($user instanceof IUser) {
				$users[] = $user;
			}
		}

		return $users;
	}//end resolveModerators()

	/**
	 * Resolve the Talk broker, or null when this instance has no Talk.
	 *
	 * @return IBroker|null The broker, or null.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	private function broker(): ?IBroker {
		try {
			if ($this->container->has(IBroker::class) === false) {
				return null;
			}

			$broker = $this->container->get(IBroker::class);
			if (($broker instanceof IBroker) === false) {
				return null;
			}

			if ($broker->hasBackend() === false) {
				return null;
			}

			return $broker;
		} catch (Throwable $e) {
			$this->logger->debug(
				'Talk broker not resolvable: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);

			return null;
		}//end try
	}//end broker()

}//end class
