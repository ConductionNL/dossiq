<?php

/**
 * A Woo request sent twice with the same submission key makes one case.
 *
 * Live round 3 (14 Oct): the forward timed out while the case was written,
 * the resident sent again, and got cases 2026-0115, 0116 and 0118.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalWooRequestController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Portal\PortalSubmissionLedger;
use OCA\Dossiq\Tests\Unit\Portal\InMemoryMemcache;
use OCA\Dossiq\Tests\Unit\Portal\PortalAssertionVerifierTest;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives the controller with a real verifier, a real ledger and a mocked intake.
 *
 * @covers \OCA\Dossiq\Controller\PortalWooRequestController
 * @covers \OCA\Dossiq\Portal\PortalSubmissionLedger
 */
class PortalWooRequestSubmissionKeyTest extends TestCase {

	private const KEY = '0f8fad5b-d9cb-469f-a165-70867728950e';

	/**
	 * The cache the ledger keeps its keys in, shared by every send of one test.
	 *
	 * @var InMemoryMemcache
	 */
	private InMemoryMemcache $cache;

	/**
	 * The intake, shared by every send of one test.
	 *
	 * @var WooRequestIntake&MockObject
	 */
	private WooRequestIntake&MockObject $intake;

	protected function setUp(): void {
		$this->cache  = new InMemoryMemcache();
		$this->intake = $this->createMock(WooRequestIntake::class);
	}//end setUp()

	/**
	 * One send to the controller.
	 *
	 * @param string $key       The Idempotency-Key header.
	 * @param string $assertion The X-Portal-Subject header; Anna's when empty.
	 *
	 * @return PortalWooRequestController
	 */
	private function send(string $key, string $assertion = ''): PortalWooRequestController {
		$assertion = ($assertion !== '' ? $assertion : PortalAssertionVerifierTest::mint());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				PortalAssertionVerifier::HEADER => $assertion,
				PortalSubmissionLedger::HEADER => $key,
				default => '',
			}
		);
		$request->method('getParam')->willReturnCallback(
			static fn (string $field, mixed $default = null): mixed => ($field === 'onderwerp' ? 'Parkeerbeleid' : $default)
		);
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		return new PortalWooRequestController(
			appName: 'dossiq',
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: 'a-dedicated-portaliq-secret-0123'),
			intake: $this->intake,
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
			ledger: new PortalSubmissionLedger(cacheFactory: $factory),
		);
	}//end send()

	/**
	 * The second send with the same key gets the first case back, and no second case is made.
	 *
	 * @return void
	 */
	public function testASecondSendWithTheSameKeyGetsTheFirstCase(): void {
		$this->intake->expects(self::once())->method('start')
			->willReturn(['caseId' => 'case-1', 'caseUrl' => 'https://x/case-1', 'identifier' => '2026-0115']);

		$first  = $this->send(key: self::KEY)->start();
		$second = $this->send(key: self::KEY)->start();

		self::assertSame(201, $first->getStatus());
		self::assertSame(201, $second->getStatus());
		self::assertSame($first->getData(), $second->getData());
	}//end testASecondSendWithTheSameKeyGetsTheFirstCase()

	/**
	 * A send that comes while the first is still being made hears so, and makes nothing.
	 *
	 * @return void
	 */
	public function testASendWhileTheFirstIsBusyIs409(): void {
		$this->intake->expects(self::once())->method('start')->willReturnCallback(
			function (): array {
				$busy = $this->send(key: self::KEY)->start();
				self::assertSame(409, $busy->getStatus());
				self::assertSame(['error' => 'submission_processing'], $busy->getData());
				return ['caseId' => 'case-1', 'caseUrl' => 'https://x/case-1'];
			}
		);

		self::assertSame(201, $this->send(key: self::KEY)->start()->getStatus());
	}//end testASendWhileTheFirstIsBusyIs409()

	/**
	 * A refused send makes nothing and lets the key go, so the corrected resend is a first send.
	 *
	 * @return void
	 */
	public function testARefusedSendLetsTheKeyGo(): void {
		$this->intake->expects(self::exactly(2))->method('start')->willReturnOnConsecutiveCalls(
			self::throwException(new WooRequestRefused(WooRequestRefused::INVALID, 'Fill in the subject.', 'onderwerp')),
			['caseId' => 'case-1', 'caseUrl' => 'https://x/case-1']
		);

		self::assertSame(400, $this->send(key: self::KEY)->start()->getStatus());
		self::assertSame(201, $this->send(key: self::KEY)->start()->getStatus());
	}//end testARefusedSendLetsTheKeyGo()

	/**
	 * Another resident's send with the same key is their own; without a key every send is new.
	 *
	 * @return void
	 */
	public function testTheKeyIsTheResidentsOwnAndNoKeyIsAlwaysNew(): void {
		$this->intake->expects(self::exactly(4))->method('start')
			->willReturn(['caseId' => 'case-x', 'caseUrl' => 'https://x/case-x']);

		$this->send(key: self::KEY)->start();
		$this->send(key: self::KEY, assertion: PortalAssertionVerifierTest::mint(['sub' => 'subject-ref-bram']))->start();
		$this->send(key: '')->start();
		$this->send(key: 'not a key')->start();
	}//end testTheKeyIsTheResidentsOwnAndNoKeyIsAlwaysNew()
}//end class
