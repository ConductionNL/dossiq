<?php

/**
 * The dashboard payload one reader is entitled to.
 *
 * 🔑 THIS IS THE SEAM BETWEEN THE DECLARATION AND THE REQUEST. {@see
 * WidgetRoles} is pure and knows nothing about Nextcloud; this class reads the
 * shipped manifest, asks who the reader is, and hands the pure half an answer.
 * Keeping them apart is what lets every rule about who sees what be tested
 * without a session, a group manager or a file on disk.
 *
 * 🔴 A MANIFEST THAT CANNOT BE READ NARROWS NOTHING, AND SAYS SO. Failing
 * closed here would empty every dashboard in the gemeente over a file
 * permission, which is a louder failure than the one it prevents and a worse
 * one: nobody would suspect the manifest. The reported warning is what makes
 * it findable, and the structural test is what stops a widget going
 * undeclared in the first place.
 *
 * 🔴 AN UNRESOLVABLE ROLE HIDES ITS WIDGET FROM EVERYONE (ADR-102). Not from
 * the people who happen not to hold the group: from everyone, because a role
 * nothing answers to is a configuration mistake, and the alternative reading
 * turns that mistake into a disclosure.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dashboard
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
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dashboard;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Narrows a dashboard payload to what the reader's widgets may show.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */
class DashboardWidgetScope {
	/**
	 * Where the shipped manifest lives, relative to the app root.
	 *
	 * @var string
	 */
	private const MANIFEST_PATH = '/src/manifest.json';

	/**
	 * The manifest, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $manifest = null;

	/**
	 * Constructor.
	 *
	 * @param WidgetRoles $widgets The declaration reader.
	 * @param IGroupManager $groups Nextcloud's groups, for what a reader holds and what exists.
	 * @param IUserManager $users Nextcloud's users, to resolve the reader.
	 * @param LoggerInterface $logger Logger.
	 * @param string $appRoot The app directory, injected so a test can point at a fixture.
	 */
	public function __construct(
		private readonly WidgetRoles $widgets,
		private readonly IGroupManager $groups,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
		private readonly string $appRoot = __DIR__ . '/../../..',
	) {
	}//end __construct()

	/**
	 * The payload this reader may receive.
	 *
	 * @param array<string, mixed> $payload The computed dashboard payload.
	 * @param string $userId The reader.
	 *
	 * @return array<string, mixed> The payload, narrowed.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function narrowFor(array $payload, string $userId): array {
		$manifest = $this->manifest();
		if ($manifest === []) {
			return $payload;
		}

		$verdicts = $this->widgets->withheldFields(
			manifest: $manifest,
			held: $this->heldBy(userId: $userId),
			known: $this->knownGroups(),
		);

		foreach ($verdicts['unresolvable'] as $widgetId) {
			// Reported, not swallowed. A widget hidden from everyone because
			// its role was renamed looks exactly like a widget somebody
			// removed, and an administrator has no other way to tell.
			$this->logger->warning(
				'Dossiq dashboard: a widget declares a role no group answers to, so it is hidden from everyone',
				['widget' => $widgetId],
			);
		}

		return $this->widgets->narrow(payload: $payload, withhold: $verdicts['withhold']);
	}//end narrowFor()

	/**
	 * The groups this reader is in.
	 *
	 * @param string $userId The reader.
	 *
	 * @return array<int, string> The group ids.
	 */
	private function heldBy(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$user = $this->users->get($userId);
		if ($user === null) {
			// A reader the user manager does not know holds nothing, which
			// hides every declared widget from them. That is the right way
			// round: an unknown reader is not a reason to show figures.
			//
			// NOT wrapped in a try/catch. A user manager that throws is an
			// instance in trouble, and swallowing that to "holds nothing"
			// would answer a narrowed payload that looks exactly like a
			// correct one for a reader with no groups. It fails loudly
			// instead, which is the rule ServiceCatchReturnsNullTest holds
			// the whole app to.
			return [];
		}

		return array_values($this->groups->getUserGroupIds($user));
	}//end heldBy()

	/**
	 * Every group that exists on this instance.
	 *
	 * @return array<int, string> The group ids.
	 */
	private function knownGroups(): array {
		$ids = [];
		foreach ($this->groups->search('') as $group) {
			$ids[] = $group->getGID();
		}

		return $ids;
	}//end knownGroups()

	/**
	 * The shipped manifest, read once.
	 *
	 * @return array<string, mixed> The manifest, empty when it cannot be read.
	 */
	private function manifest(): array {
		if ($this->manifest !== null) {
			return $this->manifest;
		}

		$path = ($this->appRoot . self::MANIFEST_PATH);
		$raw = (file_exists($path) === true ? file_get_contents($path) : false);
		if ($raw === false) {
			$this->logger->warning('Dossiq dashboard: the manifest could not be read, so no widget scoping is applied', ['path' => $path]);
			$this->manifest = [];

			return $this->manifest;
		}

		$decoded = json_decode($raw, true);
		$this->manifest = (is_array($decoded) === true ? $decoded : []);

		return $this->manifest;
	}//end manifest()
}//end class
