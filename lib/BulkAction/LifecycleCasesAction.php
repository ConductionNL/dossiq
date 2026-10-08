<?php

/**
 * Dossiq bulk action: suspend, resume or extend the term of many cases.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category BulkAction
 * @package  OCA\Dossiq\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BulkAction;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
use Throwable;

/**
 * One statutory lifecycle gesture, over every case the job selected.
 *
 * Suspending, resuming and extending are acts under the Awb (4:5 and 4:14)
 * that somebody has to account for later, and doing four hundred at once is
 * exactly when the reason goes unwritten. So the action requires a
 * justification, which OpenRegister stores on the job.
 *
 * ⚠️ The same text also travels in `parameters.reason`, because `apply()` is
 * handed the parameters and not the job: the per-case write needs the reason
 * and has no other way to reach it. The job's justification is the record; the
 * parameter is what the write reads.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `BulkActionResult` is a value object
 * whose constructor is PRIVATE: `applied()`, `skipped()`, `refused()` and
 * `failed()` are its only constructors, and they are static by OpenRegister's
 * design so the four outcomes read as four named things rather than as four
 * flags. There is no instance to call, so the rule cannot be satisfied here.
 */
class LifecycleCasesAction implements BulkActionInterface {

	use ReadsCaseObject;

	/**
	 * The action id every caller names.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public const ID = 'dossiq:lifecycle-cases';

	/**
	 * The gestures this action offers, and the `state()` flag that says whether
	 * a given case allows each one.
	 *
	 * A transition is deliberately absent: it goes through the status engine,
	 * which is the only write path for `case.status`.
	 *
	 * @var array<string, string>
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	private const GESTURES = [
		'suspend' => 'canSuspend',
		'resume' => 'canResume',
		'extend' => 'canExtend',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseLifecycleService $lifecycle The suspend/resume/extend gestures.
	 * @param IL10N                $l10n      Localisation, for the label an operator reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseLifecycleService $lifecycle,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Suspend, resume or extend the term');
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Applies one statutory lifecycle gesture to every selected case, with the reason recorded on each.');
	}//end getDescription()

	/**
	 * A statutory act somebody accounts for later needs a written reason.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function requiresJustification(): bool {
		return true;
	}//end requiresJustification()

	/**
	 * A gesture is refused per case by that case's own state, so no guard runs
	 * over the selection as a whole.
	 *
	 * @return array<int, string> No guards.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * A known gesture and a non-empty reason are required.
	 *
	 * @param array<string, mixed> $parameters The job parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the gesture is unknown or the reason is empty.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$gesture = trim((string)($parameters['gesture'] ?? ''));
		if (array_key_exists($gesture, self::GESTURES) === false) {
			throw new InvalidArgumentException('gesture must be one of suspend, resume, extend');
		}

		if (trim((string)($parameters['reason'] ?? '')) === '') {
			throw new InvalidArgumentException('reason is required');
		}
	}//end validateParameters()

	/**
	 * Apply the gesture to one case, or say whether it would apply.
	 *
	 * @param ObjectEntity         $object     The case the job is walking.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The lifecycle service
	 * reads the acting user from the session; the job runs as that user.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$caseId = $this->caseId(object: $object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		$gesture = trim((string)($parameters['gesture'] ?? ''));

		try {
			$state = $this->lifecycle->state(caseId: $caseId);
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}

		if (($state[self::GESTURES[$gesture] ?? ''] ?? false) !== true) {
			return BulkActionResult::skipped(reason: $this->refusalCode(gesture: $gesture, state: $state));
		}

		if ($commit === false) {
			return BulkActionResult::applied();
		}

		return $this->write(caseId: $caseId, gesture: $gesture, parameters: $parameters);
	}//end apply()

	/**
	 * Perform the gesture the job asked for.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $gesture    One of suspend, resume, extend.
	 * @param array<string, mixed> $parameters The job parameters.
	 *
	 * @return BulkActionResult What happened.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	private function write(string $caseId, string $gesture, array $parameters): BulkActionResult {
		$reason = trim((string)($parameters['reason'] ?? ''));

		try {
			match ($gesture) {
				'suspend' => $this->lifecycle->suspend(
					caseId: $caseId,
					reason: $reason,
					days: (int)($parameters['days'] ?? 0),
				),
				'resume' => $this->lifecycle->resume(caseId: $caseId, reason: $reason),
				default => $this->lifecycle->extend(
					caseId: $caseId,
					reason: $reason,
					newEndDate: (string)($parameters['newEndDate'] ?? ''),
				),
			};

			return BulkActionResult::applied();
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}//end try
	}//end write()

	/**
	 * Say why a case will not take the gesture, in the same codes the single
	 * case page uses.
	 *
	 * @param string               $gesture The gesture asked for.
	 * @param array<string, mixed> $state   The case's lifecycle state.
	 *
	 * @return string The refusal code.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	private function refusalCode(string $gesture, array $state): string {
		if (($state['isFinalStatus'] ?? false) === true && $gesture !== 'resume') {
			return 'case_closed';
		}

		if ($gesture === 'suspend' && ($state['suspended'] ?? false) === true) {
			return 'already_suspended';
		}

		return match ($gesture) {
			'suspend' => 'suspension_not_allowed',
			'resume' => 'not_suspended',
			default => 'extension_not_allowed',
		};
	}//end refusalCode()
}//end class
