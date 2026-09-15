<?php

/**
 * When a person's daily digest arrives, and whether it arrives at all.
 *
 * WHERE THE SWITCH LIVES. D-3 says switching the digest off belongs to the
 * platform's notification preferences, not to a dossiq settings page, and the
 * routed version of that is openregister `notification-routing-per-group-and-
 * scope`, which is not on this base yet. So the switch is stored as a personal
 * preference and surfaced in Settings, Personal, beside the reader's other
 * notification choices, and it moves to the register's routing the day that
 * lands. What is NOT done is a dossiq admin page that decides for everybody:
 * the choice is the reader's either way, which is the part of D-3 that has to
 * hold now rather than later.
 *
 * The default is on. A digest nobody switched on is a feature nobody has, and
 * the empty-queue rule below is what makes the default safe: a person with
 * nothing waiting hears nothing, so the cost of being opted in is zero on the
 * days it would have been noise.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCA\Dossiq\AppInfo\Application;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The reader's digest settings.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class DigestPreferences {
	/**
	 * Whether this person wants the digest.
	 *
	 * @var string
	 */
	public const PREF_ENABLED = 'work_digest_enabled';

	/**
	 * The hour of the reader's day the digest is sent in, 0 to 23.
	 *
	 * @var string
	 */
	public const PREF_HOUR = 'work_digest_hour';

	/**
	 * The day the last digest went out, so a person gets one a day.
	 *
	 * @var string
	 */
	public const PREF_LAST_SENT = 'work_digest_last_sent';

	/**
	 * The hour a digest arrives when nobody chose one.
	 *
	 * @var int
	 */
	public const DEFAULT_HOUR = 8;

	/**
	 * Constructor.
	 *
	 * @param IUserConfig     $userConfig Per-user preferences.
	 * @param LoggerInterface $logger     Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly IUserConfig $userConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * This person's digest settings.
	 *
	 * An unreadable preference reads as the default rather than as off: a
	 * config blip must not silently stop somebody's digest for a week.
	 *
	 * @param string $userId The person.
	 *
	 * @return array{enabled: bool, hour: int} The settings.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function forUser(string $userId): array {
		try {
			return [
				'enabled' => $this->userConfig->getValueBool($userId, Application::APP_ID, self::PREF_ENABLED, true),
				'hour' => $this->hourInRange(
					hour: $this->userConfig->getValueInt(
						$userId,
						Application::APP_ID,
						self::PREF_HOUR,
						self::DEFAULT_HOUR
					)
				),
			];
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: reading the digest settings failed: ' . $e->getMessage());

			return ['enabled' => true, 'hour' => self::DEFAULT_HOUR];
		}
	}//end forUser()

	/**
	 * Save this person's digest settings.
	 *
	 * @param string  $userId  The person.
	 * @param bool    $enabled Whether they want the digest.
	 * @param integer $hour    The hour they want it in, 0 to 23.
	 *
	 * @return array{enabled: bool, hour: int} The settings now stored.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The stored preference is itself an on/off switch.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function save(string $userId, bool $enabled, int $hour): array {
		$hour = $this->hourInRange(hour: $hour);
		$this->userConfig->setValueBool($userId, Application::APP_ID, self::PREF_ENABLED, $enabled);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::PREF_HOUR, $hour);

		return ['enabled' => $enabled, 'hour' => $hour];
	}//end save()

	/**
	 * Whether this person is due a digest in this hour.
	 *
	 * @param string  $userId The person.
	 * @param integer $hour   The hour the job is running in.
	 * @param string  $today  Today, `Y-m-d`.
	 *
	 * @return bool TRUE when a digest should be composed for them now.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function isDue(string $userId, int $hour, string $today): bool {
		$settings = $this->forUser(userId: $userId);
		if ($settings['enabled'] === false || $settings['hour'] !== $hour) {
			return false;
		}

		return ($this->lastSentOn(userId: $userId) !== $today);
	}//end isDue()

	/**
	 * The day this person's last digest went out.
	 *
	 * @param string $userId The person.
	 *
	 * @return string The day, `Y-m-d`, or an empty string.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function lastSentOn(string $userId): string {
		try {
			return $this->userConfig->getValueString($userId, Application::APP_ID, self::PREF_LAST_SENT, '');
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: reading the last digest day failed: ' . $e->getMessage());

			return '';
		}
	}//end lastSentOn()

	/**
	 * Record that this person's digest went out today.
	 *
	 * @param string $userId The person.
	 * @param string $today  Today, `Y-m-d`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function markSent(string $userId, string $today): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, self::PREF_LAST_SENT, $today);
	}//end markSent()

	/**
	 * Keep an hour inside the day.
	 *
	 * @param integer $hour The hour as it was given.
	 *
	 * @return integer The hour, 0 to 23.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function hourInRange(int $hour): int {
		if ($hour < 0 || $hour > 23) {
			return self::DEFAULT_HOUR;
		}

		return $hour;
	}//end hourInRange()
}//end class
