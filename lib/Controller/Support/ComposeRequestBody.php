<?php

/**
 * Dossiq compose request body.
 *
 * The body of a request to compose a decision from case data, and what it
 * says without a store: the template it names, and the fields it sets on the
 * new decision (the addressee overrides and the decision fields).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T05
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

/**
 * What a compose request names: its template and the fields it sets.
 *
 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T05
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ComposeRequestBody {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $body The decoded request body.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $body,
	) {
	}//end __construct()

	/**
	 * The template the request names, or null.
	 *
	 * @return string|null The template id.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T05
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function templateId(): ?string {
		$templateId = null;
		if (isset($this->body['templateId']) === true) {
			$templateId = (string)$this->body['templateId'];
		}

		return $templateId;
	}//end templateId()

	/**
	 * The fields the request sets on the decision: the addressee overrides and the decision fields.
	 *
	 * @return array<string, mixed> The fields to merge.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T05
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function fields(): array {
		$overrides = (array)($this->body['addressee'] ?? []);
		$payload = (array)$this->body;

		$merged = [];
		if ($overrides !== []) {
			$merged['addressee'] = $overrides;
		}

		foreach (['decisionType', 'rationale', 'decision'] as $field) {
			if (isset($payload[$field]) === true) {
				$merged[$field] = $payload[$field];
			}
		}

		return $merged;
	}//end fields()
}//end class
