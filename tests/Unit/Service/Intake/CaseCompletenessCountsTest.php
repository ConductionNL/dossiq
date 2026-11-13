<?php

/**
 * CaseCompleteness counts and readable-document tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CaseCompleteness;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use PHPUnit\Framework\TestCase;

/**
 * The counts and the verdict, and which documents count as readable.
 *
 * @covers \OCA\Dossiq\Service\Intake\CaseCompleteness
 */
class CaseCompletenessCountsTest extends TestCase {

	/**
	 * The service over mocked readers.
	 *
	 * @return CaseCompleteness
	 */
	private function service(): CaseCompleteness {
		return new CaseCompleteness(
			cases: $this->createMock(CaseStatusStore::class),
			caseTypes: $this->createMock(CaseTypeResolver::class),
			dossier: $this->createMock(ZaakdossierService::class),
			reader: $this->createMock(InformatieobjectReader::class),
		);
	}//end service()

	/**
	 * A readable document is matched by its id, its @self id or its uuid; all present reads complete.
	 *
	 * @return void
	 */
	public function testReadableDocumentsAreMatchedByAnyIdAndAllPresentIsComplete(): void {
		$items = [
			['key' => 'a', 'label' => 'A', 'documentType' => 'ta'],
			['key' => 'b', 'label' => 'B', 'documentType' => 'tb'],
			['key' => 'c', 'label' => 'C', 'documentType' => 'tc'],
		];
		$docA = ['id' => 'x1', 'bestandsnaam' => 'a.pdf', 'informatieobjecttype' => 'ta'];
		$docB = ['@self' => ['id' => 'x2'], 'titel' => 'B-brief', 'informatieobjecttype' => 'tb'];
		$docC = ['uuid' => 'x3', 'bestandsnaam' => 'c.pdf', 'informatieobjecttype' => 'tc'];

		$answer = $this->service()->check(
			items: $items,
			case: [],
			documents: [$docA, $docB, $docC],
			readable: [['uuid' => 'x1'], ['id' => 'x2'], ['@self' => ['id' => 'x3']]],
		);

		self::assertSame(['a.pdf', 'B-brief', 'c.pdf'], array_column($answer['items'], 'evidence'));
		self::assertSame(['items', 'present', 'total', 'missing', 'verdict'], array_keys($answer));
		self::assertSame(3, $answer['present']);
		self::assertSame(3, $answer['total']);
		self::assertSame(0, $answer['missing']);
		self::assertSame('complete', $answer['verdict']);
	}//end testReadableDocumentsAreMatchedByAnyIdAndAllPresentIsComplete()

	/**
	 * Nothing present of two reads incomplete with both missing.
	 *
	 * @return void
	 */
	public function testNothingPresentIsIncomplete(): void {
		$answer = $this->service()->check(
			items: [['key' => 'a', 'label' => 'A', 'field' => 'x'], ['key' => 'b', 'label' => 'B']],
			case: ['x' => ''],
			documents: [],
			readable: [],
		);

		self::assertSame(0, $answer['present']);
		self::assertSame(2, $answer['missing']);
		self::assertSame('incomplete', $answer['verdict']);
	}//end testNothingPresentIsIncomplete()
}//end class
