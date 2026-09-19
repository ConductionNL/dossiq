<?php

/**
 * When a person's daily digest arrives, and whether it arrives at all.
 *
 * WHERE THE SWITCH LIVES. D-3 says switching the digest off belongs to the
 * platform's notification preferences, not to a dossiq settings page. That
 * landed as openregister#3757, so the switch is now the notification preference
 * `workDigest` / `workDigestReady`: a team lead may set the team's default, a
 * person may still decide for themselves, and the read says which of the two
 * decided. What is NOT done is a dossiq admin page that decides for everybody.
 *
 * THE LOCAL VALUE IS A MIRROR, NOT A SECOND OPINION. An instance whose
 * OpenRegister predates the routing keeps the behaviour it had, so the value is
 * still written here. When the platform routes, the platform's answer is the
 * one every reader gets, including the background job: a mirror that could win
 * a read is how a screen and a job come to disagree about whether somebody is
 * being told.
 *
 * The HOUR stays here. OpenRegister routes whether a notice is sent, not what
 * time of day a person wants their own summary.
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
use OCA\Dossiq\Service\Notification\NotificationRouting;
use InvalidArgumentException;
use Throwable;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

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
	 * @param IUserConfig         $userConfig Per-user preferences.
	 * @param NotificationRouting $routing    What the platform decided the switch is.
	 * @param LoggerInterface     $logger     Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly IUserConfig $userConfig,
		private readonly NotificationRouting $routing,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * This person's digest settings.
	 *
	 * The switch comes from the platform when the platform routes it, so a team
	 * default a lead set is honoured here and in the background job alike. The
	 * local value answers only when nothing routes.
	 *
	 * An unreadable preference reads as the default rather than as off: a
	 * config blip must not silently stop somebody's digest for a week.
	 *
	 * @param string $userId The person.
	 *
	 * @return array{enabled: bool, hour: int, source: string, scope: string} The settings.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function forUser(string $userId): array {
		$enabled = true;
		$hour = self::DEFAULT_HOUR;
		$decided = ['source' => 'dossiq', 'scope' => 'global'];

		// THE ONLY PLACE THAT DEGRADES, and deliberately the one with a usable
		// default. The seam onto the platform lets a fault through rather than
		// answering "nothing routes", so a bad minute on the register cannot
		// silently flip every reader back to the local mirror. Here it is a
		// caught fault with a named fallback instead, and the job keeps running.
		try {
			$enabled = $this->userConfig->getValueBool($userId, Application::APP_ID, self::PREF_ENABLED, true);
			$hour = $this->hourInRange(
				hour: $this->userConfig->getValueInt(
					$userId,
					Application::APP_ID,
					self::PREF_HOUR,
					self::DEFAULT_HOUR
				)
			);

			$routed = $this->routing->digestEnabledFor(userId: $userId);
			if ($routed !== null) {
				$enabled = $routed;
				$decided = ($this->routing->digestDecidedBy(userId: $userId) ?? $decided);
			}
		} catch (InvalidArgumentException $e) {
			$this->logger->warning('Dossiq: the digest settings could not be read: ' . $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the routed digest switch could not be read: ' . $e->getMessage());
		}

		return [
			'enabled' => $enabled,
			'hour' => $hour,
			'source' => (string)$decided['source'],
			'scope' => (string)$decided['scope'],
		];
	}//end forUser()

	/**
	 * Save this person's digest settings.
	 *
	 * The switch goes to the platform first, as this person's own override, so
	 * a team default stays visible underneath it rather than being overwritten.
	 * The local value is written either way, so an instance that loses the
	 * routing keeps the answer this person last gave.
	 *
	 * @param string  $userId  The person.
	 * @param bool    $enabled Whether they want the digest.
	 * @param integer $hour    The hour they want it in, 0 to 23.
	 *
	 * @return array{enabled: bool, hour: int, source: string, scope: string} The settings now stored.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function save(string $userId, bool $enabled, int $hour): array {
		$hour = $this->hourInRange(hour: $hour);
		$this->routing->setDigestEnabled(userId: $userId, enabled: $enabled);
		$this->userConfig->setValueBool($userId, Application::APP_ID, self::PREF_ENABLED, $enabled);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::PREF_HOUR, $hour);

		$settings = $this->forUser(userId: $userId);
		$settings['hour'] = $hour;

		return $settings;
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
		} catch (InvalidArgumentException $e) {
			$this->logger->warning('Dossiq: the last digest day could not be read: ' . $e->getMessage());

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
