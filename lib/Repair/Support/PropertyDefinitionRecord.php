<?php

/**
 * One propertyDefinition record, as a repair step writes it.
 *
 * Split out of {@see \OCA\Dossiq\Repair\MigrateSubsidieRegelingToCaseType},
 * which keeps the reads and the saves. This class reads and writes nothing: it
 * names the property, the case type it belongs to, its type and its default,
 * and an enum property carries the values it allows. An enum stored without
 * them would keep its value and lose its constraint.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

/**
 * Builds a propertyDefinition record.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PropertyDefinitionRecord {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $enumValues The values an `enum` property allows.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly array $enumValues,
	) {
	}//end __construct()

	/**
	 * One propertyDefinition record.
	 *
	 * @param string $property   The property name.
	 * @param string $type       The property type.
	 * @param string $caseTypeId The case type it belongs to.
	 * @param mixed  $value      The source's value, the default.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function record(string $property, string $type, string $caseTypeId, mixed $value): array {
		$definition = [
			'name' => $property,
			'caseType' => $caseTypeId,
			'propertyType' => $type,
			'defaultValue' => (string) $value,
		];

		if ($type === 'enum') {
			$definition['enumValues'] = $this->enumValues;
		}

		return $definition;
	}//end record()
}//end class
