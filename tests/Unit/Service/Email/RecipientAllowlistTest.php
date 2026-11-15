<?php

/**
 * Who outbound case mail may be addressed to: configured entries, the sender's domain, the case contacts.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\RecipientAllowlist
 */
class RecipientAllowlistTest extends TestCase {

	/**
	 * An allowlist over the given configured value.
	 *
	 * @param string $configured The raw configured allowlist.
	 *
	 * @return RecipientAllowlist
	 */
	private function allowlist(string $configured): RecipientAllowlist {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->any())
			->method('getValueString')
			->with(Application::APP_ID, RecipientAllowlist::CONFIG_KEY, '')
			->willReturn($configured);

		return new RecipientAllowlist(appConfig: $config);
	}//end allowlist()

	public function testConfiguredEntriesAreSplitLoweredDedupedAndBareDomainsPrefixed(): void {
		$entries = $this->allowlist(configured: " Gemeente.NL, partner.org;Jan@Example.com\n@gemeente.nl  * ")
			->entriesFor(fromAddress: 'noreply@ignored.nl');

		self::assertSame(['@gemeente.nl', '@partner.org', 'jan@example.com', '*'], $entries);
	}//end testConfiguredEntriesAreSplitLoweredDedupedAndBareDomainsPrefixed()

	public function testWithoutConfigurationTheSendersOwnDomainIsTheList(): void {
		self::assertSame(['@gemeente.nl'], $this->allowlist(configured: '  ')->entriesFor(fromAddress: 'Zaken@Gemeente.NL '));
	}//end testWithoutConfigurationTheSendersOwnDomainIsTheList()

	public function testWithoutConfigurationOrAUsableSenderNothingIsAllowed(): void {
		self::assertSame([], $this->allowlist(configured: '')->entriesFor(fromAddress: 'no-at-sign'));
		self::assertSame([], $this->allowlist(configured: '')->entriesFor(fromAddress: 'trailing@'));
	}//end testWithoutConfigurationOrAUsableSenderNothingIsAllowed()

	public function testTheWildcardOpensTheList(): void {
		$allowlist = $this->allowlist(configured: '');

		self::assertTrue($allowlist->isOpen(entries: ['@a.nl', '*']));
		self::assertFalse($allowlist->isOpen(entries: ['@a.nl']));
		self::assertTrue($allowlist->permits(recipient: 'anyone@anywhere.com', entries: ['*'], caseContacts: []));
	}//end testTheWildcardOpensTheList()

	public function testAnEmptyRecipientIsNeverPermittedEvenWhenOpen(): void {
		self::assertFalse($this->allowlist(configured: '')->permits(recipient: '   ', entries: ['*'], caseContacts: ['']));
	}//end testAnEmptyRecipientIsNeverPermittedEvenWhenOpen()

	public function testDomainEntriesMatchTheWholeDomainOnly(): void {
		$allowlist = $this->allowlist(configured: '');
		$entries = ['@gemeente.nl'];

		self::assertTrue($allowlist->permits(recipient: ' Burger@Gemeente.nl ', entries: $entries, caseContacts: []));
		self::assertFalse($allowlist->permits(recipient: 'burger@sub.gemeente.nl', entries: $entries, caseContacts: []));
		self::assertFalse($allowlist->permits(recipient: 'burger@gemeente.nl.evil.com', entries: $entries, caseContacts: []));
		self::assertFalse($allowlist->permits(recipient: 'gemeente.nl', entries: $entries, caseContacts: []));
	}//end testDomainEntriesMatchTheWholeDomainOnly()

	public function testAddressEntriesAndCaseContactsMatchExactly(): void {
		$allowlist = $this->allowlist(configured: '');

		self::assertTrue($allowlist->permits(recipient: 'JAN@example.com', entries: ['jan@example.com'], caseContacts: []));
		self::assertFalse($allowlist->permits(recipient: 'piet@example.com', entries: ['jan@example.com'], caseContacts: []));
		self::assertTrue($allowlist->permits(recipient: 'Aanvrager@Private.org', entries: ['@gemeente.nl'], caseContacts: ['aanvrager@private.org']));
	}//end testAddressEntriesAndCaseContactsMatchExactly()
}//end class
