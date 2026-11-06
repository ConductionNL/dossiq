<?php

/**
 * Dossiq sensitive answer check
 *
 * Whether a case answers one of its case type's sensitive fields. Those
 * answers live in `case.sensitiveProperties`, which only dossiq-sensitive
 * reads, so the person moving a case usually cannot see them. A flow guard
 * still has to know whether the asset test was done; this reads the case as
 * the system and answers yes or no, never the answer itself.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;

/**
 * Answers whether a case answers a sensitive field, read as the system.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class SensitiveAnswerCheck {
	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settings Register, case schema and OpenRegister.
	 * @param UnscopedObjectReader $reader   Reads the case as the system.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly UnscopedObjectReader $reader = new UnscopedObjectReader(),
	) {
	}//end __construct()

	/**
	 * Whether the case holds a non-empty sensitive answer under this name.
	 *
	 * Fails closed: a case that cannot be read answers no, so the guard
	 * refuses rather than letting the move through.
	 *
	 * @param string $caseId The case.
	 * @param string $name   The field's name.
	 *
	 * @return bool True when answered.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isAnswered(string $caseId, string $name): bool {
		$objectService = $this->settings->getObjectService();
		if ($caseId === '' || $name === '' || $objectService === null) {
			return false;
		}

		$case = $this->reader->find(
			objectService: $objectService,
			register: (string)$this->settings->getConfigValue('register'),
			schema: (string)$this->settings->getConfigValue('case_schema'),
			id: $caseId
		);
		foreach ((array)($case['sensitiveProperties'] ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['name'] ?? '') === $name && trim((string)($entry['value'] ?? '')) !== '') {
				return true;
			}
		}

		return false;
	}//end isAnswered()
}//end class
