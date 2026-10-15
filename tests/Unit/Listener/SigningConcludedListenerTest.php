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
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\Signing\SigningConclusionRecorder;
use OCA\Filinq\Event\SigningConcludedEvent;
use OCA\Filinq\Event\SigningProvenance;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
	 * The service account double.
	 *
	 * @var BackgroundServiceAccount&MockObject
	 */
	private BackgroundServiceAccount&MockObject $account;

	/**
	 * The listener, with a service account that runs the operation as given.
	 *
	 * @param LoggerInterface|null $logger The logger; a null logger when not given.
	 *
	 * @return SigningConcludedListener
	 */
	private function listener(?LoggerInterface $logger = null): SigningConcludedListener {
		$this->decisions = $this->createMock(SigningConclusionRecorder::class);
		$this->account = $this->createMock(BackgroundServiceAccount::class);
		$this->account->method('runAs')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$this->account->expects($this->never())->method('runAsWhenNobodyIsSignedIn');

		return new SigningConcludedListener($this->decisions, $this->account, ($logger ?? new NullLogger()));
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

	/**
	 * Every conclusion is written as the service account, never as the signed-in user (decision 242).
	 *
	 * @return void
	 */
	public function testTheConclusionIsWrittenAsTheServiceAccount(): void {
		$listener = $this->listener();
		$this->account->expects($this->once())->method('runAs');
		$this->decisions->expects($this->once())->method('record')->willReturn(true);

		$listener->handle(self::concluded(sourceApp: 'dossiq', status: 'signed'));
	}//end testTheConclusionIsWrittenAsTheServiceAccount()

	/**
	 * Without a usable service account nothing is written, and an error names the decision.
	 *
	 * @return void
	 */
	public function testWithoutAServiceAccountNothingIsWrittenAndTheDecisionIsNamed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('not recorded'),
			$this->callback(static fn (array $context): bool => $context['decision'] === 'decision-1' && $context['signingRequest'] === 'sr-1' && $context['status'] === 'expired')
		);
		$this->decisions = $this->createMock(SigningConclusionRecorder::class);
		$this->decisions->expects($this->never())->method('record');
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAs')->willThrowException(new ServiceAccountUnavailableException('no service account configured'));
		$account->expects($this->never())->method('runAsWhenNobodyIsSignedIn');

		(new SigningConcludedListener($this->decisions, $account, $logger))->handle(self::concluded(sourceApp: 'dossiq', status: 'expired'));
	}//end testWithoutAServiceAccountNothingIsWrittenAndTheDecisionIsNamed()
}//end class
