<?php

/**
 * The case a DSO intake record becomes.
 *
 * Pins DsoIntakeCasePayload::payloadFor: the payload's keys in order, the
 * title and its fallbacks, the procedure type from the record's own or its
 * raw request's activities, the start day and received moment, the optional
 * description and initial status, the journal entry, and the refusal when no
 * mapped case type resolves.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\Dso\DsoIntakeCasePayload;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Dso\DsoIntakeCasePayload
 */
class DsoIntakeCasePayloadTest extends TestCase {

	/**
	 * The journal entries appended.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * A full record: every field, in order.
	 *
	 * @return void
	 */
	public function testAFullRecordBecomesTheWholeCase(): void {
		$case = $this->payload(initialStatus: 'st-1')->payloadFor('va-1', [
			'mappedCaseTypes' => [['reference' => ' unknown '], 'ct-ref'],
			'mappedTitle' => ' Dakkapel ',
			'mappedSummary' => ' Een dakkapel aan de achterzijde ',
			'submissionDate' => '2026-09-01T10:15:00+02:00',
			'rawRequest' => ['activiteiten' => [['regelkwalificatie' => 'uitgebreide']]],
		]);

		self::assertSame(['title', 'caseType', 'dsoStatus', 'procedureType', 'permitApplicationRef', 'startDate', 'receivedAt', 'description', 'status'], array_slice(array_keys($case), 0, 9));
		self::assertSame('Omgevingsvergunning: Dakkapel', $case['title']);
		self::assertSame('ct-uuid', $case['caseType']);
		self::assertSame('submitted', $case['dsoStatus']);
		self::assertSame('uitgebreide', $case['procedureType']);
		self::assertSame('va-1', $case['permitApplicationRef']);
		self::assertSame('2026-09-01', $case['startDate']);
		self::assertSame('Een dakkapel aan de achterzijde', $case['description']);
		self::assertSame('st-1', $case['status']);
		self::assertSame([['type' => 'dsoIntake', 'note' => 'Zaak aangemaakt vanuit DSO vergunningaanvraag va-1.']], $this->entries);
	}//end testAFullRecordBecomesTheWholeCase()

	/**
	 * A bare record: the fallbacks and no optional fields.
	 *
	 * @return void
	 */
	public function testABareRecordFallsBack(): void {
		$case = $this->payload(initialStatus: '')->payloadFor('va-2', ['caseType' => 'ct-ref', 'title' => ' ', 'indieningsdatum' => 'gisteren', 'activiteiten' => [['regelkwalificatie' => 'regulier']]]);

		self::assertSame('Omgevingsvergunning: va-2', $case['title']);
		self::assertSame('reguliere', $case['procedureType']);
		self::assertSame(date('Y-m-d'), $case['startDate']);
		self::assertArrayNotHasKey('receivedAt', $case);
		self::assertArrayNotHasKey('description', $case);
		self::assertArrayNotHasKey('status', $case);

		self::assertSame('Omgevingsvergunning: Eigen titel', $this->payload(initialStatus: '')->payloadFor('va-3', ['caseType' => 'ct-ref', 'title' => 'Eigen titel'])['title']);
	}//end testABareRecordFallsBack()

	/**
	 * No mapped case type resolves: refused, naming the references.
	 *
	 * @return void
	 */
	public function testARecordWithoutAResolvingCaseTypeIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No dossiq case type answers to the activity mapping of vergunningaanvraag va-4 (mappedCaseTypes: nope, also-nope)');

		$this->payload(initialStatus: '')->payloadFor('va-4', ['mappedCaseTypes' => ['nope'], 'caseType' => ' also-nope ']);
	}//end testARecordWithoutAResolvingCaseTypeIsRefused()

	/**
	 * The payload builder over a resolver that knows only `ct-ref`.
	 *
	 * @param string $initialStatus The case type's initial status.
	 *
	 * @return DsoIntakeCasePayload
	 */
	private function payload(string $initialStatus): DsoIntakeCasePayload {
		$caseTypes = $this->createMock(CaseTypeReferenceResolver::class);
		$caseTypes->method('resolve')->willReturnCallback(static fn (string $reference): array => ($reference === 'ct-ref' ? ['id' => 'ct-uuid'] : []));
		$caseTypes->method('idOf')->willReturn('ct-uuid');
		$caseTypes->method('initialStatusOf')->willReturn($initialStatus);

		$journal = $this->createMock(CaseJournal::class);
		$journal->method('append')->willReturnCallback(
			function (array $case, array $entry): array {
				$this->entries[] = $entry;
				return $case;
			}
		);

		return new DsoIntakeCasePayload(caseTypes: $caseTypes, journal: $journal);
	}//end payload()
}//end class
