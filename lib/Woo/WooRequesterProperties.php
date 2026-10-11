<?php

/**
 * Dossiq Woo Requester Properties (site-woo-request-in-steps)
 *
 * The requester details a Woo request carries, written as answers to the
 * questions the case type itself asks. The request keeps what the resident
 * filled in at the time; the case's own `properties` bag is where a handler
 * reads it on the case screen, beside the question. Both, because they answer
 * different questions, and neither is derived from the other.
 *
 * Its own class rather than a method on {@see WooRequestIntake}: that class is
 * at the complexity phpmd refuses, and this is a read of the case type that
 * has nothing to do with writing a case.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The requester details as entries of a case's `properties` bag.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
 */
class WooRequesterProperties {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the propertyDefinition schema.
	 * @param LoggerInterface $logger          Says when the definitions cannot be read.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The entries for one case type, or [] when there are none to write.
	 *
	 * A definition the type does not declare is skipped rather than invented,
	 * and a read that fails costs the entries and not the request: the same
	 * answers are kept on `wooRequest` either way.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseTypeId    The case type whose questions these answer.
	 * @param array<string, mixed> $wooRequest    The normalised request.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-keeps-what-kind-of-documents-and-which-requester-req-sws-010
	 */
	public function forCaseType(object $objectService, string $register, string $caseTypeId, array $wooRequest): array {
		$answers = $this->answers(wooRequest: $wooRequest);
		if ($answers === []) {
			return [];
		}

		$definitions = $this->definitions(objectService: $objectService, register: $register, caseTypeId: $caseTypeId);

		$entries = [];
		foreach ($answers as $field => $value) {
			if (isset($definitions[$field]) === false) {
				continue;
			}

			$entries[] = ['propertyDefinition' => $definitions[$field], 'name' => $field, 'value' => $value];
		}

		return $entries;
	}//end forCaseType()

	/**
	 * The requester answers that were given, by field name.
	 *
	 * @param array<string, mixed> $wooRequest The normalised request.
	 *
	 * @return array<string, string> The answers.
	 */
	private function answers(array $wooRequest): array {
		$answers = [];
		foreach (WooRequestForm::REQUESTER_FIELDS as $field) {
			$value = trim((string)($wooRequest[$field] ?? ''));
			if ($value !== '') {
				$answers[$field] = $value;
			}
		}

		return $answers;
	}//end answers()

	/**
	 * The case type's property definitions, as id by name.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseTypeId    The case type.
	 *
	 * @return array<string, string> The definition id per name.
	 */
	private function definitions(object $objectService, string $register, string $caseTypeId): array {
		$schema = (string)$this->settingsService->getConfigValue('property_definition_schema');
		if ($schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WooRequestIntake: the case type property definitions could not be read, so the requester details are kept on the request only',
				['error' => $e->getMessage()]
			);
			return [];
		}

		$definitions = [];
		foreach ($rows as $row) {
			$name = trim((string)($row['name'] ?? ''));
			$id = $this->idOfRow(row: $row);
			if ($name !== '' && $id !== '') {
				$definitions[$name] = $id;
			}
		}

		return $definitions;
	}//end definitions()

	/**
	 * One row's id, wherever the store put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOfRow(array $row): string {
		foreach ([($row['@self']['id'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $candidate) {
			$id = trim((string)($candidate ?? ''));
			if ($id !== '') {
				return $id;
			}
		}

		return '';
	}//end idOfRow()
}//end class
