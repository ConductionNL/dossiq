<?php

/**
 * The party kinds and roles the People tab offers.
 *
 * The keys are a stored contract: a role written on a link is one of these
 * keys, and an address is the one kind that brings its own role. Every label
 * and description goes through the app's translator.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\PartyVocabulary;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\People\PartyVocabulary
 */
class PartyVocabularyTest extends TestCase {

	/**
	 * A vocabulary whose translator marks what it translated.
	 *
	 * @return PartyVocabulary
	 */
	private function vocabulary(): PartyVocabulary {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[nl] ' . $text);

		return new PartyVocabulary(l10n: $l10n);
	}//end vocabulary()

	/**
	 * The kinds, in order, with the identity shapes they search and the role an address brings.
	 *
	 * @return void
	 */
	public function testTheKindsAndWhatEachSearches(): void {
		$kinds = $this->vocabulary()->kinds();

		self::assertSame(
			expected: ['person', 'organisation', 'colleague', 'address'],
			actual: array_column($kinds, 'key'),
		);
		self::assertSame(expected: ['naturalPerson'], actual: $kinds[0]['identityShapes']);
		self::assertSame(expected: ['organization'], actual: $kinds[1]['identityShapes']);
		self::assertSame(expected: ['naturalPerson'], actual: $kinds[2]['identityShapes']);
		self::assertArrayNotHasKey(key: 'identityShapes', array: $kinds[3]);
		self::assertSame(expected: [PartyVocabulary::ROLE_LOCATION], actual: $kinds[3]['roles']);
		self::assertSame(expected: '[nl] Organisation', actual: $kinds[1]['label']);
	}//end testTheKindsAndWhatEachSearches()

	/**
	 * The roles, in order, keyed by the values stored on a link.
	 *
	 * @return void
	 */
	public function testTheRolesAreTheStoredKeys(): void {
		$roles = $this->vocabulary()->roles();

		self::assertSame(
			expected: ['aanvrager', 'gemachtigde', 'belanghebbende', 'afzender', 'geadresseerde', 'locatie'],
			actual: array_column($roles, 'key'),
		);
		self::assertSame(expected: PartyVocabulary::ROLE_REQUESTER, actual: $roles[0]['key']);
		self::assertSame(expected: PartyVocabulary::ROLE_REPRESENTATIVE, actual: $roles[1]['key']);
		self::assertSame(expected: '[nl] Authorised representative', actual: $roles[1]['label']);
	}//end testTheRolesAreTheStoredKeys()

	/**
	 * Every label and description is translated.
	 *
	 * @return void
	 */
	public function testEveryLabelAndDescriptionIsTranslated(): void {
		$vocabulary = $this->vocabulary();

		foreach (array_merge($vocabulary->kinds(), $vocabulary->roles()) as $entry) {
			self::assertStringStartsWith(prefix: '[nl] ', string: $entry['label'], message: $entry['key']);
			self::assertStringStartsWith(prefix: '[nl] ', string: $entry['description'], message: $entry['key']);
		}
	}//end testEveryLabelAndDescriptionIsTranslated()
}//end class
