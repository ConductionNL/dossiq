<?php

/**
 * Dossiq bulk action: write one case attribute across many cases.
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
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IUser;
use RuntimeException;
use Throwable;

/**
 * One attribute, one value, across every case the job selected.
 *
 * This is the action the homogeneity guard exists for. A bulk change to a
 * case-type attribute across two versions of a case type writes a value into a
 * field that means two things in the two versions, and the write is not merely
 * reversible afterwards: it is unreadable. So the action declares
 * `GUARD_HOMOGENEITY` and the engine refuses such a selection before a single
 * case is touched (D-4).
 *
 * dossiq refuses the same selection earlier still, in
 * {@see \OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard}, at selection time and
 * naming the versions. The two are not a duplicate: OpenRegister's guard reads
 * the SCHEMA version an object was written against, dossiq's reads the CASE
 * TYPE version the case runs on, and it is the second that carries the
 * vocabulary a handler is about to overwrite.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `BulkActionResult` is a value object
 * whose constructor is PRIVATE: `applied()`, `skipped()`, `refused()` and
 * `failed()` are its only constructors, and they are static by OpenRegister's
 * design so the four outcomes read as four named things rather than as four
 * flags. There is no instance to call, so the rule cannot be satisfied here.
 */
class SetCaseAttributeAction implements BulkActionInterface {

	use ReadsCaseObject;

	/**
	 * The action id every caller names.
	 *
	 * @var string
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public const ID = 'dossiq:set-case-attribute';

	/**
	 * The properties this action will not write, and why each is out.
	 *
	 * `status` has one write path and it is the transition engine. `assignee`
	 * has one too, and it is the reassignment action, which stamps the audit
	 * entry this action does not. `activity` and `statusHistory` are ledgers
	 * that are appended to, never set. `caseType` would move a case onto
	 * another vocabulary, which is a rebind and not an attribute change.
	 *
	 * @var array<int, string>
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	private const NOT_WRITABLE = [
		'id',
		'status',
		'assignee',
		'activity',
		'statusHistory',
		'caseType',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration and the object service.
	 * @param IL10N           $l10n            Localisation, for the label an operator reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
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
		return $this->l10n->t('Set one field on the cases');
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Writes one value into one field on every selected case, refusing a selection that spans two case type versions.');
	}//end getDescription()

	/**
	 * An attribute change carries its own meaning and needs no separate reason.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * The selection must be one version, or the value means two things.
	 *
	 * @return array<int, string> The homogeneity guard.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function getGuards(): array {
		return [BulkActionInterface::GUARD_HOMOGENEITY];
	}//end getGuards()

	/**
	 * A writable property is required, and a value must be present.
	 *
	 * @param array<string, mixed> $parameters The job parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the property is absent, reserved, or no value is given.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$property = trim((string)($parameters['property'] ?? ''));
		if ($property === '') {
			throw new InvalidArgumentException('property is required');
		}

		if (in_array($property, self::NOT_WRITABLE, true) === true) {
			throw new InvalidArgumentException($property . ' has its own write path and is not set in bulk');
		}

		if (array_key_exists('value', $parameters) === false) {
			throw new InvalidArgumentException('value is required');
		}
	}//end validateParameters()

	/**
	 * Write the value onto one case, or say whether it would change anything.
	 *
	 * @param ObjectEntity         $object     The case the job is walking.
	 * @param array<string, mixed> $parameters The job parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The object service reads
	 * the acting user from the session; the job runs as that user.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$caseId = $this->caseId(object: $object);
		if ($caseId === '') {
			return BulkActionResult::skipped(reason: 'no_case_id');
		}

		$case = $this->caseData(object: $object);
		$property = trim((string)($parameters['property'] ?? ''));
		$value = ($parameters['value'] ?? null);

		if (array_key_exists($property, $case) === true && $case[$property] === $value) {
			return BulkActionResult::skipped(reason: 'already_set');
		}

		if ($commit === false) {
			return BulkActionResult::applied();
		}

		try {
			$case[$property] = $value;
			$this->write(caseId: $caseId, case: $case);

			return BulkActionResult::applied();
		} catch (Throwable $e) {
			return BulkActionResult::failed(message: $e->getMessage());
		}//end try
	}//end apply()

	/**
	 * Store the changed case.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $case   The case, with the new value on it.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister or the case schema is not configured.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	private function write(string $caseId, array $case): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('OpenRegister is not available');
		}

		$objectService->updateObject($register, $schema, $caseId, $case);
	}//end write()
}//end class
