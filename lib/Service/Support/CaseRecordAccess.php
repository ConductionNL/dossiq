<?php

/**
 * Dossiq case record access
 *
 * Reads and writes one case for a change that refuses rather than half-does:
 * a case that cannot be read, found or saved, and a case register that
 * cannot be reached, each end the change with a refusal that says what was
 * not done to the case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * One case read and written back, or a refusal naming what was not done.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class CaseRecordAccess {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service and the case scope.
	 * @param CaseTypeStore   $store           Flattens a stored row.
	 * @param string          $done            What the change does to the case, e.g. "moved".
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $store,
		private readonly string $done,
	) {
	}//end __construct()

	/**
	 * The refusal for a case that could not be found.
	 *
	 * @return RefusedException
	 */
	private function notFound(): RefusedException {
		return new RefusedException(
			rule: 'case-not-found',
			sentence: 'This case could not be found.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end notFound()

	/**
	 * Read one case, refusing rather than answering an empty one.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function read(string $caseId): array {
		[$objectService, $register, $schema] = $this->scope();

		try {
			$found = $objectService->find($caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: 'case-unreadable',
				sentence: 'This case could not be read, so it was not '.$this->done.'.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		$case = $this->store->asRow(value: $found);
		if ($case === []) {
			throw $this->notFound();
		}

		return $case;
	}//end read()

	/**
	 * Write the changed case back.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $case   The changed case.
	 * @param string               $rule   The rule a failed write refuses with.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the write fails.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function write(string $caseId, array $case, string $rule): void {
		$this->writeOrRefuse(caseId: $caseId, write: ['case' => $case, 'rule' => $rule]);
	}//end write()

	/**
	 * Save the case, refusing as indeterminate when it cannot be saved.
	 *
	 * @param string                                          $caseId The case uuid.
	 * @param array{case: array<string, mixed>, rule: string} $write  `case` the changed case; `rule` the rule a failed write refuses with.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the write fails.
	 */
	private function writeOrRefuse(string $caseId, array $write): void {
		[$case, $rule] = [$write['case'], $write['rule']];
		[$objectService] = $this->scope();

		try {
			$objectService->updateObject(objectId: $caseId, data: $case);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: $rule,
				sentence: 'The case was not '.$this->done.', because the change could not be saved.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}
	}//end writeOrRefuse()

	/**
	 * The object service and the case scope, or a refusal.
	 *
	 * @return array{0: object, 1: string, 2: string} The service, register and schema.
	 *
	 * @throws RefusedException When OpenRegister or the case schema is absent.
	 */
	private function scope(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			throw new RefusedException(
				rule: 'case-store-unavailable',
				sentence: 'The case register could not be reached, so nothing was '.$this->done.'.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return [$objectService, $register, $schema];
	}//end scope()
}//end class
