<?php

/**
 * What the ontvangstbevestiging says about when the clock starts.
 *
 * The four things it has to name are the reference, the moment the request was
 * received, the moment the term starts and the deadline. The fifth thing, the
 * sentence explaining why the start is not the moment they pressed send, is
 * conditional, and the condition is the point: an explanation on every
 * confirmation teaches people to stop reading them, and then the one that
 * mattered goes unread too (D-4).
 *
 * BOTH LANGUAGES ARE ASSERTED, not because a translation is likely to be
 * missing but because these two bodies are written out separately in the
 * service, so a line added to one and not the other is invisible until a
 * citizen who chose English gets three dates and no explanation.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `$outside` branch from
 * `termStartLines()` reddens testTheSundayFilingIsExplained and
 * testTheEnglishBodySaysTheSame on the sentence assertions, and returning the
 * lines unconditionally reddens testNoExplanationWhenNoneIsNeeded. Restored
 * after.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\Termijn\TermLetters;
use PHPUnit\Framework\TestCase;

/**
 * The ontvangstbevestiging, and what the citizen may be told.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermLetters::render
 * @uses \OCA\Dossiq\Portal\PortalContributionProvider
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeConfirmationTextTest extends TestCase {

	/**
	 * A Sunday filing: four facts and the sentence that explains the gap.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-the-intake-confirmation-says-when-the-clock-starts-req-term-041
	 */
	public function testTheSundayFilingIsExplained(): void {
		$rendered = $this->render(
			locale: 'nl',
			context: [
				'receivedAt' => '2026-03-08T20:14:00+01:00',
				'termStartsAt' => '2026-03-09T09:00:00+01:00',
				'receivedOutsideWorkingHours' => true,
			],
		);

		self::assertStringContainsString('ZAAK-2026-0001', $rendered['body'], 'The reference.');
		self::assertStringContainsString('08-03-2026', $rendered['body'], 'The moment it was received.');
		self::assertStringContainsString('09-03-2026', $rendered['body'], 'The moment the term starts.');
		self::assertStringContainsString('30-04-2026', $rendered['body'], 'The deadline.');
		self::assertStringContainsString(
			'eerstvolgende werkdag',
			$rendered['body'],
			'Without this sentence the two dates read as a mistake rather than as the rule.',
		);
	}//end testTheSundayFilingIsExplained()

	/**
	 * A Tuesday filing: the same four facts, and no explanation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-the-intake-confirmation-says-when-the-clock-starts-req-term-041
	 */
	public function testNoExplanationWhenNoneIsNeeded(): void {
		$rendered = $this->render(
			locale: 'nl',
			context: [
				'receivedAt' => '2026-03-10T10:00:00+01:00',
				'termStartsAt' => '2026-03-10T10:00:00+01:00',
				'receivedOutsideWorkingHours' => false,
			],
		);

		self::assertStringContainsString('10-03-2026', $rendered['body']);
		self::assertStringNotContainsString(
			'eerstvolgende werkdag',
			$rendered['body'],
			'There is nothing to explain when the clock started the moment they pressed send.',
		);
	}//end testNoExplanationWhenNoneIsNeeded()

	/**
	 * The English body names the same four and carries the same sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-the-intake-confirmation-says-when-the-clock-starts-req-term-041
	 */
	public function testTheEnglishBodySaysTheSame(): void {
		$rendered = $this->render(
			locale: 'en',
			context: [
				'receivedAt' => '2026-03-08T20:14:00+01:00',
				'termStartsAt' => '2026-03-09T09:00:00+01:00',
				'receivedOutsideWorkingHours' => true,
			],
		);

		self::assertStringContainsString('08-03-2026', $rendered['body']);
		self::assertStringContainsString('09-03-2026', $rendered['body']);
		self::assertStringContainsString('30-04-2026', $rendered['body']);
		self::assertStringContainsString('first working day', $rendered['body']);
	}//end testTheEnglishBodySaysTheSame()

	/**
	 * An unstamped case gets the mail it always got, with no invented start.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-the-intake-confirmation-says-when-the-clock-starts-req-term-041
	 */
	public function testAnUnstampedCaseNamesNoStart(): void {
		$rendered = $this->render(locale: 'nl', context: []);

		self::assertStringContainsString('ZAAK-2026-0001', $rendered['body']);
		self::assertStringContainsString('30-04-2026', $rendered['body'], 'The deadline is unaffected.');
		self::assertStringNotContainsString(
			'beslistermijn start op',
			$rendered['body'],
			'A start nobody computed is worse than no start at all.',
		);
	}//end testAnUnstampedCaseNamesNoStart()

	/**
	 * The three fields are on the one list the portal and the mail both read.
	 *
	 * Written twice, the two lists agree the day they are written and diverge
	 * afterwards. This is the assertion that says the citizen's screen and the
	 * citizen's mail are fed from the same definition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-the-intake-confirmation-says-when-the-clock-starts-req-term-041
	 */
	public function testTheCitizenMaySeeTheThreeFields(): void {
		self::assertSame(
			['receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours'],
			array_values(
				array_intersect(
					PortalContributionProvider::CITIZEN_CASE_FIELDS,
					['receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours'],
				)
			),
		);
	}//end testTheCitizenMaySeeTheThreeFields()

	/**
	 * Render the acknowledgement over one context.
	 *
	 * Rendered straight off {@see TermLetters}, which is where the wording
	 * lives and which has no collaborators. This used to build the whole
	 * notification service without its constructor to reach the same method.
	 *
	 * @param string               $locale  The declared language.
	 * @param array<string, mixed> $context The extra context keys.
	 *
	 * @return array{subject: string, body: string, locale: string} The rendered message.
	 */
	private function render(string $locale, array $context): array {
		return (new TermLetters())->render(
			'ontvangstbevestiging',
			['case' => 'ZAAK-2026-0001', 'endDateCurrent' => '30-04-2026'],
			array_merge(['locale' => $locale, 'subject' => 'een dakkapel'], $context),
		);
	}//end render()
}//end class
