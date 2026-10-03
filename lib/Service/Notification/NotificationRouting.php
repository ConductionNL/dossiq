<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the platform decided a person's notification preference is.
 *
 * OpenRegister owns the layers: the schema default, then the group default,
 * then the person's own value, and it says which one decided. dossiq reads
 * that answer and never computes one of its own. A second opinion about the
 * same switch is how a screen and a dispatcher come to disagree about whether
 * somebody is being told.
 *
 * IT WRITES ONE THING: the acting person's own digest switch, because dossiq's
 * digest endpoint already owned that value and two writers for one switch is
 * how a screen and a background job come to disagree. Every OTHER write, a
 * group default above all, is OpenRegister's endpoint, which the screens call
 * directly (ADR-022). Setting a team's default is an act of administration over
 * that team, and the check that the writer administers it belongs beside the
 * write, not in a second controller that forwards.
 *
 * ABSENT OPENREGISTER IS NOT AN ERROR. An instance whose OpenRegister predates
 * the routing has no such service, so every method answers null and the caller
 * falls back to what it did before.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
class NotificationRouting {

	/**
	 * OpenRegister's preference service, named as a string so this app never
	 * imports a class an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const PREFERENCE_SERVICE = 'OCA\\OpenRegister\\Service\\Notification\\NotificationPreferenceService';

	/**
	 * The notification domains dossiq's shipped rules declare.
	 *
	 * The dispatcher matches a pinned preference against the domain the RULE
	 * declares, so this list is the whole set a preference can usefully be
	 * pinned to. `caseType.notificationDomain` offers exactly these, and the
	 * settings screen offers exactly these: a domain nothing declares stores a
	 * preference nothing ever matches, which reads as a switch that does
	 * nothing.
	 *
	 * @var array<int, string>
	 */
	public const DOMAINS = ['zaken', 'waarneming', 'werkvoorraad'];

	/**
	 * The schema and notification key the daily work digest is routed under.
	 *
	 * @var string
	 */
	public const DIGEST_SCHEMA = 'workDigest';

	/**
	 * The digest rule's own key on that schema.
	 *
	 * @var string
	 */
	public const DIGEST_NOTIFICATION = 'workDigestReady';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access (ADR-083).
	 * @param LoggerInterface $logger          Says why a read answered nothing.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance routes notifications at all.
	 *
	 * @return bool TRUE when OpenRegister carries the routing.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function isAvailable(): bool {
		return ($this->service() !== null);
	}//end isAvailable()

	/**
	 * Every effective notification for one person, with the layer that decided.
	 *
	 * @param string      $userId The person.
	 * @param string|null $scope  Pin the answer to one scope, e.g. `domain:zaken`.
	 *
	 * @return array<int, array<string, mixed>>|null The entries, or null when nothing routes.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function effectiveFor(string $userId, ?string $scope = null): ?array {
		$service = $this->service();
		if ($service === null || $userId === '') {
			return null;
		}

		$scopes = [];
		if ($scope !== null && $scope !== '') {
			$scopes[] = $scope;
		}

		// A platform that THROWS propagates. Answering null here would say
		// "nothing routes on this instance", which is a different fact, and it
		// would silently hand every reader back to the local mirror the moment
		// the register had a bad minute. The caller that owns a sensible
		// default is the one that decides: DigestPreferences::forUser().
		$entries = $service->getEffectiveForUser(userId: $userId, scopes: $scopes);
		if (is_array($entries) === false) {
			return null;
		}

		return array_values($entries);
	}//end effectiveFor()

	/**
	 * One effective notification, with the layer that decided it.
	 *
	 * @param string      $userId       The person.
	 * @param string      $schema       The schema slug the rule lives on.
	 * @param string      $notification The rule's own key.
	 * @param string|null $scope        Pin the answer to one scope.
	 *
	 * @return array<string, mixed>|null The entry, or null when it does not route.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function effectiveOne(
		string $userId,
		string $schema,
		string $notification,
		?string $scope = null
	): ?array {
		$entries = $this->effectiveFor(userId: $userId, scope: $scope);
		if ($entries === null) {
			return null;
		}

		foreach ($entries as $entry) {
			if (($entry['schema'] ?? null) === $schema && ($entry['notification'] ?? null) === $notification) {
				return $entry;
			}
		}

		return null;
	}//end effectiveOne()

	/**
	 * Whether this person's daily work digest is switched on.
	 *
	 * The switch is a notification preference, so a team lead may set the
	 * team's default and a person may still decide for themselves. dossiq
	 * stores no answer of its own: it asks the layer that decided.
	 *
	 * @param string $userId The person.
	 *
	 * @return bool|null TRUE or FALSE when the platform decided, null when nothing routes.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function digestEnabledFor(string $userId): ?bool {
		$entry = $this->effectiveOne(
			userId: $userId,
			schema: self::DIGEST_SCHEMA,
			notification: self::DIGEST_NOTIFICATION
		);

		if ($entry === null || array_key_exists('enabled', $entry) === false) {
			return null;
		}

		return (bool)$entry['enabled'];
	}//end digestEnabledFor()

	/**
	 * Which layer decided this person's digest switch, and how narrowly.
	 *
	 * @param string $userId The person.
	 *
	 * @return array{source: string, scope: string}|null The deciding layer, or null when nothing routes.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function digestDecidedBy(string $userId): ?array {
		$entry = $this->effectiveOne(
			userId: $userId,
			schema: self::DIGEST_SCHEMA,
			notification: self::DIGEST_NOTIFICATION
		);

		if ($entry === null) {
			return null;
		}

		return [
			'source' => (string)($entry['source'] ?? 'schema-default'),
			'scope' => (string)($entry['scope'] ?? 'global'),
		];
	}//end digestDecidedBy()

	/**
	 * Switch this person's own daily digest on or off.
	 *
	 * Writes the person's own override and nothing else. A team default is not
	 * written here: it is an act of administration over that team, and the
	 * check for it lives beside OpenRegister's own endpoint.
	 *
	 * @param string $userId  The person, who is always the acting person.
	 * @param bool   $enabled Whether they want the digest.
	 *
	 * @return bool TRUE when the platform stored it, FALSE when nothing routes.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function setDigestEnabled(string $userId, bool $enabled): bool {
		$service = $this->service();
		if ($service === null || $userId === '' || method_exists($service, 'setOverride') === false) {
			return false;
		}

		try {
			$service->setOverride(
				userId: $userId,
				schemaSlug: self::DIGEST_SCHEMA,
				notificationKey: self::DIGEST_NOTIFICATION,
				override: ['enabled' => $enabled],
				scope: null
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq notifications: the digest switch was not routed: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end setDigestEnabled()

	/**
	 * OpenRegister's preference service, or null when this instance has none.
	 *
	 * @return object|null The service.
	 */
	private function service(): ?object {
		$service = $this->settingsService->getOpenRegisterClass(class: self::PREFERENCE_SERVICE);
		if (is_object($service) === false) {
			return null;
		}

		// An OpenRegister that predates the layered read answers the old shape,
		// which carries no deciding layer. Asking for it and getting a value
		// with no `source` is exactly the case this guard exists for: half an
		// answer reads as a whole one.
		if (method_exists($service, 'getEffectiveForUser') === false) {
			return null;
		}

		return $service;
	}//end service()
}//end class
