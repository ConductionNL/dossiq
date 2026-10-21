<?php

/**
 * Dossiq Woo review reports: the two organisation switches and the reader group.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Whether the organisation switched a Woo review report on, and who may read the throughput.
 *
 * Both reports are a policy choice (decision D9): a per-reviewer count is a
 * per-person productivity figure, and a parties report lists third parties'
 * names. So both are off until an administrator says yes, and the throughput
 * report cannot be switched on without a named group that exists. An unset,
 * empty or unreadable value reads as off: off is the safe state.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
class WooReportSwitches {

	/**
	 * Throughput per reviewer per day (row 16.10).
	 */
	public const THROUGHPUT = 'reviewerThroughputReport';

	/**
	 * The collection by sender, recipient and domain (row 16.11).
	 */
	public const PARTIES = 'collectionPartiesReport';

	/**
	 * The Nextcloud group whose members read the throughput report.
	 */
	public const READERS = 'reviewerThroughputReaders';

	/**
	 * Each setting's earlier, procedure-named key. Read when the current key
	 * was never written, and accepted on a save, so an instance configured
	 * before the rename keeps its reports.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_KEYS = [
		self::THROUGHPUT => 'wooReviewerThroughputReport',
		self::PARTIES => 'wooCollectionPartiesReport',
		self::READERS => 'wooReviewerThroughputReaders',
	];

	/**
	 * The refusal rule when the throughput report has no existing reader group.
	 */
	public const RULE_NEEDS_READERS = 'woo-throughput-needs-reader-group';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IGroupManager $groupManager The Nextcloud group manager.
	 * @param IUserSession|null $userSession The session, for what the signed-in user is offered.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Whether a switch is on. Anything but an explicit yes reads as off.
	 *
	 * The throughput report also needs its reader group to exist: a group
	 * deleted after the switch went on turns the report off rather than
	 * leaving it readable by nobody in particular.
	 *
	 * @param string $switch One of self::THROUGHPUT or self::PARTIES.
	 *
	 * @return bool True when the organisation switched the report on.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	public function isOn(string $switch): bool {
		if ($switch !== self::THROUGHPUT && $switch !== self::PARTIES) {
			return false;
		}

		if (self::truthy(value: $this->stored(key: $switch)) === false) {
			return false;
		}

		if ($switch === self::THROUGHPUT) {
			return $this->groupExists(groupId: $this->readerGroup());
		}

		return true;
	}//end isOn()

	/**
	 * The configured reader group of the throughput report, or ''.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function readerGroup(): string {
		return trim($this->stored(key: self::READERS));
	}//end readerGroup()

	/**
	 * Whether a user is a member of the throughput reader group.
	 *
	 * Being an administrator is not enough: the reader group is the only
	 * way in, so an administrator outside it is refused like anyone else.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return bool True when the user is in the existing reader group.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function maySeeThroughput(string $userId): bool {
		$group = $this->readerGroup();
		if ($userId === '' || $this->groupExists(groupId: $group) === false) {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $group);
	}//end maySeeThroughput()

	/**
	 * Which reports the signed-in user is offered, for the menu.
	 *
	 * The throughput report is offered only while it is on AND the user is
	 * in its reader group. The parties report is offered to every signed-in
	 * user while it is on: it is read per case, and whether this user may read
	 * a case is asked when they name one.
	 *
	 * @return array{throughput: bool, parties: bool} What the user is offered.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	public function offeredToCurrentUser(): array {
		$user = $this->userSession?->getUser();
		if ($user === null) {
			return ['throughput' => false, 'parties' => false];
		}

		return [
			'throughput' => $this->isOn(switch: self::THROUGHPUT) && $this->maySeeThroughput(userId: $user->getUID()),
			'parties' => $this->isOn(switch: self::PARTIES),
		];
	}//end offeredToCurrentUser()

	/**
	 * Refuse a settings save that would leave the throughput report on without a reader group.
	 *
	 * Only a save that touches the switch or the group is checked, so an
	 * unrelated save never fails on it. The values the save carries win over
	 * the stored ones.
	 *
	 * @param array<string, mixed> $data The settings the administrator saves.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the throughput report would be on with no existing reader group.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	public function assertSaveAllowed(array $data): void {
		$data = self::withCurrentKeys(data: $data);
		if (array_key_exists(self::THROUGHPUT, $data) === false && array_key_exists(self::READERS, $data) === false) {
			return;
		}

		$switch = (string)($data[self::THROUGHPUT] ?? $this->stored(key: self::THROUGHPUT));
		if (self::truthy(value: $switch) === false) {
			return;
		}

		$group = trim((string)($data[self::READERS] ?? $this->stored(key: self::READERS)));
		if ($this->groupExists(groupId: $group) === true) {
			return;
		}

		throw new RefusedException(
			rule: self::RULE_NEEDS_READERS,
			sentence: 'Name an existing reader group before switching the throughput report on.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end assertSaveAllowed()

	/**
	 * A settings save with every earlier key carried onto its current key.
	 *
	 * A value posted under the current key wins over one under the old key.
	 *
	 * @param array<string, mixed> $data The settings as posted.
	 *
	 * @return array<string, mixed> The settings, current keys filled in.
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public static function withCurrentKeys(array $data): array {
		foreach (self::LEGACY_KEYS as $current => $legacy) {
			if (array_key_exists($legacy, $data) === true && array_key_exists($current, $data) === false) {
				$data[$current] = $data[$legacy];
			}
		}

		return $data;
	}//end withCurrentKeys()

	/**
	 * Whether a stored or posted value means yes.
	 *
	 * @param mixed $value The value as stored or posted.
	 *
	 * @return bool True for 'true', '1', 'yes' or 'on'.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	public static function truthy(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}//end truthy()

	/**
	 * Read a stored value, '' when unset.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string The stored value.
	 */
	private function stored(string $key): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		$legacy = (self::LEGACY_KEYS[$key] ?? null);
		if ($value !== '' || $legacy === null || $this->appConfig->hasKey(Application::APP_ID, $key) === true) {
			return $value;
		}

		return $this->appConfig->getValueString(Application::APP_ID, $legacy, '');
	}//end stored()

	/**
	 * Whether a named group exists. An empty name never does.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return bool True when the group exists.
	 */
	private function groupExists(string $groupId): bool {
		if ($groupId === '') {
			return false;
		}

		return $this->groupManager->groupExists($groupId);
	}//end groupExists()
}//end class
