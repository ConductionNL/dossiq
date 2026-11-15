<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\DecisionAdvice
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionAdvice;

use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use Throwable;

/**
 * Reads and writes decision advice in OpenRegister.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */
class DecisionAdviceStore {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings OpenRegister and the schema configuration.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Read stored advice.
	 *
	 * @param string $adviceId The advice.
	 *
	 * @return array<string, mixed> The advice.
	 *
	 * @throws DecisionAdviceRefusal When it cannot be found.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function find(string $adviceId): array {
		[$objectService, $register, $schema] = $this->store();
		try {
			$found = $objectService->find($adviceId, register: $register, schema: $schema);
		} catch (Throwable) {
			$found = null;
		}

		$advice = $this->toArray(value: $found);
		if ($advice === [] || trim((string)($advice['case'] ?? '')) === '') {
			throw new DecisionAdviceRefusal(message: 'Dit advies bestaat niet', status: Http::STATUS_NOT_FOUND);
		}

		return $advice;
	}//end find()

	/**
	 * Write the advice.
	 *
	 * @param array<string, mixed> $advice The advice.
	 *
	 * @return array<string, mixed> The advice as stored.
	 *
	 * @throws DecisionAdviceRefusal When OpenRegister refuses it.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	public function save(array $advice): array {
		[$objectService, $register, $schema] = $this->store();
		try {
			$saved = $objectService->saveObject(register: $register, schema: $schema, object: $advice);
		} catch (Throwable) {
			throw new DecisionAdviceRefusal('Het advies kon niet worden opgeslagen', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->toArray(value: $saved);
	}//end save()

	/**
	 * The object service with the advice register and schema.
	 *
	 * @return array{0: object, 1: string, 2: string} Service, register, schema.
	 *
	 * @throws DecisionAdviceRefusal When OpenRegister or the configuration is absent.
	 */
	private function store(): array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('decision_advice_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw new DecisionAdviceRefusal('OpenRegister is niet beschikbaar', Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return [$objectService, $register, $schema];
	}//end store()

	/**
	 * An OpenRegister answer as an array.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return array<string, mixed> The array, or [] when it is not one.
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end toArray()
}//end class
