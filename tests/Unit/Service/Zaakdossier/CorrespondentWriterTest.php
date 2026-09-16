<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The writer's OpenRegister calls, against a stub object service that records
 * every dispatch row it was asked to store.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CorrespondentWriterTest extends TestCase {
	private const CONFIG = [
		'register' => 'dossiq',
		'dispatch_schema' => 'dispatch',
	];

	private const PARTIES = [
		['partyUuid' => 'party-jan', 'displayName' => 'Jan Jansen', 'email' => 'jan@example.org'],
		['partyUuid' => 'party-council', 'displayName' => 'Gemeente Utrecht', 'email' => ''],
	];

	/** @var object The stub object service. */
	private object $objects;

	/** @var PersonLinkReader&MockObject The parties reader. */
	private PersonLinkReader&MockObject $people;

	/** @var CorrespondentWriter The writer under test. */
	private CorrespondentWriter $writer;

	/**
	 * A writer over a stub object service and a case with two parties.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> Every dispatch row stored. */
			public array $saves = [];

			/** @var array<int, array<string, mixed>> The rows a search answers. */
			public array $answers = [];

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$fields = array_filter(
					$filters,
					static fn (string $key): bool => str_starts_with($key, '_') === false,
					ARRAY_FILTER_USE_KEY
				);

				return array_values(array_filter(
					$this->answers,
					static function (array $row) use ($fields): bool {
						foreach ($fields as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid on an update.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$this->saves[] = $object;
				$this->answers[] = $object;
				return ['id' => 'dispatch-' . count($this->saves)] + $object;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);

		$this->people = $this->createMock(originalClassName: PersonLinkReader::class);
		$this->people->method('peopleOn')->willReturn(self::PARTIES);

		$this->writer = new CorrespondentWriter(
			rules: new DocumentCorrespondents(),
			people: $this->people,
			settingsService: $settings,
		);
	}//end setUp()

	/**
	 * Two addressees give two rows, each naming one party in the addressee
	 * role, on this document and this case, with the date of the send.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function testTwoAddresseesGiveTwoDispatchRows(): void {
		$written = $this->writer->recordDispatches(
			caseId: 'case-1',
			documentId: 'doc-1',
			correspondents: ['sender' => '', 'recipients' => ['party-jan', 'party-council']],
		);

		$this->assertSame(expected: 2, actual: $written);
		$this->assertCount(expectedCount: 2, haystack: $this->objects->saves);
		foreach ($this->objects->saves as $row) {
			$this->assertSame(expected: 'doc-1', actual: $row['document']);
			$this->assertSame(expected: 'case-1', actual: $row['case']);
			$this->assertSame(expected: 'geadresseerde', actual: $row['relationshipType']);
			$this->assertSame(expected: date('Y-m-d'), actual: $row['sendDate']);
			$this->assertArrayNotHasKey(key: 'receiveDate', array: $row);
			// 🔴 The field this change exists to stop being written.
			$this->assertArrayNotHasKey(key: 'contactPersonName', array: $row);
		}

		$this->assertSame(
			expected: ['party-jan', 'party-council'],
			actual: array_column($this->objects->saves, 'involvedParty'),
		);
	}//end testTwoAddresseesGiveTwoDispatchRows()

	/**
	 * A sender gives a row in the sender role, with a receive date and no
	 * send date: only one of the two acts happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function testASenderGivesARowWithAReceiveDateAndNoSendDate(): void {
		$this->writer->recordDispatches(
			caseId: 'case-1',
			documentId: 'doc-2',
			correspondents: ['sender' => 'party-jan', 'recipients' => []],
		);

		$this->assertCount(expectedCount: 1, haystack: $this->objects->saves);
		$row = $this->objects->saves[0];
		$this->assertSame(expected: 'afzender', actual: $row['relationshipType']);
		$this->assertSame(expected: date('Y-m-d'), actual: $row['receiveDate']);
		$this->assertArrayNotHasKey(key: 'sendDate', array: $row);
	}//end testASenderGivesARowWithAReceiveDateAndNoSendDate()

	/**
	 * Saving the same document twice is a correction, not a second send.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function testTheSameCorrespondentIsNotRecordedTwice(): void {
		$first = $this->writer->recordDispatches(
			caseId: 'case-1',
			documentId: 'doc-3',
			correspondents: ['sender' => '', 'recipients' => ['party-jan']],
		);
		$second = $this->writer->recordDispatches(
			caseId: 'case-1',
			documentId: 'doc-3',
			correspondents: ['sender' => '', 'recipients' => ['party-jan']],
		);

		$this->assertSame(expected: 1, actual: $first);
		$this->assertSame(expected: 0, actual: $second);
		$this->assertCount(expectedCount: 1, haystack: $this->objects->saves);
	}//end testTheSameCorrespondentIsNotRecordedTwice()

	/**
	 * A document with no correspondents writes nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function testNoCorrespondentsWritesNothing(): void {
		$this->assertSame(
			expected: 0,
			actual: $this->writer->recordDispatches(
				caseId: 'case-1',
				documentId: 'doc-4',
				correspondents: ['sender' => '', 'recipients' => []],
			),
		);
		$this->assertSame(
			expected: 0,
			actual: $this->writer->recordDispatches(
				caseId: 'case-1',
				documentId: '',
				correspondents: ['sender' => 'party-jan', 'recipients' => []],
			),
		);
		$this->assertSame(expected: [], actual: $this->objects->saves);
	}//end testNoCorrespondentsWritesNothing()

	/**
	 * The writer resolves against the case's own parties, so a typed name is
	 * dropped here too and the mail intake's address finds its party.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testTheWriterResolvesAgainstTheCasesOwnParties(): void {
		$resolved = $this->writer->resolveFor(
			caseId: 'case-1',
			sender: 'Jan Jansen',
			recipients: ['party-council'],
			direction: DocumentCorrespondents::DIRECTION_INTERNAL,
		);
		$this->assertSame(expected: '', actual: $resolved['sender']);
		$this->assertSame(expected: ['party-council'], actual: $resolved['recipients']);

		$this->assertSame(
			expected: 'party-jan',
			actual: $this->writer->partyHolding(caseId: 'case-1', address: 'Jan@example.org'),
		);
		$this->assertSame(
			expected: '',
			actual: $this->writer->partyHolding(caseId: 'case-1', address: '   '),
		);
	}//end testTheWriterResolvesAgainstTheCasesOwnParties()
}//end class
