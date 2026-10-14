<?php

/**
 * Signing Concluded Listener Test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/signing-through-filinq/specs/libresign-besluit-signing/spec.md#requirement-a-signing-request-that-ends-unsigned-is-shown-as-not-concluded
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\SigningConcludedListener;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\Signing\SigningConclusionRecorder;
use OCA\Filinq\Event\SigningConcludedEvent;
use OCA\Filinq\Event\SigningProvenance;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Filinq's conclusion reaches the decision that asked for it, and only that one.
 */
final class SigningConcludedListenerTest extends TestCase {
	/**
	 * The recorder double.
	 *
	 * @var SigningConclusionRecorder&MockObject
	 */
	private SigningConclusionRecorder&MockObject $decisions;

	/**
	 * The listener, with a service account that runs the operation as given.
	 *
	 * @return SigningConcludedListener
	 */
	private function listener(): SigningConcludedListener {
		$this->decisions = $this->createMock(SigningConclusionRecorder::class);
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		return new SigningConcludedListener($this->decisions, $account, new NullLogger());
	}//end listener()

	/**
	 * Filinq's conclusion event.
	 *
	 * @param string $sourceApp The app that asked for the signature.
	 * @param string $status The terminal status.
	 *
	 * @return SigningConcludedEvent
	 */
	private static function concluded(string $sourceApp, string $status): SigningConcludedEvent {
		return new SigningConcludedEvent(
			signingRequestId: 'sr-1',
			status: $status,
			signedDocumentRef: $status === 'signed' ? '9001' : null,
			signers: ['signer-1'],
			signedAt: $status === 'signed' ? '2026-10-14T10:00:00+02:00' : null,
			provenance: new SigningProvenance(sourceApp: $sourceApp, subjectId: 'decision-1', externalReference: 'decision-1', correlationId: 'c-1'),
		);
	}//end concluded()

	/**
	 * Each terminal status reaches the decision as filinq sent it.
	 *
	 * @return void
	 */
	public function testAConclusionOfDossiqsRequestReachesTheDecision(): void {
		foreach (['signed', 'expired', 'declined', 'cancelled'] as $status) {
			$listener = $this->listener();
			$this->decisions->expects($this->once())->method('record')->with(
				'decision-1',
				'sr-1',
				$status,
				$status === 'signed' ? '9001' : null,
				$status === 'signed' ? '2026-10-14T10:00:00+02:00' : null,
			)->willReturn(true);

			$listener->handle(self::concluded(sourceApp: 'dossiq', status: $status));
		}
	}//end testAConclusionOfDossiqsRequestReachesTheDecision()

	/**
	 * Another app's conclusion, or another event, is not dossiq's to record.
	 *
	 * @return void
	 */
	public function testAnotherAppsConclusionIsIgnored(): void {
		$listener = $this->listener();
		$this->decisions->expects($this->never())->method('record');

		$listener->handle(self::concluded(sourceApp: 'shillinq', status: 'signed'));
		$listener->handle(new Event());
	}//end testAnotherAppsConclusionIsIgnored()
}//end class
