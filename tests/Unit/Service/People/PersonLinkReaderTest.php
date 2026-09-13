<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The people linked to a case, read from OpenRegister.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
class PersonLinkReaderTest extends TestCase {

	/**
	 * The settings, which resolve OpenRegister's contact service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Build the reader on a doubled settings service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
	}//end setUp()

	/**
	 * The reader with a contact service that answers this listing.
	 *
	 * @param mixed $listing What getContactsForObject returns, or an exception to throw.
	 *
	 * @return PersonLinkReader The reader.
	 */
	private function readerAnswering(mixed $listing): PersonLinkReader {
		$people = new class($listing) {
			/**
			 * @param mixed $listing The listing or the exception.
			 */
			public function __construct(private mixed $listing) {
			}

			/**
			 * The people on an object.
			 *
			 * @param string $objectUuid The object.
			 *
			 * @return mixed The listing.
			 */
			public function getContactsForObject(string $objectUuid): mixed {
				if ($this->listing instanceof \Throwable) {
					throw $this->listing;
				}

				return $this->listing;
			}
		};
		$this->settings->method('getOpenRegisterClass')->willReturn($people);

		return new PersonLinkReader(settingsService: $this->settings);
	}//end readerAnswering()

	/**
	 * The listing's rows come back, and anything that is not a row is dropped.
	 *
	 * @return void
	 */
	public function testThePeopleOfACaseAreItsLinkRows(): void {
		$reader = $this->readerAnswering(
			listing: [
				'results' => [
					['contactUid' => 'user:jan', 'displayName' => 'Jan de Vries', 'email' => 'jan@example.nl'],
					'not a row',
					['contactUid' => 'contact-8', 'displayName' => 'Piet'],
				],
				'total' => 2,
			]
		);

		$people = $reader->peopleOn(caseId: 'case-1');

		$this->assertCount(expectedCount: 2, haystack: $people);
		$this->assertSame(expected: 'user:jan', actual: $people[0]['contactUid']);
	}//end testThePeopleOfACaseAreItsLinkRows()

	/**
	 * One person by their uid, and null for somebody who is not on the case.
	 *
	 * @return void
	 */
	public function testOnePersonIsFoundByTheirUid(): void {
		$reader = $this->readerAnswering(
			listing: ['results' => [['contactUid' => 'user:jan', 'displayName' => 'Jan de Vries']]]
		);

		$this->assertSame(
			expected: 'Jan de Vries',
			actual: $reader->personOn(caseId: 'case-1', personUid: 'user:jan')['displayName'],
		);
		$this->assertNull(actual: $reader->personOn(caseId: 'case-1', personUid: 'user:piet'));
	}//end testOnePersonIsFoundByTheirUid()

	/**
	 * Without OpenRegister there are no people, and no exception either.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereAreNoPeople(): void {
		$this->settings->method('getOpenRegisterClass')->willReturn(null);
		$reader = new PersonLinkReader(settingsService: $this->settings);

		$this->assertSame(expected: [], actual: $reader->peopleOn(caseId: 'case-1'));
		$this->assertNull(actual: $reader->personOn(caseId: 'case-1', personUid: 'user:jan'));
	}//end testWithoutOpenRegisterThereAreNoPeople()

	/**
	 * A contact service that throws leaves the caller with an empty list.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsAnEmptyList(): void {
		$reader = $this->readerAnswering(listing: new RuntimeException('OpenRegister said no'));

		$this->assertSame(expected: [], actual: $reader->peopleOn(caseId: 'case-1'));
	}//end testAFailingLookupIsAnEmptyList()

	/**
	 * A listing in a shape the reader does not know is empty rather than fatal.
	 *
	 * @return void
	 */
	public function testAnUnknownListingShapeIsEmpty(): void {
		$this->assertSame(
			expected: [],
			actual: $this->readerAnswering(listing: ['items' => [['contactUid' => 'user:jan']]])->peopleOn(caseId: 'case-1'),
		);
	}//end testAnUnknownListingShapeIsEmpty()

	/**
	 * The address and the name a link carries, and what stands in when it has neither.
	 *
	 * @return void
	 */
	public function testTheAddressAndTheNameOfALink(): void {
		$reader = $this->readerAnswering(listing: ['results' => []]);

		$this->assertSame(expected: 'jan@example.nl', actual: $reader->emailOf(link: ['email' => ' jan@example.nl ']));
		$this->assertSame(expected: '', actual: $reader->emailOf(link: []));
		$this->assertSame(expected: 'Jan de Vries', actual: $reader->nameOf(link: ['displayName' => 'Jan de Vries']));
		// No name: the uid is what the handler can still act on.
		$this->assertSame(expected: 'user:jan', actual: $reader->nameOf(link: ['contactUid' => 'user:jan']));
		$this->assertSame(expected: '', actual: $reader->nameOf(link: []));
	}//end testTheAddressAndTheNameOfALink()
}//end class
