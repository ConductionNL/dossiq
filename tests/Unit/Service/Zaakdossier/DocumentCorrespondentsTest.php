<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules that decide which party a submitted correspondent names, and
 * which correspondent a direction lets a document carry.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use PHPUnit\Framework\TestCase;

class DocumentCorrespondentsTest extends TestCase {

	/**
	 * The parties of the case every case below is about.
	 *
	 * Jan carries a party uuid and an address. The council carries a party
	 * uuid and no address. Ouder is a link written BEFORE the party model:
	 * a contact uid and no party uuid, which is the shape on every case
	 * older than dossiq#2849.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const PARTIES = [
		['partyUuid' => 'party-jan', 'displayName' => 'Jan Jansen', 'email' => 'jan@example.org'],
		['partyUuid' => 'party-council', 'displayName' => 'Gemeente Utrecht', 'email' => ''],
		['contactUid' => 'uid-ouder', 'displayName' => 'Ouder Link', 'email' => 'ouder@example.org'],
	];

	/** @var DocumentCorrespondents The rules under test. */
	private DocumentCorrespondents $rules;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->rules = new DocumentCorrespondents();
	}//end setUp()

	/**
	 * 🔴 THE TEST THIS CHANGE EXISTS FOR. A typed name is not a correspondent.
	 *
	 * The register has carried `dispatch.contactPersonName` since the ZGW
	 * import and nothing ever wrote it, because a typed name cannot be
	 * counted and goes stale the day the party is corrected. The same words
	 * that name a party on screen must resolve to nothing; the party's own
	 * uuid must resolve to the party.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testATypedNameIsDroppedAndTheSamePartysUuidIsKept(): void {
		$typed = $this->rules->resolve(
			sender: 'Jan Jansen',
			recipients: [],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_INCOMING,
		);
		$this->assertSame(expected: '', actual: $typed['sender']);

		$byUuid = $this->rules->resolve(
			sender: 'party-jan',
			recipients: [],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_INCOMING,
		);
		$this->assertSame(expected: 'party-jan', actual: $byUuid['sender']);
	}//end testATypedNameIsDroppedAndTheSamePartysUuidIsKept()

	/**
	 * A party that is not on this case is not a correspondent of it either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testAPartyOfAnotherCaseIsDropped(): void {
		$resolved = $this->rules->resolve(
			sender: 'party-stranger',
			recipients: ['party-stranger'],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_INTERNAL,
		);
		$this->assertSame(expected: '', actual: $resolved['sender']);
		$this->assertSame(expected: [], actual: $resolved['recipients']);
	}//end testAPartyOfAnotherCaseIsDropped()

	/**
	 * The mail intake's path: an address resolves to the party holding it,
	 * whatever the casing, and an address nobody holds resolves to nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testAnAddressResolvesToThePartyHoldingItWhateverTheCasing(): void {
		$this->assertSame(
			expected: 'party-jan',
			actual: $this->rules->partyHolding(address: 'JAN@Example.org', parties: self::PARTIES),
		);
		$this->assertSame(
			expected: 'party-jan',
			actual: $this->rules->resolve(
				sender: '  jan@example.org ',
				recipients: [],
				parties: self::PARTIES,
				direction: DocumentCorrespondents::DIRECTION_INCOMING,
			)['sender'],
		);
		$this->assertSame(
			expected: '',
			actual: $this->rules->partyHolding(address: 'stranger@example.org', parties: self::PARTIES),
		);
	}//end testAnAddressResolvesToThePartyHoldingItWhateverTheCasing()

	/**
	 * A link written before the party model is still a party of the case,
	 * and it is stored under its contact uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testALinkWithoutAPartyUuidResolvesToItsContactUid(): void {
		$this->assertSame(
			expected: 'uid-ouder',
			actual: $this->rules->partyHolding(address: 'ouder@example.org', parties: self::PARTIES),
		);
		$this->assertSame(
			expected: 'uid-ouder',
			actual: $this->rules->identifierOf(party: ['contactUid' => 'uid-ouder']),
		);
	}//end testALinkWithoutAPartyUuidResolvesToItsContactUid()

	/**
	 * An outgoing document names addressees and no sender; an incoming one
	 * names a sender and no addressees. The contradiction is dropped, not
	 * thrown: these arrive on an upload, and a throw loses the file with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-012-direction-decides-which-correspondent-a-document-may-carry
	 */
	public function testASenderOnAnOutgoingDocumentIsDroppedAndTheRecipientsAreKept(): void {
		$outgoing = $this->rules->resolve(
			sender: 'party-jan',
			recipients: ['party-council'],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_OUTGOING,
		);
		$this->assertSame(expected: '', actual: $outgoing['sender']);
		$this->assertSame(expected: ['party-council'], actual: $outgoing['recipients']);

		$incoming = $this->rules->resolve(
			sender: 'party-jan',
			recipients: ['party-council'],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_INCOMING,
		);
		$this->assertSame(expected: 'party-jan', actual: $incoming['sender']);
		$this->assertSame(expected: [], actual: $incoming['recipients']);

		$internal = $this->rules->resolve(
			sender: 'party-jan',
			recipients: ['party-council'],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_INTERNAL,
		);
		$this->assertSame(expected: 'party-jan', actual: $internal['sender']);
		$this->assertSame(expected: ['party-council'], actual: $internal['recipients']);
	}//end testASenderOnAnOutgoingDocumentIsDroppedAndTheRecipientsAreKept()

	/**
	 * A direction nobody recognises allows both, because the schema's default
	 * is internal and a document with an unreadable direction must not
	 * silently lose the correspondent a handler just typed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-012-direction-decides-which-correspondent-a-document-may-carry
	 */
	public function testAnUnknownDirectionAllowsBoth(): void {
		$this->assertSame(
			expected: ['sender' => true, 'recipients' => true],
			actual: $this->rules->allowedFor(direction: 'sideways'),
		);
		$this->assertSame(
			expected: ['sender' => false, 'recipients' => true],
			actual: $this->rules->allowedFor(direction: ' outgoing '),
		);
	}//end testAnUnknownDirectionAllowsBoth()

	/**
	 * The picker hands back the option OBJECT it was given, and a recipients
	 * list arrives as one value as often as a list. Both are read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function testAnOptionObjectAndASingleValueAreBothRead(): void {
		$object = $this->rules->resolve(
			sender: null,
			recipients: [['id' => 'party-council', 'label' => 'Gemeente Utrecht']],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_OUTGOING,
		);
		$this->assertSame(expected: ['party-council'], actual: $object['recipients']);

		$single = $this->rules->resolve(
			sender: null,
			recipients: 'party-jan',
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_OUTGOING,
		);
		$this->assertSame(expected: ['party-jan'], actual: $single['recipients']);

		$oneObject = $this->rules->resolve(
			sender: ['partyUuid' => 'party-jan'],
			recipients: [],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_INCOMING,
		);
		$this->assertSame(expected: 'party-jan', actual: $oneObject['sender']);
	}//end testAnOptionObjectAndASingleValueAreBothRead()

	/**
	 * The same addressee named twice is one addressee.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testTheSameAddresseeNamedTwiceIsStoredOnce(): void {
		$resolved = $this->rules->resolve(
			sender: null,
			recipients: ['party-jan', 'jan@example.org', 'party-jan'],
			parties: self::PARTIES,
			direction: DocumentCorrespondents::DIRECTION_OUTGOING,
		);
		$this->assertSame(expected: ['party-jan'], actual: $resolved['recipients']);
	}//end testTheSameAddresseeNamedTwiceIsStoredOnce()

	/**
	 * A stored correspondent shows the party's name, and an identifier the
	 * case no longer knows shows itself rather than a blank cell.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-015-the-correspondents-are-on-screen-and-can-be-filtered
	 */
	public function testAStoredCorrespondentIsNamedAndAnUnknownOneIsNotBlank(): void {
		$this->assertSame(
			expected: 'Jan Jansen',
			actual: $this->rules->nameOf(identifier: 'party-jan', parties: self::PARTIES),
		);
		$this->assertSame(
			expected: 'party-gone',
			actual: $this->rules->nameOf(identifier: 'party-gone', parties: self::PARTIES),
		);
		$this->assertSame(
			expected: '',
			actual: $this->rules->nameOf(identifier: '  ', parties: self::PARTIES),
		);
	}//end testAStoredCorrespondentIsNamedAndAnUnknownOneIsNotBlank()
}//end class
