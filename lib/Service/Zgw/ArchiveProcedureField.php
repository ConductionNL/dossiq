<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * One field of a resultaattype's brondatumArchiefprocedure and the value its
 * presence is judged on.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ArchiveProcedureField {
	/**
	 * Constructor.
	 *
	 * @param string $name  The field, e.g. `datumkenmerk`.
	 * @param string $value The value, '' when absent.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $value,
	) {
	}//end __construct()

	/**
	 * The full field path, for error reporting.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function path(): string {
		return 'brondatumArchiefprocedure.'.$this->name;
	}//end path()

	/**
	 * Whether the field carries a value.
	 *
	 * @return bool True when the value is not empty.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function isFilled(): bool {
		return $this->value !== '';
	}//end isFilled()
}//end class
