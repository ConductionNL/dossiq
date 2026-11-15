<?php

/**
 * Zgw Rule Values Test
 *
 * The small values the ZGW rules report with: an archive-procedure field
 * names its full path and whether it is filled, a document link names
 * itself the way a refusal quotes it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ArchiveProcedureField;
use OCA\Dossiq\Service\Zgw\DocumentLink;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ArchiveProcedureField
 * @covers \OCA\Dossiq\Service\Zgw\DocumentLink
 */
class ZgwRuleValuesTest extends TestCase {

	public function testAnArchiveProcedureFieldNamesItsFullPath(): void {
		$field = new ArchiveProcedureField(name: 'datumkenmerk', value: 'einddatum');

		self::assertSame('brondatumArchiefprocedure.datumkenmerk', $field->path());
		self::assertSame('datumkenmerk', $field->name);
		self::assertSame('einddatum', $field->value);
	}

	public function testAnArchiveProcedureFieldIsFilledOnlyWithAValue(): void {
		self::assertTrue((new ArchiveProcedureField(name: 'objecttype', value: 'zaak'))->isFilled());
		self::assertTrue((new ArchiveProcedureField(name: 'objecttype', value: '0'))->isFilled(), '"0" is a value');
		self::assertFalse((new ArchiveProcedureField(name: 'objecttype', value: ''))->isFilled());
	}

	public function testADocumentLinkNamesItselfAsARefusalQuotesIt(): void {
		$link = new DocumentLink(objectType: 'besluit', objectUuid: 'b-1', documentUuid: 'd-2');

		self::assertSame('besluit b-1 with document d-2', $link->label());
		self::assertSame('b-1', $link->objectUuid);
		self::assertSame('d-2', $link->documentUuid);
	}
}//end class
