<?php

/**
 * A private label one person puts on a shared case.
 *
 * 🔴 IT IS NOT A SECOND STATUS. The case's own status is the one everybody
 * reads, the one reports group by and the one a flow branches on. A personal
 * stage is a note to self: it says "I am waiting on advice for this one",
 * visible to nobody else, changing nothing. The risk this design exists to
 * avoid is two people reading two different statuses off one case, so the case
 * page shows the case's status prominently and the personal stage as what it
 * is.
 *
 * WHY IT IS A PREFERENCE AND NOT AN OBJECT. An object is readable by anybody
 * the case is readable by, and it would carry an audit trail and appear in a
 * register export. A per-user preference cannot be read by another person
 * through any dossiq endpoint, cannot join a report, and disappears with the
 * account. That is exactly the privacy the requirement asks for, and it is a
 * property of where the data lives rather than of a filter somebody remembered
 * to write.
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
use InvalidArgumentException;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes one person's private stages.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class PersonalStageService {
	/**
	 * The preference holding the map of case id to stage.
	 *
	 * @var string
	 */
	public const PREF_STAGES = 'personal_stages';

	/**
	 * How many stages one person may keep.
	 *
	 * A cap rather than unbounded growth: the preference is one row, and a
	 * handler who labels four hundred cases has a different problem than this
	 * feature solves.
	 *
	 * @var int
	 */
	public const MAX_STAGES = 200;

	/**
	 * How long a stage may be.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 60;

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
	 * This person's stage on one case.
	 *
	 * @param string $userId The reader. Their own, always: there is no
	 *                       parameter anywhere that reads somebody else's.
	 * @param string $caseId The case.
	 *
	 * @return string The stage, or an empty string when they set none.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function get(string $userId, string $caseId): string {
		return (string)($this->all(userId: $userId)[$caseId] ?? '');
	}//end get()

	/**
	 * Every stage this person set.
	 *
	 * @param string $userId The reader.
	 *
	 * @return array<string, string> Case id to stage.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function all(string $userId): array {
		try {
			$raw = $this->userConfig->getValueString($userId, Application::APP_ID, self::PREF_STAGES, '');
		} catch (InvalidArgumentException $e) {
			// Exactly what `getValueString` declares, and no more. A blanket
			// `Throwable` here swallowed a programming error as readily as a
			// malformed identifier and answered "you set no stages" either
			// way, which is the shape ServiceCatchReturnsNullTest counts.
			$this->logger->warning('Dossiq: the personal stages could not be read: ' . $e->getMessage());
			$raw = '';
		}

		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$stages = [];
		foreach ($decoded as $caseId => $stage) {
			if (is_string($caseId) === true && is_string($stage) === true && trim($stage) !== '') {
				$stages[$caseId] = $stage;
			}
		}

		return $stages;
	}//end all()

	/**
	 * Set, or clear, this person's stage on one case.
	 *
	 * @param string $userId The reader.
	 * @param string $caseId The case.
	 * @param string $stage  The stage, or an empty string to clear it.
	 *
	 * @return string The stage now stored.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function set(string $userId, string $caseId, string $stage): string {
		$stage = trim($stage);
		if (mb_strlen($stage) > self::MAX_LENGTH) {
			$stage = mb_substr($stage, 0, self::MAX_LENGTH);
		}

		$stages = $this->all(userId: $userId);
		unset($stages[$caseId]);
		if ($stage !== '') {
			$stages[$caseId] = $stage;
		}

		if (count($stages) > self::MAX_STAGES) {
			$stages = array_slice($stages, (count($stages) - self::MAX_STAGES), null, true);
		}

		$this->userConfig->setValueString(
			$userId,
			Application::APP_ID,
			self::PREF_STAGES,
			json_encode($stages, JSON_THROW_ON_ERROR)
		);

		return $stage;
	}//end set()
}//end class
