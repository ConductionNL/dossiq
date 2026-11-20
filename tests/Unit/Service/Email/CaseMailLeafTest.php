<?php

/**
 * Linking one Mail message to a case through OpenRegister's email link service.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseMailLeaf;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkTarget;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\CaseMailLeaf
 */
class CaseMailLeafTest extends TestCase {

	/**
	 * The case's uuid, register and schema reach the link target, and the account, message id and uid reach the message.
	 *
	 * @return void
	 */
	public function testTheMessageIsLinkedToTheCaseOfItsAccount(): void {
		$service = new class () {
			/**
			 * The calls made.
			 *
			 * @var array<int, array{target: LinkTarget, message: EmailMessageRef}>
			 */
			public array $links = [];

			/**
			 * Records one link.
			 *
			 * @param LinkTarget      $target  Where the message goes.
			 * @param EmailMessageRef $message The message.
			 *
			 * @return void
			 */
			public function linkEmail(LinkTarget $target, EmailMessageRef $message): void {
				$this->links[] = ['target' => $target, 'message' => $message];
			}
		};

		(new CaseMailLeaf(linkService: $service, accountId: 3))->link(
			case: ['uuid' => 'case-uuid', 'identifier' => '2026-0042', 'registerId' => 5, 'schemaId' => 7],
			message: ['id' => 81, 'uid' => '<a@b>', 'subject' => 'Re: 2026-0042', 'preview' => '']
		);

		$this->assertCount(1, $service->links);
		$target = $service->links[0]['target'];
		$message = $service->links[0]['message'];
		$this->assertSame('case-uuid', $target->objectUuid);
		$this->assertSame(5, $target->registerId);
		$this->assertSame(7, $target->schemaId());
		$this->assertSame(3, $message->mailAccountId);
		$this->assertSame('81', $message->messageId, 'the Mail id is passed as a string');
		$this->assertSame('<a@b>', $message->uidForMatch());
	}//end testTheMessageIsLinkedToTheCaseOfItsAccount()
}//end class
