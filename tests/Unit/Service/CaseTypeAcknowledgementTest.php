<?php

/**
 * CaseTypeAcknowledgement unit tests.
 *
 * What a case type says about confirming receipt, and which cases owe one.
 * Every assertion here guards a way the Awb 4:3a duty could go missing without
 * anything turning red: a case type that declares nothing, a case type whose
 * declaration was switched off, a case typed at the balie that would be mailed
 * anyway, and a declared list of moments that quietly lost the statutory entry.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use PHPUnit\Framework\TestCase;

/**
 * The declaration, and the cases it applies to.
 *
 * @covers \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 */
class CaseTypeAcknowledgementTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var CaseTypeAcknowledgement
	 */
	private CaseTypeAcknowledgement $declaration;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->declaration = new CaseTypeAcknowledgement();
	}//end setUp()

	/**
	 * A case type that declares nothing still owes the duty.
	 *
	 * The law does not wait for a configuration, so the default is ON and the
	 * default channel list is every electronic intake channel.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDeclaresNothingStillOwesTheDuty(): void {
		$declaration = $this->declaration->declarationFor(caseType: []);

		self::assertTrue(condition: $declaration['enabled']);
		self::assertTrue(condition: $declaration['statutory']);
		self::assertSame(expected: ['email', 'website', 'zgw-api'], actual: $declaration['intakeChannels']);
		self::assertSame(expected: 'email', actual: $declaration['defaultChannel']);
		self::assertFalse(condition: $declaration['contentOnPlatform']);
		self::assertSame(expected: 'nl', actual: $declaration['language']);
	}//end testACaseTypeThatDeclaresNothingStillOwesTheDuty()

	/**
	 * Each electronic intake path owes an acknowledgement.
	 *
	 * The portal writes `website`, the mail intake writes `email` and the ZGW
	 * API intake writes `zgw-api`. One listener covers all three because all
	 * three end in a `case` object; what tells them apart is this field.
	 *
	 * @param string $channel The intake channel.
	 *
	 * @return void
	 *
	 * @dataProvider electronicChannels
	 */
	public function testEveryElectronicIntakePathOwesOne(string $channel): void {
		self::assertTrue(
			condition: $this->declaration->owesAcknowledgement(
				case: ['intakeChannel' => $channel],
				caseType: [],
			),
			message: $channel . ' is an electronic submission and owes a confirmation of receipt'
		);
	}//end testEveryElectronicIntakePathOwesOne()

	/**
	 * The electronic intake channels.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function electronicChannels(): array {
		return [
			'the portal' => ['website'],
			'the mail intake' => ['email'],
			'the ZGW API' => ['zgw-api'],
		];
	}//end electronicChannels()

	/**
	 * A case typed at the balie owes nothing.
	 *
	 * @param string $channel The intake channel.
	 *
	 * @return void
	 *
	 * @dataProvider nonElectronicChannels
	 */
	public function testANonElectronicIntakeOwesNothing(string $channel): void {
		self::assertFalse(
			condition: $this->declaration->owesAcknowledgement(
				case: ['intakeChannel' => $channel],
				caseType: [],
			)
		);
	}//end testANonElectronicIntakeOwesNothing()

	/**
	 * The channels that are not an electronic submission.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function nonElectronicChannels(): array {
		return [
			'typed by hand' => ['manual'],
			'at the counter' => ['balie'],
			'by telephone' => ['phone'],
			'by post' => ['post'],
			'something else' => ['other'],
		];
	}//end nonElectronicChannels()

	/**
	 * A case type may name a channel the default list leaves out.
	 *
	 * @return void
	 */
	public function testACaseTypeMayNameAChannelOfItsOwn(): void {
		$caseType = ['acknowledgement' => ['intakeChannels' => ['balie']]];

		self::assertTrue(
			condition: $this->declaration->owesAcknowledgement(case: ['intakeChannel' => 'balie'], caseType: $caseType)
		);
		self::assertFalse(
			condition: $this->declaration->owesAcknowledgement(case: ['intakeChannel' => 'email'], caseType: $caseType)
		);
	}//end testACaseTypeMayNameAChannelOfItsOwn()

	/**
	 * A case with no intake channel at all owes nothing.
	 *
	 * An absent channel is not evidence of an electronic submission, and
	 * reading it as one would mail somebody about every hand-typed case.
	 *
	 * @return void
	 */
	public function testAnAbsentIntakeChannelOwesNothing(): void {
		self::assertFalse(condition: $this->declaration->owesAcknowledgement(case: [], caseType: []));
	}//end testAnAbsentIntakeChannelOwesNothing()

	/**
	 * A declaration switched off is honoured.
	 *
	 * @return void
	 */
	public function testADeclarationSwitchedOffIsHonoured(): void {
		self::assertFalse(
			condition: $this->declaration->owesAcknowledgement(
				case: ['intakeChannel' => 'website'],
				caseType: ['acknowledgement' => ['enabled' => false]],
			)
		);
	}//end testADeclarationSwitchedOffIsHonoured()

	/**
	 * Switching the duty off warns, and the warning names Awb 4:3a.
	 *
	 * @return void
	 */
	public function testSwitchingTheDutyOffWarnsAndNamesTheArticle(): void {
		$warnings = $this->declaration->publicationWarnings(
			caseType: ['acknowledgement' => ['enabled' => false]]
		);

		self::assertCount(expectedCount: 1, haystack: $warnings);
		self::assertStringContainsString(needle: 'Awb 4:3a', haystack: $warnings[0]);
	}//end testSwitchingTheDutyOffWarnsAndNamesTheArticle()

	/**
	 * An empty channel list sends nothing, so it warns too.
	 *
	 * Enabled with no channel is off with extra steps, and it reads as on.
	 *
	 * @return void
	 */
	public function testAnEmptyChannelListWarns(): void {
		$warnings = $this->declaration->publicationWarnings(
			caseType: ['acknowledgement' => ['intakeChannels' => []]]
		);

		self::assertCount(expectedCount: 1, haystack: $warnings);
		self::assertStringContainsString(needle: 'Awb 4:3a', haystack: $warnings[0]);
	}//end testAnEmptyChannelListWarns()

	/**
	 * A case type that declares nothing warns about nothing.
	 *
	 * @return void
	 */
	public function testADefaultCaseTypeWarnsAboutNothing(): void {
		self::assertSame(expected: [], actual: $this->declaration->publicationWarnings(caseType: []));
	}//end testADefaultCaseTypeWarnsAboutNothing()

	/**
	 * A declared list of moments that lost the statutory entry warns.
	 *
	 * @return void
	 */
	public function testRemovingTheStatutoryMomentWarns(): void {
		$warnings = $this->declaration->publicationWarnings(
			caseType: [
				'notificationMoments' => [
					['moment' => 'status-changed', 'template' => 'status'],
					['moment' => 'case-incomplete', 'template' => 'incomplete'],
				],
			]
		);

		self::assertCount(expectedCount: 1, haystack: $warnings);
		self::assertStringContainsString(needle: 'Awb 4:3a', haystack: $warnings[0]);
	}//end testRemovingTheStatutoryMomentWarns()

	/**
	 * The statutory moment is there whether or not anyone typed it.
	 *
	 * @return void
	 */
	public function testTheStatutoryMomentIsAlwaysInTheList(): void {
		$moments = $this->declaration->momentsFor(caseType: []);

		self::assertSame(expected: 'case-received', actual: $moments[0]['moment']);
		self::assertTrue(condition: $moments[0]['statutory']);
		self::assertSame(expected: 'ontvangstbevestiging', actual: $moments[0]['template']);
	}//end testTheStatutoryMomentIsAlwaysInTheList()

	/**
	 * Asking for something is not the same moment as moving on.
	 *
	 * Informing and chasing are different messages, so a case type declaring
	 * both keeps both, and neither collapses into the other.
	 *
	 * @return void
	 */
	public function testAskingForSomethingIsNotTheStatusChangeMoment(): void {
		$moments = $this->declaration->momentsFor(
			caseType: [
				'notificationMoments' => [
					['moment' => 'case-incomplete', 'template' => 'aanvulling'],
					['moment' => 'status-changed', 'template' => 'status'],
				],
			]
		);

		$byMoment = [];
		foreach ($moments as $moment) {
			$byMoment[$moment['moment']] = $moment['template'];
		}

		self::assertArrayHasKey(key: 'case-received', array: $byMoment);
		self::assertSame(expected: 'aanvulling', actual: $byMoment['case-incomplete']);
		self::assertSame(expected: 'status', actual: $byMoment['status-changed']);
		self::assertNotSame(expected: $byMoment['case-incomplete'], actual: $byMoment['status-changed']);
	}//end testAskingForSomethingIsNotTheStatusChangeMoment()

	/**
	 * The citizen's own recorded channel wins over the case type's default.
	 *
	 * @return void
	 */
	public function testTheCitizensRecordedChannelWins(): void {
		$caseType = ['acknowledgement' => ['defaultChannel' => 'email']];

		self::assertSame(
			expected: 'portal',
			actual: $this->declaration->channelFor(case: ['communicationChannel' => 'portal'], caseType: $caseType)
		);
		self::assertSame(
			expected: 'email',
			actual: $this->declaration->channelFor(case: [], caseType: $caseType)
		);
	}//end testTheCitizensRecordedChannelWins()

	/**
	 * Content on the platform is read off the case type, not the template.
	 *
	 * @return void
	 */
	public function testContentOnThePlatformIsACaseTypeDecision(): void {
		self::assertTrue(
			condition: $this->declaration->contentStaysOnPlatform(
				caseType: ['acknowledgement' => ['contentOnPlatform' => true]]
			)
		);
		self::assertFalse(condition: $this->declaration->contentStaysOnPlatform(caseType: []));
	}//end testContentOnThePlatformIsACaseTypeDecision()

	/**
	 * The language is Dutch unless the case type declares another.
	 *
	 * @return void
	 */
	public function testTheLanguageIsDutchUnlessDeclared(): void {
		self::assertSame(expected: 'nl', actual: $this->declaration->languageFor(caseType: []));
		self::assertSame(
			expected: 'en',
			actual: $this->declaration->languageFor(caseType: ['acknowledgement' => ['language' => 'en']])
		);
	}//end testTheLanguageIsDutchUnlessDeclared()
}//end class
