<?php

/**
 * A filed message names the party it came from, not just the address.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\EmailArchivalService;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\EmailArchivalService
 * @uses \OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents
 */
class InboundSenderIsAPartyTest extends TestCase {
	private const PARTIES = [
		['partyUuid' => 'party-jan', 'displayName' => 'Jan Jansen', 'email' => 'jan@example.org'],
	];

	/** @var object The stub object service, recording what was filed. */
	private object $objects;

	/** @var EmailArchivalService The service under test. */
	private EmailArchivalService $archival;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> Every object saved. */
			public array $saves = [];

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$this->saves[] = $object;
				return ['id' => 'archival-' . count($this->saves)] + $object;
			}

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> Nothing is stored yet.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return [];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_document_schema' => 'caseDocument',
			][$key] ?? $default
		);

		$people = $this->createMock(originalClassName: PersonLinkReader::class);
		$people->method('peopleOn')->willReturnCallback(
			static function (string $caseId): array {
				if ($caseId === 'case-1') {
					return self::PARTIES;
				}

				return [];
			}
		);

		$this->archival = new EmailArchivalService(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			correspondents: new CorrespondentWriter(
				rules: new DocumentCorrespondents(),
				people: $people,
				settingsService: $settings,
				store: new DocumentRecordStore(settingsService: $settings),
			),
		);
	}//end setUp()

	/**
	 * A message from an address a party of the case holds names that party,
	 * whatever the header's casing and display name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testAFiledMessageNamesThePartyItCameFrom(): void {
		$this->archival->archiveLinkedEmail(
			caseId: 'case-1',
			metadata: ['from' => 'JAN@example.org', 'subject' => 'Aanvulling'],
		);

		$filed = $this->objects->saves[0];
		$this->assertSame(expected: 'party-jan', actual: $filed['sender']);
		$this->assertSame(expected: 'incoming', actual: $filed['direction']);
		// The raw address stays: it is what the message actually said, and
		// the party is what somebody can open.
		$this->assertSame(expected: 'JAN@example.org', actual: $filed['from']);
	}//end testAFiledMessageNamesThePartyItCameFrom()

	/**
	 * 🔴 An address nobody on the case holds leaves the sender empty. The
	 * intake does NOT create a party for every stranger who mails the case,
	 * which is how a People tab fills up with one row per newsletter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testAnAddressNobodyHoldsLeavesTheSenderEmpty(): void {
		$this->archival->archiveLinkedEmail(
			caseId: 'case-1',
			metadata: ['from' => 'nieuwsbrief@example.com'],
		);

		$this->assertSame(expected: '', actual: $this->objects->saves[0]['sender']);
		$this->assertSame(expected: 'incoming', actual: $this->objects->saves[0]['direction']);
	}//end testAnAddressNobodyHoldsLeavesTheSenderEmpty()

	/**
	 * The parties of another case are not this case's correspondents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testThePartiesOfAnotherCaseDoNotAnswer(): void {
		$this->archival->archiveLinkedEmail(
			caseId: 'case-other',
			metadata: ['from' => 'jan@example.org'],
		);

		$this->assertSame(expected: '', actual: $this->objects->saves[0]['sender']);
	}//end testThePartiesOfAnotherCaseDoNotAnswer()
}//end class
