<?php

/**
 * Unit tests for the RetiredTemplateSyntax message helpers: retired case
 * placeholders become the message step's top-level fields, merge fields are
 * inlined, and a recipient reference becomes an address or a field; whatever
 * the message step cannot read is refused as unmappable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 */
class RetiredTemplateSyntaxMessagingTest extends TestCase {

	private RetiredTemplateSyntax $syntax;

	protected function setUp(): void {
		parent::setUp();
		$this->syntax = new RetiredTemplateSyntax();
	}//end setUp()

	public function testCasePlaceholdersBecomeTopLevelFieldsAndOthersAreDropped(): void {
		$this->assertSame(
			'Zaak {{ title }} van {{ owner }}, beste .',
			$this->syntax->forMessaging('Zaak {{ case.title }} van {{case.owner}}, beste {{ user.name }}.', 'subject')
		);
		$this->assertSame('Geen velden', $this->syntax->forMessaging('Geen velden', 'body'));
	}//end testCasePlaceholdersBecomeTopLevelFieldsAndOthersAreDropped()

	public function testANestedCasePathIsUnmappable(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('the subject uses {{case.applicant.name}}, and the OpenRegister message step reads only top-level fields');

		$this->syntax->forMessaging('Beste {{ case.applicant.name }}', 'subject');
	}//end testANestedCasePathIsUnmappable()

	public function testABareCaseRootIsUnmappable(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('the body uses {{case.}}');

		$this->syntax->forMessaging('{{ case. }}', 'body');
	}//end testABareCaseRootIsUnmappable()

	public function testMergeFieldsAreInlinedAndAnythingElseIsLeftAlone(): void {
		$this->assertSame(
			'12 euro voor , ref  en {{ case.title }}',
			$this->syntax->inlineMergeFields(
				'{{ case.mergeFields.amount }} euro voor {{case.mergeFields.missing}}, ref {{ case.mergeFields.list }} en {{ case.title }}',
				['amount' => 12, 'list' => ['a']]
			)
		);
		$this->assertSame('ja', $this->syntax->inlineMergeFields('{{ case.mergeFields.ok }}', ['ok' => 'ja']));
	}//end testMergeFieldsAreInlinedAndAnythingElseIsLeftAlone()

	public function testARecipientIsAnAddressOrAField(): void {
		$this->assertSame('loket@gemeente.nl', $this->syntax->recipientFromRef('email:loket@gemeente.nl'));
		$this->assertSame('{{ assignee_email }}', $this->syntax->recipientFromRef('assignee_email'));
		$this->assertSame('{{ owner-mail }}', $this->syntax->recipientFromRef('owner-mail'));
	}//end testARecipientIsAnAddressOrAField()

	public function testNoRecipientIsUnmappable(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('the step names no recipient');

		$this->syntax->recipientFromRef('');
	}//end testNoRecipientIsUnmappable()

	public function testARecipientPathTheStepCannotReadIsUnmappable(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('the recipient "case.owner" is not a field name the send-email step can read');

		$this->syntax->recipientFromRef('case.owner');
	}//end testARecipientPathTheStepCannotReadIsUnmappable()
}//end class
