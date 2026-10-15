<?php

/**
 * The case types that have a Nextcloud Forms form bound to them.
 *
 * Lists them for OpenRegister's question which forms write into the case
 * schema, and takes a binding off when a schema change breaks it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The stored Forms bindings of the case types.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */
class IntakeFormBindings {

	/**
	 * How many case types one read lists.
	 *
	 * @var int
	 */
	private const PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param IntakeFormBinding $binding         Reads a case type's own Forms binding.
	 * @param LoggerInterface   $logger          Logger.
	 * @param CaseFieldWriter   $writer          Writes one field of a stored object.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntakeFormBinding $binding,
		private readonly LoggerInterface $logger,
		private readonly CaseFieldWriter $writer = new CaseFieldWriter(),
	) {
	}//end __construct()

	/**
	 * Every published case type that has a Forms form bound to it.
	 *
	 * @return array<int, array<string, mixed>> The case types.
	 *
	 * @throws RuntimeException When the case types cannot be read: a failed read is not "no forms".
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function boundCaseTypes(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('The case type register is not configured.');
		}

		$found = $objectService->searchObjects(['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::PAGE]);
		if (is_array($found) === false) {
			throw new RuntimeException('The case types could not be listed.');
		}

		$rows = $this->rowsOf(found: $found);
		if (count($rows) >= self::PAGE) {
			$this->logger->warning('Dossiq intake: more case types than one read lists; forms bound to the rest are not judged', ['listed' => self::PAGE]);
		}

		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => ($row['isDraft'] ?? false) !== true && $this->binding->formRef(caseType: $row) !== ''
			)
		);
	}//end boundCaseTypes()

	/**
	 * The rows of a search answer, each as an array.
	 *
	 * @param array<mixed> $found The answer: a list, or `{results: list}`.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(array $found): array {
		if (is_array($found['results'] ?? null) === true) {
			$found = $found['results'];
		}

		$rows = [];
		foreach ($found as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Take a case type's Forms binding off, so the form opens no more cases.
	 *
	 * The mapping stays on the case type: binding the form again is one field.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the case type cannot be written, so OpenRegister reports the failure.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function unbind(string $caseTypeId): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseTypeId === '') {
			throw new RuntimeException('The intake form of case type ' . $caseTypeId . ' could not be taken off.');
		}

		$this->writer->write(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			case: ['id' => $caseTypeId],
			changes: [IntakeFormBinding::FORM_REF => '']
		);
	}//end unbind()
}//end class
