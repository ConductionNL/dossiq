<?php

/**
 * A template the case cannot fill is refused, not sent with a hole in it.
 *
 * 🔴 THE SAME CLASS AS dossiq#2950, IN A SECOND RENDERER OVER THE SAME STORE.
 * `EmailTemplateService` and `CaseEmailService` both read the
 * `email_template_schema` store, and they answered two different placeholder
 * vocabularies. The editor offers `titel`, `contactNaam`, `startDate`; the send
 * path answered `title`, `startdatum` and nothing at all about a contact. A
 * template authored in the editor and sent through `sendFromTemplate()` reached
 * the recipient with `{{titel}}` and `{{contactNaam}}` still in it.
 *
 * 🔴 AND THE INSTRUMENT SAID SUCCESS. `substituteVariables()` leaves an
 * unanswered placeholder exactly as it found it — correct for a preview, wrong
 * for a mail. The transport accepts it, the send returns, and the only person
 * who sees the defect is the citizen. `findUnresolvedVariables()` has sat
 * beside the send path since both were written, asked only by the preview
 * endpoint.
 *
 * So there are two assertions here and they are different. One: the vocabulary
 * now matches, so the ordinary template renders. Two: when something still
 * cannot be filled, the send is REFUSED rather than reporting success. A test
 * with only the first would pass against a service that sends anything.
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
 * @spec openspec/changes/template-placeholders-answer/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\EmailTemplateService;
use PHPUnit\Framework\TestCase;

/**
 * The two vocabularies, and the refusal.
 *
 * @covers \OCA\Dossiq\Service\Email\CaseEmailRepository
 */
class SendFromTemplateRefusesTest extends TestCase {

	/**
	 * A case as the store holds it.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function case(): array {
		return [
			'identifier' => '2026-0042',
			'title' => 'Dakkapel Kerkstraat 12',
			'startDate' => '2026-09-01',
			'endDate' => '2026-10-01',
			'deadline' => '2026-11-01',
			'status' => 'ontvangen',
			'assignee' => 'a.handler',
			'contactName' => 'J. Jansen',
		];
	}//end case()

	/**
	 * The projection the send path resolves against.
	 *
	 * @return array<string, mixed> The variable map.
	 */
	private function projection(): array {
		$repository = $this->getMockBuilder(CaseEmailRepository::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		return $repository->flattenCaseVariables($this->case());
	}//end projection()

	/**
	 * The send path answers every name the editor offers.
	 *
	 * @return void
	 */
	public function testTheSendPathAnswersWhatTheEditorOffers(): void {
		$service = $this->getMockBuilder(EmailTemplateService::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		$offered = [];
		foreach ($service->getAvailableVariables('any') as $names) {
			$offered = array_merge($offered, $names);
		}

		$projection = $this->projection();

		$unanswerable = [];
		foreach ($offered as $name) {
			if (array_key_exists($name, $projection) === false) {
				$unanswerable[] = $name;
			}
		}

		$this->assertSame(
			[],
			$unanswerable,
			'a name the editor offers and this path cannot fill is a placeholder that reaches '
			. 'the recipient as itself: ' . implode(', ', $unanswerable)
		);
	}//end testTheSendPathAnswersWhatTheEditorOffers()

	/**
	 * The names the old projection used still resolve.
	 *
	 * @return void
	 */
	public function testThePreviousSpellingsStillResolve(): void {
		$projection = $this->projection();

		// Templates written against the six-key projection are stored on
		// instances and are not ours to rewrite.
		foreach (CaseEmailRepository::DEPRECATED_ALIASES as $was => $now) {
			$this->assertArrayHasKey($was, $projection);
			$this->assertSame($projection[$now], $projection[$was]);
		}
	}//end testThePreviousSpellingsStillResolve()

	/**
	 * A name nothing answers is reported, so the send path can refuse.
	 *
	 * @return void
	 */
	public function testAnUnanswerableNameIsReported(): void {
		$service = $this->getMockBuilder(\OCA\Dossiq\Service\CaseEmailService::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		$projection = $this->projection();

		// The control first: a template the case CAN fill reports nothing, or
		// "reported" below could mean it reports everything.
		$this->assertSame(
			[],
			$service->findUnresolvedVariables(
				'Zaak {{zaakNummer}} voor {{contactNaam}}, uiterlijk {{deadline}}.',
				$projection
			)
		);

		$this->assertSame(
			['nobodyAnswersThis'],
			$service->findUnresolvedVariables(
				'Zaak {{zaakNummer}}: {{nobodyAnswersThis}}.',
				$projection
			)
		);
	}//end testAnUnanswerableNameIsReported()

	/**
	 * The send path asks that question before it sends.
	 *
	 * @return void
	 */
	public function testTheSendPathAsksBeforeSending(): void {
		$source = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/CaseEmailService.php'
		);

		$from = strpos($source, 'public function sendFromTemplate(');
		$this->assertNotFalse($from);
		$body = substr($source, $from, (strpos($source, '}//end sendFromTemplate()', $from) - $from));

		// 🔴 BOTH HALVES, IN THIS ORDER. Asking and not refusing is the state
		// this path was already in: `findUnresolvedVariables()` existed and the
		// preview endpoint was its only caller.
		$this->assertStringContainsString(
			'findUnresolvedVariables',
			$body,
			'the send path must ask which placeholders the case cannot fill'
		);
		// Matched by ITS OWN SENTENCE, not by `throw new RuntimeException`:
		// this method already threw one for a template that does not exist, so
		// a looser match is satisfied by THAT and asserts nothing about this
		// refusal. The first draft of this test was, and reddened on the
		// ordering assertion rather than on the thing it meant to check.
		$this->assertStringContainsString(
			'Email not sent: the template names',
			$body,
			'and it must REFUSE on them: leaving them in the text is a mail that sends, reports '
			. 'success, and reaches the citizen with template syntax in it'
		);
		$this->assertLessThan(
			strpos($body, 'Email not sent: the template names'),
			strpos($body, 'findUnresolvedVariables'),
			'the question must be asked before the refusal'
		);
		$this->assertLessThan(
			strpos($body, '$this->sendEmail('),
			strpos($body, 'Email not sent: the template names'),
			'and the refusal must come before the send, or the mail is already gone'
		);
	}//end testTheSendPathAsksBeforeSending()
}//end class
