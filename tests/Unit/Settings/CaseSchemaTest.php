<?php

/**
 * The case schema declares the references a case carried before dossiq, and
 * declares them searchable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register the way the runtime merges it.
 *
 * @coversNothing
 */
class CaseSchemaTest extends TestCase {

	/**
	 * `formerReferences` is a list of `{application, reference}`, a search
	 * declares it, and an imported case carrying one fits the schema.
	 *
	 * @return void
	 */
	public function testFormerReferencesIsDeclaredAndSearchable(): void {
		$real = new RealSchemaValidator();
		$property = $real->schemas['case']['properties']['formerReferences'] ?? null;

		self::assertIsArray($property, 'the case schema declares formerReferences');
		self::assertSame('array', $property['type']);
		self::assertSame('object', $property['items']['type']);
		self::assertSame(['application', 'reference'], array_keys($property['items']['properties']));
		self::assertSame('exact', $property['matchType'] ?? null, 'a former reference is searchable');
		self::assertSame('text', $property['inputControl'] ?? null);

		$case = [
			'title' => 'Alle adviezen over de Stationsweg 2025',
			'caseType' => '3c0f5a00-0000-4000-a000-00000000a001',
			'formerReferences' => [['application' => 'opencatalogi', 'reference' => 'WOO-2026-A1B2C3']],
		];
		self::assertSame([], $real->errors(slug: 'case', payload: $case));
		self::assertNotSame([], $real->errors(slug: 'case', payload: ['formerReferences' => 'WOO-2026-A1B2C3'] + $case));
	}//end testFormerReferencesIsDeclaredAndSearchable()
}//end class
