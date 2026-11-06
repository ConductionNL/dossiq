<?php

/**
 * A complaint is a case of the shipped Klacht case type.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/complaints-are-a-case-type/specs/complaint-management/spec.md#requirement-a-complaint-is-a-case-of-the-shipped-complaint-case-type-req-cmp-ct-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\Session\SessionDefinitionReader;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The register's Klacht case type: its statuses, results, roles, hearing and the
 * import that carries stored complaint records onto cases.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 * @covers \OCA\Dossiq\Service\Session\SessionDefinitionReader
 * @covers \OCA\Dossiq\Service\Session\SessionDefinition
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/complaints-are-a-case-type/specs/complaint-management/spec.md#requirement-a-complaint-is-a-case-of-the-shipped-complaint-case-type-req-cmp-ct-01
 */
class ComplaintCaseTypeTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The case type's slug and identifier.
	 */
	private const CASE_TYPE = 'klacht-behandeling';

	/**
	 * The register's seed objects, decoded once.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $objects = null;

	/**
	 * The register's seed objects.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objects(): array {
		if (self::$objects === null) {
			$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);
			self::$objects = (array)$register['components']['objects'];
		}

		return self::$objects;
	}//end objects()

	/**
	 * The case type's own rows of one schema, in register order.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		return array_values(
			array_filter(
				$this->objects(),
				static fn (array $row): bool => ($row['@self']['schema'] ?? '') === $schema
					&& ($row['caseType'] ?? '') === '@ref:' . self::CASE_TYPE
			)
		);
	}//end rowsOf()

	/**
	 * The case type row.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(): array {
		foreach ($this->objects() as $row) {
			if (($row['@self']['slug'] ?? '') === self::CASE_TYPE) {
				return $row;
			}
		}

		self::fail(message: 'The register ships no ' . self::CASE_TYPE . ' case type');
	}//end caseType()

	/**
	 * The statuses follow the steps of board DqKlachten, in order, ending in the one final status.
	 *
	 * @return void
	 */
	public function testTheStatusesAreTheStepsOfTheBoard(): void {
		$statuses = $this->rowsOf(schema: 'statusType');
		usort($statuses, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		self::assertSame(
			expected: ['Ontvangen', 'Bevestigd', 'Hoorgesprek', 'Oordeel', 'Antwoordbrief', 'Afgehandeld'],
			actual: array_column($statuses, 'name')
		);
		self::assertSame(expected: [1, 2, 3, 4, 5, 6], actual: array_column($statuses, 'order'));
		$final = array_filter($statuses, static fn (array $row): bool => $row['isFinal'] === true);
		self::assertSame(
			expected: ['klacht-afgehandeld'],
			actual: array_values(array_map(static fn (array $row): string => $row['@self']['slug'], $final))
		);
		self::assertSame(expected: '@ref:klacht-ontvangen', actual: $this->caseType()['initialStatus']);
	}//end testTheStatusesAreTheStepsOfTheBoard()

	/**
	 * The results are the board's four verdicts plus a withdrawal, and the term is the Awb's.
	 *
	 * @return void
	 */
	public function testTheResultsAndTheTermFollowTheAwb(): void {
		self::assertEqualsCanonicalizing(
			expected: ['Klacht gegrond', 'Klacht deels gegrond', 'Klacht ongegrond', 'Klacht niet-ontvankelijk', 'Klacht ingetrokken'],
			actual: array_column($this->rowsOf(schema: 'resultType'), 'name')
		);

		$type = $this->caseType();
		self::assertSame(expected: 'P42D', actual: $type['processingDeadline']);
		self::assertTrue(condition: $type['extensionAllowed']);
		self::assertSame(expected: 'P28D', actual: $type['extensionPeriod']);
		self::assertStringContainsString(needle: 'Awb 9:11 lid 1', haystack: $type['description']);

		$received = new \DateTimeImmutable('2026-03-01');
		self::assertSame(expected: '2026-04-12', actual: $received->add(new \DateInterval($type['processingDeadline']))->format('Y-m-d'));
	}//end testTheResultsAndTheTermFollowTheAwb()

	/**
	 * The hearing is the generic session, inviting only roles the case type declares.
	 *
	 * @return void
	 */
	public function testTheHearingIsADeclaredSession(): void {
		$definitions = (new SessionDefinitionReader())->allOf(caseType: $this->caseType());

		self::assertSame(expected: ['hoorgesprek'], actual: array_keys($definitions));
		$hearing = $definitions['hoorgesprek']->toArray();
		self::assertTrue(condition: $hearing['waivable'], message: 'A complainant may decline to be heard (Awb 9:10 lid 2).');
		self::assertTrue(condition: $hearing['minutesRequired']);

		$roles = array_column($this->rowsOf(schema: 'roleType'), 'name');
		self::assertEqualsCanonicalizing(expected: ['Klager', 'Betrokken medewerker', 'Klachtencoördinator'], actual: $roles);
		foreach ($hearing['inviteeRoles'] as $role) {
			self::assertContains(needle: $role, haystack: $roles);
		}
	}//end testTheHearingIsADeclaredSession()

	/**
	 * Every complaint status lands on a status of the case type, and every reference resolves.
	 *
	 * @return void
	 */
	public function testEveryComplaintStatusLandsOnAKlachtStatus(): void {
		$import = $this->caseType()['recordImports'][0];
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);
		$complaintStatuses = $register['components']['schemas']['complaint']['properties']['status']['enum'];

		self::assertSame(expected: 'complaint', actual: $import['sourceSchema']);
		self::assertTrue(condition: $import['runOnUpgrade']);
		self::assertSame(expected: 'externalReference', actual: $import['matchField']);
		self::assertEqualsCanonicalizing(expected: array_merge($complaintStatuses, ['*']), actual: array_keys($import['statuses']));

		$statusRefs = array_map(static fn (array $row): string => '@ref:' . $row['@self']['slug'], $this->rowsOf(schema: 'statusType'));
		$resultRefs = array_map(static fn (array $row): string => '@ref:' . $row['@self']['slug'], $this->rowsOf(schema: 'resultType'));
		foreach ($import['statuses'] as $declared) {
			self::assertContains(needle: $declared['status'], haystack: $statusRefs);
			if (isset($declared['result']) === true) {
				self::assertContains(needle: $declared['result'], haystack: $resultRefs);
			}
		}
	}//end testEveryComplaintStatusLandsOnAKlachtStatus()

	/**
	 * A stored complaint maps onto one case that names its source.
	 *
	 * @return void
	 */
	public function testAStoredComplaintMapsOntoACase(): void {
		$complaint = [
			'complaintNumber' => 'KL-2026-0041',
			'complainant' => ['name' => 'R. de Boer', 'email' => 'r.deboer@example.nl'],
			'subject' => 'Melding over een losse stoeptegel is na vier weken niet opgepakt',
			'description' => 'De melding is niet opgepakt.',
			'receiptDate' => '2026-10-13',
			'receiptChannel' => 'brief',
			'handler' => 'sanne.mulder',
			'status' => 'withdrawn',
			'afhandelDeadline' => '2026-11-24',
		];

		$mapped = (new RecordCaseMapping(dates: $this->caseDates()))->map(
			import: $this->caseType()['recordImports'][0],
			record: $complaint,
			recordId: 'complaint-1',
			caseTypeId: 'klacht-type-id'
		);

		$case = $mapped['case'];
		self::assertSame(expected: '@ref:klacht-afgehandeld', actual: $case['status']);
		self::assertSame(expected: '@ref:klacht-ingetrokken', actual: $mapped['result']);
		self::assertSame(expected: 'closed', actual: $mapped['term']['state']);
		self::assertSame(expected: '2026-11-24', actual: $mapped['term']['endDate']);
		self::assertSame(expected: $complaint['subject'], actual: $case['title']);
		self::assertSame(expected: '2026-10-13', actual: $case['startDate']);
		self::assertSame(expected: 'R. de Boer', actual: $case['initiatorDisplayName']);
		self::assertSame(expected: 'sanne.mulder', actual: $case['assignee']);
		self::assertSame(expected: 'post', actual: $case['intakeChannel']);
		self::assertSame(expected: 'vertrouwelijk', actual: $case['confidentiality']);
		self::assertSame(expected: 'complaint-1', actual: $case['externalReference']);
	}//end testAStoredComplaintMapsOntoACase()
}//end class
