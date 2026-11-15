<?php

/**
 * OpenRegister FormDestinationValidator stub.
 *
 * Declaration-only mirror of OpenRegister's validator of a form mapping
 * against its destination schema. dossiq resolves it by class name at
 * runtime; the unit tests mock it. StubApiDriftTest keeps it in line.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCP\IL10N;

/**
 * Judges a form mapping against its destination schema.
 */
class FormDestinationValidator {

	public const REQUIRED_UNMAPPED = 'required-unmapped';

	public const PROPERTY_UNKNOWN = 'property-unknown';

	/**
	 * Constructor.
	 *
	 * @param IL10N          $l10n  Translations.
	 * @param FormFieldRules $rules Field rules.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly FormFieldRules $rules,
	) {
	}//end __construct()

	/**
	 * The findings for a mapping into a schema.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 * @param Schema               $schema  The destination.
	 * @param array<string, mixed> $options `audience` and such.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public function validate(array $mapping, Schema $schema, array $options = []): array {
		return [];
	}//end validate()
}//end class
