<?php

/**
 * The write that marks a case as worked on.
 *
 * `lastActivityAt` is when someone last did something on the case: moved its
 * status, wrote a note, logged a contact, added a document or completed a
 * task. It is not `@self.updated`, which every save moves, including the
 * nightly rematerialisation and an example-set refresh. A stalled case is one
 * nobody worked on, so it is measured from this field.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Activity
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Activity;

use DateTimeInterface;
use OCA\Dossiq\Kernel\Contracts\CaseActivityStampInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes `lastActivityAt` onto a case.
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */
class CaseActivityStamp implements CaseActivityStampInterface {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the register and the case schema.
	 * @param ITimeFactory    $time            The clock.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mark the case as worked on now.
	 *
	 * An id that is not a case is no error: a note or a task can hang on any
	 * object, and only a case has an activity date.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return bool True when the case was stamped.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity
	 */
	public function stamp(string $caseId): bool {
		$caseId = trim($caseId);
		$target = $this->stampTarget();
		if ($caseId === '' || $target === null) {
			return false;
		}

		return $this->writeStamp(target: $target, caseId: $caseId);
	}//end stamp()

	/**
	 * The object service, register and case schema, or null when one is unavailable.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The stamp target.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity
	 */
	private function stampTarget(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema = (string)$this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end stampTarget()

	/**
	 * Stamp the case, logging a failed write.
	 *
	 * @param array{0: object, 1: string, 2: string} $target The object service, register and case schema.
	 * @param string                                 $caseId The case uuid.
	 *
	 * @return bool True when the case was stamped.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity
	 */
	private function writeStamp(array $target, string $caseId): bool {
		try {
			return $this->stampIfCase(target: $target, caseId: $caseId);
		} catch (Throwable $e) {
			// The work itself is saved; a case whose activity date lags only
			// sorts a little too far up the stalled list until its next act.
			$this->logger->warning(
				'Dossiq: the case activity date could not be written',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			return false;
		}
	}//end writeStamp()

	/**
	 * Write the activity date onto the case, when the id names a case.
	 *
	 * @param array{0: object, 1: string, 2: string} $target The object service, register and case schema.
	 * @param string                                 $caseId The case uuid.
	 *
	 * @return bool True when the case was stamped, false when the id is no case.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity
	 */
	private function stampIfCase(array $target, string $caseId): bool {
		[$objectService, $register, $schema] = $target;
		$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
		if ($case === null) {
			return false;
		}

		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
			changes: [self::FIELD => $this->time->now()->format(DateTimeInterface::ATOM)]
		);

		return true;
	}//end stampIfCase()
}//end class
