<?php

/**
 * The ontvangstbevestiging names when the clock starts, and says why when it
 * is not the moment the citizen pressed send.
 *
 * 🔴 THE SENTENCE IS CONDITIONAL, AND BOTH BRANCHES ARE DRIVEN. A renderer
 * that always adds it passes any test that only checks the Sunday case, and it
 * teaches every reader to skip the paragraph (D-4). So the Tuesday case
 * asserts the sentence is ABSENT.
 *
 * 🔴 THE MAIL READS THE STAMP AND COMPUTES NOTHING. A second computation in
 * the renderer is the second source D-3 refuses: it would drift the day a
 * holiday is administered, and the citizen would be quoting a date the system
 * no longer recognises. The test therefore feeds a start the calendar would
 * NOT produce and asserts the mail says that one.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TermijnNotificationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The acknowledgement's two moments.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeConfirmationSaysTheStartTest extends TestCase {
	/**
	 * Render one acknowledgement without standing the whole service up.
	 *
	 * `renderTemplate()` is pure over its arguments, so the instance is built
	 * without the constructor: the mail this change touches is a string
	 * function of the context, and wiring five collaborators to prove that
	 * would test the container instead.
	 *
	 * @param array<string, mixed> $context The notification context.
	 *
	 * @return array{subject: string, body: string, locale: string} The rendered mail.
	 */
	private function render(array $context): array {
		$service = (new ReflectionClass(TermijnNotificationService::class))->newInstanceWithoutConstructor();

		return $service->renderTemplate('ontvangstbevestiging', ['case' => 'ZAAK-42', 'endDateCurrent' => '2026-11-09'], $context);
	}//end render()

	/**
	 * A Sunday filing is told all four facts and the reason.
	 *
	 * @return void
	 */
	public function testASundayFilingIsToldWhyItStartsOnMonday(): void {
		$body = $this->render([
			'locale' => 'nl',
			'receivedAt' => '2026-09-13 20:41',
			'termStartsAt' => '2026-09-14 09:00',
			'receivedOutsideWorkingHours' => true,
		])['body'];

		$this->assertStringContainsString('ZAAK-42', $body);
		$this->assertStringContainsString('2026-09-13 20:41', $body);
		$this->assertStringContainsString('2026-09-14 09:00', $body);
		$this->assertStringContainsString('2026-11-09', $body);
		$this->assertStringContainsString('eerste werkdag', $body);
	}//end testASundayFilingIsToldWhyItStartsOnMonday()

	/**
	 * A Tuesday filing is told the four facts and nothing else.
	 *
	 * @return void
	 */
	public function testATuesdayFilingIsNotGivenAnExplanationItDoesNotNeed(): void {
		$body = $this->render([
			'locale' => 'nl',
			'receivedAt' => '2026-09-15 10:00',
			'termStartsAt' => '2026-09-15 10:00',
			'receivedOutsideWorkingHours' => false,
		])['body'];

		$this->assertStringContainsString('2026-09-15 10:00', $body);
		$this->assertStringContainsString('2026-11-09', $body);
		$this->assertStringNotContainsString('eerste werkdag', $body);
	}//end testATuesdayFilingIsNotGivenAnExplanationItDoesNotNeed()

	/**
	 * English says the same four things.
	 *
	 * @return void
	 */
	public function testTheEnglishMailSaysTheSameFour(): void {
		$body = $this->render([
			'locale' => 'en',
			'receivedAt' => '2026-09-13 20:41',
			'termStartsAt' => '2026-09-14 09:00',
			'receivedOutsideWorkingHours' => true,
		])['body'];

		$this->assertStringContainsString('ZAAK-42', $body);
		$this->assertStringContainsString('2026-09-13 20:41', $body);
		$this->assertStringContainsString('2026-09-14 09:00', $body);
		$this->assertStringContainsString('first working day', $body);
	}//end testTheEnglishMailSaysTheSameFour()

	/**
	 * The start it names is the one on the case, not one it worked out.
	 *
	 * @return void
	 */
	public function testItNamesTheStoredStartAndComputesNothing(): void {
		// A start no calendar would produce for this arrival. A renderer doing
		// its own arithmetic would print something else.
		$body = $this->render([
			'locale' => 'nl',
			'receivedAt' => '2026-09-13 20:41',
			'termStartsAt' => '2026-09-17 11:22',
			'receivedOutsideWorkingHours' => true,
		])['body'];

		$this->assertStringContainsString('2026-09-17 11:22', $body);
	}//end testItNamesTheStoredStartAndComputesNothing()

	/**
	 * A case with no stamp says nothing about a start it does not have, rather
	 * than printing a dash where a date belongs.
	 *
	 * @return void
	 */
	public function testACaseWithNoStampSaysNothingAboutAStart(): void {
		$body = $this->render(['locale' => 'nl'])['body'];

		$this->assertStringNotContainsString('de termijn start op', $body);
		$this->assertStringNotContainsString('eerste werkdag', $body);
		// And the mail it has always sent is unchanged.
		$this->assertStringContainsString('2026-11-09', $body);
	}//end testACaseWithNoStampSaysNothingAboutAStart()
}//end class
