<?php

/**
 * The integrity check verifies the next batch as the service account, and waits when that account is unavailable.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\DocumentIntegrityCheckJob;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\Zaakdossier\DocumentIntegrityVerifier;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for DocumentIntegrityCheckJob.
 *
 * @covers \OCA\Dossiq\BackgroundJob\DocumentIntegrityCheckJob
 */
class DocumentIntegrityCheckJobTest extends TestCase {

	/**
	 * Run the job once.
	 *
	 * @param DocumentIntegrityVerifier $verifier The verifier.
	 * @param BackgroundServiceAccount  $account  The service account.
	 *
	 * @return void
	 */
	private function runJob(DocumentIntegrityVerifier $verifier, BackgroundServiceAccount $account): void {
		$job = new DocumentIntegrityCheckJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			verifier: $verifier,
			serviceAccount: $account
		);

		(new ReflectionMethod(objectOrMethod: $job, method: 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The next batch of 500 is verified inside the service account.
	 *
	 * @return void
	 */
	public function testTheNextBatchIsVerifiedAsTheServiceAccount(): void {
		$inside   = false;
		$verifier = $this->createMock(originalClassName: DocumentIntegrityVerifier::class);
		$verifier->expects($this->once())->method('verifyNext')->with(500)->willReturnCallback(
			static function () use (&$inside): int {
				TestCase::assertTrue(condition: $inside, message: 'verified outside the service account');
				return 3;
			}
		);
		$account = $this->createMock(originalClassName: BackgroundServiceAccount::class);
		$account->expects($this->once())->method('runAs')->willReturnCallback(
			static function (callable $operation) use (&$inside): mixed {
				$inside = true;
				$result = $operation();
				$inside = false;

				return $result;
			}
		);

		$this->runJob(verifier: $verifier, account: $account);
	}//end testTheNextBatchIsVerifiedAsTheServiceAccount()

	/**
	 * An unavailable service account verifies nothing and does not throw.
	 *
	 * @return void
	 */
	public function testAnUnavailableAccountVerifiesNothing(): void {
		$verifier = $this->createMock(originalClassName: DocumentIntegrityVerifier::class);
		$verifier->expects($this->never())->method('verifyNext');
		$account = $this->createMock(originalClassName: BackgroundServiceAccount::class);
		$account->method('runAs')->willThrowException(exception: new ServiceAccountUnavailableException(message: 'no account'));

		$this->runJob(verifier: $verifier, account: $account);
		$this->addToAssertionCount(count: 1);
	}//end testAnUnavailableAccountVerifiesNothing()
}//end class
