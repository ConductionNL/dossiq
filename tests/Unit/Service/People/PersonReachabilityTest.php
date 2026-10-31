<?php

/**
 * DqContact "Bereikbaar via": the portal mailbox and the newest contact, for a
 * user who may read the person.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\PersonReachability;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\TrustedRevealRead;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\People\PersonReachability
 */
class PersonReachabilityTest extends TestCase {

	/**
	 * The portaliq double the last service was built with.
	 *
	 * @var object
	 */
	private object $presence;

	/**
	 * The service over these rows and this portaliq answer.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows   Rows per schema.
	 * @param array<string, mixed>                             $answer portaliq's answer.
	 *
	 * @return PersonReachability The service.
	 */
	private function reachability(array $rows, array $answer): PersonReachability {
		$objects = new class ($rows) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->rows[$schema] ?? [];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturn('dossiq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf(str_replace('message box', 'berichtenbox', $text), $args));
		$presence = new class ($answer) {
			/**
			 * @param array<string, mixed> $answer The answer.
			 */
			public function __construct(public array $answer) {
			}

			/**
			 * The BSNs portaliq was asked about.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * @param string $bsn The BSN.
			 *
			 * @return array<string, mixed>
			 */
			public function forCitizen(string $bsn): array {
				$this->asked[] = $bsn;
				return $bsn === '123457609' ? $this->answer : ['allowed' => true, 'hasAccount' => false, 'portalName' => ''];
			}
		};

		$this->presence = $presence;

		return new PersonReachability($settings, $l10n, new TrustedRevealRead(), $presence);
	}//end reachability()

	/**
	 * A person with a portal account and a contact moment.
	 *
	 * @return void
	 */
	public function testThePortalMailboxAndTheLastContact(): void {
		$service = $this->reachability(
			rows: [
				'brpPerson' => [['id' => 'p-1', 'citizenServiceNumber' => '123457609']],
				'contactmoment' => [['startTime' => '2026-10-04T10:52:00+02:00', 'notificationChannel' => 'webformulier']],
			],
			answer: ['allowed' => true, 'hasAccount' => true, 'portalName' => 'Mijn Zuiddrecht']
		);

		$this->assertSame(
			['portal' => 'Mijn Zuiddrecht, berichtenbox', 'lastContactAt' => '2026-10-04T10:52:00+02:00', 'lastContactChannel' => 'webformulier'],
			$service->forPerson(personId: 'p-1')
		);
	}//end testThePortalMailboxAndTheLastContact()

	/**
	 * No portal account, or a refused question, gives no mailbox line.
	 *
	 * @return void
	 */
	public function testNoAccountNoLine(): void {
		$rows = ['brpPerson' => [['id' => 'p-1', 'citizenServiceNumber' => '123457609']]];

		$this->assertSame('', $this->reachability(rows: $rows, answer: ['allowed' => true, 'hasAccount' => false, 'portalName' => 'Mijn Zuiddrecht'])->forPerson(personId: 'p-1')['portal']);
		$this->assertSame('', $this->reachability(rows: $rows, answer: ['allowed' => false, 'hasAccount' => true, 'portalName' => 'Mijn Zuiddrecht'])->forPerson(personId: 'p-1')['portal']);
	}//end testNoAccountNoLine()

	/**
	 * A person the user may not read gives no answer.
	 *
	 * @return void
	 */
	public function testAnUnreadablePersonGivesNothing(): void {
		$this->assertNull($this->reachability(rows: [], answer: [])->forPerson(personId: 'p-1'));
	}//end testAnUnreadablePersonGivesNothing()

	/**
	 * portaliq answers "has a portal account" for any BSN, so it is asked only
	 * about a person the user's own read returned: a person the user may not
	 * read is never put to it, and a readable one is asked about once.
	 *
	 * @return void
	 */
	public function testPortaliqIsAskedOnlyAboutAPersonTheUserMayRead(): void {
		$this->reachability(rows: ['contactmoment' => [['startTime' => '2026-10-04T10:52:00+02:00']]], answer: [])->forPerson(personId: 'p-1');
		$this->assertSame([], $this->presence->asked);

		$this->reachability(rows: ['brpPerson' => [['id' => 'p-1', 'citizenServiceNumber' => '123457609']]], answer: [])->forPerson(personId: 'p-1');
		$this->assertSame(['123457609'], $this->presence->asked);
	}//end testPortaliqIsAskedOnlyAboutAPersonTheUserMayRead()

	/**
	 * A register that cannot be read is not "not found": the failure reaches
	 * the controller, and portaliq is not asked.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsNotNotFound(): void {
		$service = $this->reachability(rows: ['brpPerson' => [['id' => 'p-1', 'citizenServiceNumber' => '123457609']]], answer: []);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new class {
			/**
			 * @param mixed ...$arguments Ignored.
			 *
			 * @return never
			 */
			public function searchObjectsBySlug(mixed ...$arguments): never {
				throw new \RuntimeException('register unreachable');
			}
		});
		$settings->method('getConfigValue')->willReturn('dossiq');
		$service = new PersonReachability($settings, $this->createMock(IL10N::class), new TrustedRevealRead(), $this->presence);

		$this->expectException(\RuntimeException::class);
		try {
			$service->forPerson(personId: 'p-1');
		} finally {
			$this->assertSame([], $this->presence->asked);
		}
	}//end testAnUnreadableRegisterIsNotNotFound()
}//end class
