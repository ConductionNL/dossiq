<?php

/**
 * A letter an action files names the party it went to.
 *
 * 🔴 THE HANDLER'S OWN TESTS ASSERT AN EMPTY ADDRESSEE, because their
 * container registers no CorrespondentWriter. That is the right assertion for
 * them and it is exactly the assertion that cannot catch the interesting
 * failure: a handler that never asks for an addressee passes it too. So this
 * file registers a real writer over a case that HAS parties, and asserts the
 * party reaches uploadDocument.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\CreateDocumentHandler;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Actions\AddressesTheCase
 * @uses \OCA\Dossiq\Service\Actions\CreateDocumentHandler
 * @uses \OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents
 */
class LetterNamesItsAddresseeTest extends TestCase {

	/**
	 * A handler over a container that answers with both services.
	 *
	 * @param ZaakdossierService $dossier The dossier service double.
	 * @param array<int, array<string, mixed>> $parties The parties of the case.
	 *
	 * @return CreateDocumentHandler The handler.
	 */
	private function handler(ZaakdossierService $dossier, array $parties): CreateDocumentHandler {
		$people = $this->createMock(originalClassName: PersonLinkReader::class);
		$people->method('peopleOn')->willReturn($parties);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$writer = new CorrespondentWriter(
			rules: new DocumentCorrespondents(),
			people: $people,
			settingsService: $settings,
			store: new DocumentRecordStore(settingsService: $settings),
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				ZaakdossierService::class => $dossier,
				CorrespondentWriter::class => $writer,
				default => throw new \RuntimeException('not registered: ' . $id),
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getDisplayName')->willReturn('Ruben');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CreateDocumentHandler(
			container: $container,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end handler()

	/**
	 * Run the action over a case with the given parties and answer with the
	 * metadata the dossier was handed.
	 *
	 * @param array<int, array<string, mixed>> $parties The parties of the case.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private function fileALetter(array $parties): array {
		$captured = [];
		$dossier = $this->createMock(originalClassName: ZaakdossierService::class);
		$dossier->method('uploadDocument')->willReturnCallback(
			static function (string $caseId, string $name, string $body, array $metadata) use (&$captured): array {
				$captured = $metadata;
				return ['id' => 'doc-1'];
			}
		);

		$result = $this->handler(dossier: $dossier, parties: $parties)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Beste lezer',
				'outputName' => 'besluit.md',
				'documentType' => 'besluit',
			],
			case: ['id' => 'case-1', 'title' => 'ZK-1'],
			transitionContext: [],
		);
		$this->assertTrue(condition: $result->succeeded);

		return $captured;
	}//end fileALetter()

	/**
	 * The addressee named on the case is the one the letter goes to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testTheLetterGoesToThePartyNamedAddressee(): void {
		$metadata = $this->fileALetter(parties: [
			['partyUuid' => 'party-jan', 'role' => 'aanvrager'],
			['partyUuid' => 'party-council', 'role' => 'geadresseerde'],
		]);

		$this->assertSame(expected: ['party-council'], actual: $metadata['recipients']);
		$this->assertSame(expected: 'outgoing', actual: $metadata['direction']);
	}//end testTheLetterGoesToThePartyNamedAddressee()

	/**
	 * With no addressee named, the letter goes to the requester.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testWithNoAddresseeTheLetterGoesToTheRequester(): void {
		$metadata = $this->fileALetter(parties: [
			['partyUuid' => 'party-jan', 'role' => 'aanvrager'],
			['partyUuid' => 'party-neighbour', 'role' => 'belanghebbende'],
		]);

		$this->assertSame(expected: ['party-jan'], actual: $metadata['recipients']);
	}//end testWithNoAddresseeTheLetterGoesToTheRequester()

	/**
	 * A case whose links predate the generic roles calls the requester the
	 * initiator, and a letter still reaches them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testAnInitiatorLinkStillReceivesTheLetter(): void {
		$metadata = $this->fileALetter(parties: [
			['contactUid' => 'uid-old', 'role' => 'initiator'],
		]);

		$this->assertSame(expected: ['uid-old'], actual: $metadata['recipients']);
	}//end testAnInitiatorLinkStillReceivesTheLetter()

	/**
	 * 🔴 A case with nobody on it still gets its letter. The letter is the
	 * act; the addressee can be filled in on the document's properties, and a
	 * letter that was never filed cannot be.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testACaseWithNoPartiesStillGetsItsLetter(): void {
		$metadata = $this->fileALetter(parties: []);

		$this->assertSame(expected: [], actual: $metadata['recipients']);
	}//end testACaseWithNoPartiesStillGetsItsLetter()
}//end class
