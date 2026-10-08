<?php

/**
 * Dossiq Case Assignment Service.
 *
 * Claim and release: the two gestures that put a case in someone's hands, or
 * give it back to the queue.
 *
 * WHY THE RULE LIVES HERE AND NOT IN THE BROWSER. The case page can hide a
 * button, and the queue can filter its rows, but neither can settle a race:
 * two handlers looking at the same unclaimed case both see Claim. So the rule
 * is read-compare-write against the STORED case, and a claim on a case that
 * already has a handler is refused with a code the controller turns into a
 * 409. The page's gate is a courtesy; this is the decision.
 *
 * The read and the write both run as the signed-in user, so OpenRegister's own
 * RBAC answers "may this person touch this case" (ADR-022, ADR-023). Nothing
 * here duplicates that check; what it adds is the one thing OpenRegister
 * cannot know, which is that a case with a handler is not free to take.
 *
 * Nothing is recorded beyond the field change: the audit trail on the case
 * already carries who changed `assignee`, when, and from what.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Who holds a case, and the two gestures that change that.
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */
class CaseAssignmentService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (OpenRegister access).
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Who holds this case, and what this user may do about it.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId The signed-in user.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The assignment.
	 *
	 * @throws RuntimeException With code `case_not_found` when the case does not resolve for this user.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	public function state(string $caseId, string $userId): array {
		return $this->describe(caseId: $caseId, userId: $userId, assignee: $this->readAssignee(caseId: $caseId));
	}//end state()

	/**
	 * Take this case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId The signed-in user, who becomes the assignee.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The new assignment.
	 *
	 * @throws RuntimeException With code `case_not_found`, `already_yours` or `already_assigned`.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	public function claim(string $caseId, string $userId): array {
		$assignee = $this->readAssignee(caseId: $caseId);
		if ($assignee === $userId) {
			throw new RuntimeException('already_yours');
		}

		if ($assignee !== '') {
			throw new RuntimeException('already_assigned');
		}

		$this->write(caseId: $caseId, assignee: $userId);

		return $this->describe(caseId: $caseId, userId: $userId, assignee: $userId);
	}//end claim()

	/**
	 * Give this case back to the queue.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId The signed-in user, who must be the assignee.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The new assignment.
	 *
	 * @throws RuntimeException With code `case_not_found`, `not_assigned` or `not_yours`.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	public function release(string $caseId, string $userId): array {
		$assignee = $this->readAssignee(caseId: $caseId);
		if ($assignee === '') {
			throw new RuntimeException('not_assigned');
		}

		if ($assignee !== $userId) {
			throw new RuntimeException('not_yours');
		}

		$this->write(caseId: $caseId, assignee: null);

		return $this->describe(caseId: $caseId, userId: $userId, assignee: '');
	}//end release()

	/**
	 * Shape one answer, so the three gestures cannot describe the same case
	 * differently.
	 *
	 * @param string $caseId   The case UUID.
	 * @param string $userId   The signed-in user.
	 * @param string $assignee The handler on the case, empty when nobody holds it.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The assignment.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function describe(string $caseId, string $userId, string $assignee): array {
		return [
			'caseId' => $caseId,
			'assignee' => $assignee,
			'mine' => ($assignee !== '' && $assignee === $userId),
			'claimable' => ($assignee === ''),
			'releasable' => ($assignee !== '' && $assignee === $userId),
		];
	}//end describe()

	/**
	 * Read the handler off the stored case.
	 *
	 * The read runs as the signed-in user, so a case OpenRegister will not show
	 * this person does not resolve, and `case_not_found` is the answer for both
	 * a missing case and a refused read. That is deliberate: a 404 that only
	 * appears for cases you may not see is an existence oracle.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The assignee, or an empty string when nobody holds the case.
	 *
	 * @throws RuntimeException With code `case_not_found`.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function readAssignee(string $caseId): string {
		[$objectService, $register, $schema] = $this->openRegister();

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq CaseAssignmentService: case lookup failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $caseId]
			);
			throw new RuntimeException('case_not_found');
		}

		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		return trim((string)($case['assignee'] ?? ''));
	}//end readAssignee()

	/**
	 * Write the handler onto the stored case, and nothing else.
	 *
	 * A partial write, never a saved snapshot: the case is worked on by more
	 * writers than this one, and saving a whole read-back object would put
	 * every other property back as it stood a moment ago.
	 *
	 * @param string      $caseId   The case UUID.
	 * @param string|null $assignee The new handler, or null to clear the field.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With code `assignment_write_failed`.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function write(string $caseId, ?string $assignee): void {
		[$objectService, $register, $schema] = $this->openRegister();

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: ['assignee' => $assignee]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq CaseAssignmentService: assignment write failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $caseId]
			);
			throw new RuntimeException('assignment_write_failed');
		}
	}//end write()

	/**
	 * The OpenRegister seam and the case schema, or a refusal.
	 *
	 * @return array{0: object, 1: string, 2: string} The object service, register and schema.
	 *
	 * @throws RuntimeException With code `case_not_found` when OpenRegister or the schema is absent.
	 *
	 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	 */
	private function openRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->logger->warning(
				'Dossiq CaseAssignmentService: OpenRegister unavailable',
				['app' => Application::APP_ID]
			);
			throw new RuntimeException('case_not_found');
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq CaseAssignmentService: case schema not configured',
				['app' => Application::APP_ID]
			);
			throw new RuntimeException('case_not_found');
		}

		return [$objectService, $register, $schema];
	}//end openRegister()
}//end class
