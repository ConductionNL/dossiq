<?php

/**
 * Dossiq review configuration: what a case type says about reviewing its documents.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Reads the `documentReview` block of a case's case type.
 *
 * The document review is one capability for every case type. A case type
 * switches it on and configures it here: the review depth per document type
 * today. A case whose type says nothing gets the defaults, every page.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
 */
class ReviewConfiguration {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The case type's `documentReview` block for a case; empty when the case or its type says nothing.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The block.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
	 */
	public function forCase(string $caseId): array {
		$case = $this->row(schemaKey: 'case_schema', id: $caseId);
		$type = ($case['caseType'] ?? '');
		if (is_array($type) === true) {
			$type = ($type['id'] ?? ($type['uuid'] ?? ''));
		}

		$block = ($this->row(schemaKey: 'case_type_schema', id: (string)$type)['documentReview'] ?? []);
		if (is_array($block) === false) {
			return [];
		}

		return $block;
	}//end forCase()

	/**
	 * The review depth by document type for a case; empty means every page for every type.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The depth by document type.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
	 */
	public function reviewDepth(string $caseId): array {
		$depth = ($this->forCase(caseId: $caseId)['reviewDepth'] ?? []);
		if (is_array($depth) === false) {
			return [];
		}

		return $depth;
	}//end reviewDepth()

	/**
	 * One row of a configured schema, or empty.
	 *
	 * @param string $schemaKey The app config key of the schema.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $schemaKey, string $id): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '' || $id === '') {
			return [];
		}

		return ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id) ?? []);
	}//end row()
}//end class
