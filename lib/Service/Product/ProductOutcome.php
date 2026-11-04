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

namespace OCA\Dossiq\Service\Product;

/**
 * One outcome being turned into a product: the store, the case and what its type declares.
 *
 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
 */
final class ProductOutcome {

	/**
	 * Constructor.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $caseObject    The case.
	 * @param array<string, mixed> $declared      The case type's product declaration.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly array $caseObject,
		public readonly array $declared,
	) {
	}//end __construct()

	/**
	 * A case value by name: a top-level field, else a case-type property answer.
	 *
	 * @param string $name The name.
	 *
	 * @return string The value, or ''.
	 *
	 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
	 */
	public function answer(string $name): string {
		if ($name === '') {
			return '';
		}

		if (is_scalar($this->caseObject[$name] ?? null) === true) {
			return trim((string)$this->caseObject[$name]);
		}

		foreach ((array)($this->caseObject['properties'] ?? []) as $answer) {
			if (is_array($answer) === true && ($answer['name'] ?? null) === $name && is_scalar($answer['value'] ?? null) === true) {
				return trim((string)$answer['value']);
			}
		}

		return '';
	}//end answer()

	/**
	 * A case value as the declaration wants it stored in a product field.
	 *
	 * @param string $field The product field.
	 * @param string $name  The case value's name.
	 *
	 * @return string The value; compact fields in capitals without spaces or dashes.
	 *
	 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
	 */
	public function stored(string $field, string $name): string {
		$value = $this->answer(name: $name);
		if (in_array($field, (array)($this->declared['compactFields'] ?? []), true) === true) {
			return strtoupper((string)preg_replace('/[\s-]+/', '', $value));
		}

		return $value;
	}//end stored()
}//end class
