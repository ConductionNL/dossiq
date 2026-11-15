<?php

/**
 * Reachability at its edges: no BSN, no portaliq, a failing portaliq, no register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use RuntimeException;

/**
 * Tests for PersonReachability at its edges.
 *
 * @covers \OCA\Dossiq\Service\People\PersonReachability
 * @uses \OCA\Dossiq\Service\Support\TrustedRevealRead
 */
class PersonReachabilityEdgeTest extends TestCase {

	/**
	 * A settings service over rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows     Rows per schema.
	 * @param string                                           $register The configured register.
	 * @param bool                                             $noOr     Whether OpenRegister is absent.
	 *
	 * @return SettingsService The double.
	 */
	private function settings(array $rows, string $register = 'dossiq', bool $noOr = false): SettingsService {
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
				return ($this->rows[$schema] ?? []);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noOr === true ? null : $objects);
		$settings->method('getConfigValue')->willReturn($register);

		return $settings;
	}//end settings()

	/**
	 * An IL10N double that formats like the real one.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return $l10n;
	}//end l10n()

	/**
	 * A person without a BSN is never put to portaliq and gets no portal line.
	 *
	 * @return void
	 */
	public function testAPersonWithoutABsnIsNotPutToPortaliq(): void {
		$presence = new class {
			/**
			 * Times asked.
			 *
			 * @var int
			 */
			public int $asked = 0;

			/**
			 * @param string $bsn The BSN.
			 *
			 * @return array<string, mixed>
			 */
			public function forCitizen(string $bsn): array {
				$this->asked++;
				return ['allowed' => true, 'hasAccount' => true, 'portalName' => 'Portal'];
			}
		};
		$service = new PersonReachability($this->settings(rows: ['brpPerson' => [['id' => 'p-1']]]), $this->l10n(), new TrustedRevealRead(), $presence);

		self::assertSame(['portal' => '', 'lastContactAt' => '', 'lastContactChannel' => ''], $service->forPerson(personId: 'p-1'));
		self::assertSame(0, $presence->asked);
	}//end testAPersonWithoutABsnIsNotPutToPortaliq()

	/**
	 * A presence service without forCitizen gives no portal line.
	 *
	 * @return void
	 */
	public function testAPresenceServiceWithoutTheQuestionGivesNoLine(): void {
		$service = new PersonReachability(
			$this->settings(rows: ['brpPerson' => [['citizenServiceNumber' => '123457609']]]),
			$this->l10n(),
			new TrustedRevealRead(),
			new \stdClass()
		);

		self::assertSame('', $service->forPerson(personId: 'p-1')['portal']);
	}//end testAPresenceServiceWithoutTheQuestionGivesNoLine()

	/**
	 * Without portaliq installed there is no portal line, and the contact moment still shows.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqThereIsNoPortalLine(): void {
		self::assertFalse(class_exists(PersonReachability::PRESENCE_CLASS), 'portaliq is not installed in the unit suite.');
		$service = new PersonReachability(
			$this->settings(rows: [
				'brpPerson' => [['citizenServiceNumber' => '123457609']],
				'contactmoment' => [['startTime' => '2026-10-01T09:00:00+02:00', 'notificationChannel' => 'telefoon']],
			]),
			$this->l10n(),
			new TrustedRevealRead()
		);

		self::assertSame(
			['portal' => '', 'lastContactAt' => '2026-10-01T09:00:00+02:00', 'lastContactChannel' => 'telefoon'],
			$service->forPerson(personId: 'p-1')
		);
	}//end testWithoutPortaliqThereIsNoPortalLine()

	/**
	 * A presence service that throws gives no portal line instead of failing the answer.
	 *
	 * @return void
	 */
	public function testAFailingPresenceServiceGivesNoLine(): void {
		$presence = new class {
			/**
			 * @param string $bsn The BSN.
			 *
			 * @return never
			 */
			public function forCitizen(string $bsn): never {
				throw new RuntimeException('portaliq down');
			}
		};
		$service = new PersonReachability($this->settings(rows: ['brpPerson' => [['citizenServiceNumber' => '123457609']]]), $this->l10n(), new TrustedRevealRead(), $presence);

		self::assertSame('', $service->forPerson(personId: 'p-1')['portal']);
	}//end testAFailingPresenceServiceGivesNoLine()

	/**
	 * An account without a portal name gives no line.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutAPortalNameGivesNoLine(): void {
		$presence = new class {
			/**
			 * @param string $bsn The BSN.
			 *
			 * @return array<string, mixed>
			 */
			public function forCitizen(string $bsn): array {
				return ['allowed' => true, 'hasAccount' => true, 'portalName' => '  '];
			}
		};
		$service = new PersonReachability($this->settings(rows: ['brpPerson' => [['citizenServiceNumber' => '123457609']]]), $this->l10n(), new TrustedRevealRead(), $presence);

		self::assertSame('', $service->forPerson(personId: 'p-1')['portal']);
	}//end testAnAccountWithoutAPortalNameGivesNoLine()

	/**
	 * No OpenRegister, or no configured register, reads no person.
	 *
	 * @return void
	 */
	public function testNoRegisterReadsNoPerson(): void {
		$rows = ['brpPerson' => [['citizenServiceNumber' => '123457609']]];

		self::assertNull((new PersonReachability($this->settings(rows: $rows, noOr: true), $this->l10n(), new TrustedRevealRead(), new \stdClass()))->forPerson(personId: 'p-1'));
		self::assertNull((new PersonReachability($this->settings(rows: $rows, register: ''), $this->l10n(), new TrustedRevealRead(), new \stdClass()))->forPerson(personId: 'p-1'));
	}//end testNoRegisterReadsNoPerson()
}//end class
