<?php

/**
 * DRC rule for changing a locked document
 *
 * A locked enkelvoudiginformatieobject is changed only with its own lock id
 * (drc-006): without one the change is refused as missing the lock, with
 * another as the wrong lock. An unlocked document needs none.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Drc-006: the refusal of a change to a locked document without its own lock id.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DrcLockRule {

	/**
	 * Constructor.
	 *
	 * @param ZgwRuleResults $results Builds the answers of a rule.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwRuleResults $results,
	) {
	}//end __construct()

	/**
	 * Validate lock requirement for document modifications (drc-006).
	 *
	 * @param array      $body           The request body
	 * @param array|null $existingObject The existing EIO data
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function refusal(array $body, ?array $existingObject): ?array {
		// Check if the document is locked.
		$existingLock = $this->currentLock(existingObject: $existingObject);
		if ($existingLock === '' || $existingLock === false) {
			return null;
		}

		// Drc-006: Require valid lock ID.
		return $this->lockRefusal(requestLock: $body['lock'] ?? '', existingLock: $existingLock);
	}//end refusal()

	/**
	 * The lock a stored document carries, '' when there is no stored document.
	 *
	 * @param array|null $existingObject The existing EIO data
	 *
	 * @return mixed The lock id, '' or false when unlocked
	 */
	private function currentLock(?array $existingObject): mixed {
		if ($existingObject === null) {
			return '';
		}

		return $existingObject['locked'] ?? ($existingObject['lock'] ?? '');
	}//end currentLock()

	/**
	 * Drc-006: the refusal of a modification of a locked document without its own lock id.
	 *
	 * @param mixed $requestLock  The lock id the request carries, '' when none
	 * @param mixed $existingLock The lock id the document carries
	 *
	 * @return array|null Validation error, or null when the lock ids match
	 */
	private function lockRefusal(mixed $requestLock, mixed $existingLock): ?array {
		if ($requestLock === '') {
			return $this->results->fieldRefusal(
				fieldName: 'lock',
				code: 'required',
				detail: 'Het document is vergrendeld. Geef een geldig lock ID mee.',
				reason: 'Het document is vergrendeld.'
			);
		}

		if ($requestLock !== $existingLock) {
			return $this->results->fieldRefusal(
				fieldName: 'lock',
				code: 'incorrect-lock-id',
				detail: 'Het opgegeven lock ID is niet geldig.',
				reason: 'Het opgegeven lock ID komt niet overeen.'
			);
		}

		return null;
	}//end lockRefusal()
}//end class
