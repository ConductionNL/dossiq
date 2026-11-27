<?php

/**
 * SubmissionMadeOnce Unit Tests
 *
 * Without a ledger there is no key and no replay; a first send opens the case
 * and its answer comes back on a resend; a send the intake refuses lets its
 * key go, so the corrected resend is a first send.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\SubmissionMadeOnce;
use OCA\Dossiq\Kernel\Contracts\WooRequestIntakeInterface;
use OCA\Dossiq\Portal\PortalSubmissionLedger;
use OCA\Dossiq\Tests\Unit\Portal\InMemoryMemcache;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\AppFramework\Http;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SubmissionMadeOnce.
 *
 * @covers \OCA\Dossiq\Controller\Support\SubmissionMadeOnce
 */
final class SubmissionMadeOnceTest extends TestCase {

	/**
	 * A well-formed submission key.
	 */
	private const KEY = '6f1c2a9e-0b7d-4c1e-9a3f-2d5e8b4c7a10';

	/**
	 * Without a ledger there is no key, no replay, and every send opens a case.
	 *
	 * @return void
	 */
	public function testWithoutALedgerEverySendOpensACase(): void {
		$intake = $this->createMock(originalClassName: WooRequestIntakeInterface::class);
		$intake->expects($this->exactly(count: 2))->method('start')->willReturn(['caseId' => 'case-1']);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn(self::KEY);
		$once = new SubmissionMadeOnce(intake: $intake);

		$this->assertSame(expected: '', actual: $once->key(request: $request));
		$this->assertNull(actual: $once->replay(subjectRef: 'anna', key: self::KEY));
		$once->open(request: ['subjectRef' => 'anna'], key: self::KEY);
		$this->assertSame(expected: ['caseId' => 'case-1'], actual: $once->open(request: ['subjectRef' => 'anna'], key: self::KEY));
	}//end testWithoutALedgerEverySendOpensACase()

	/**
	 * A resend with the key of a send that made a case gets that case's answer back.
	 *
	 * @return void
	 */
	public function testAResendGetsTheFirstAnswerBack(): void {
		$intake = $this->createMock(originalClassName: WooRequestIntakeInterface::class);
		$intake->expects($this->once())->method('start')->willReturn(['caseId' => 'case-1']);
		$once = $this->overALedger(intake: $intake);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn(' ' . self::KEY . ' ');
		$key = $once->key(request: $request);

		$this->assertSame(expected: self::KEY, actual: $key);
		$this->assertNull(actual: $once->replay(subjectRef: 'anna', key: $key));
		$once->open(request: ['subjectRef' => 'anna'], key: $key);

		$replay = $once->replay(subjectRef: 'anna', key: $key);
		$this->assertSame(expected: Http::STATUS_CREATED, actual: $replay?->getStatus());
		$this->assertSame(expected: ['caseId' => 'case-1'], actual: $replay?->getData());
	}//end testAResendGetsTheFirstAnswerBack()

	/**
	 * A send still being made answers 409; a refused send lets its key go and is thrown on.
	 *
	 * @return void
	 */
	public function testARefusedSendLetsItsKeyGo(): void {
		$intake = $this->createMock(originalClassName: WooRequestIntakeInterface::class);
		$intake->method('start')->willThrowException(
			new WooRequestRefused(reason: WooRequestRefused::INVALID, detail: 'Fill in the subject.', field: 'onderwerp')
		);
		$once = $this->overALedger(intake: $intake);

		$this->assertNull(actual: $once->replay(subjectRef: 'anna', key: self::KEY));
		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $once->replay(subjectRef: 'anna', key: self::KEY)?->getStatus());

		try {
			$once->open(request: ['subjectRef' => 'anna'], key: self::KEY);
			$this->fail(message: 'The refusal was not thrown on.');
		} catch (WooRequestRefused $refused) {
			$this->assertSame(expected: WooRequestRefused::INVALID, actual: $refused->getReason());
		}

		$this->assertNull(actual: $once->replay(subjectRef: 'anna', key: self::KEY));
	}//end testARefusedSendLetsItsKeyGo()

	/**
	 * The submissions over an in-memory ledger.
	 *
	 * @param WooRequestIntakeInterface $intake The intake.
	 *
	 * @return SubmissionMadeOnce
	 */
	private function overALedger(WooRequestIntakeInterface $intake): SubmissionMadeOnce {
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('createDistributed')->willReturn(new InMemoryMemcache());

		return new SubmissionMadeOnce(intake: $intake, ledger: new PortalSubmissionLedger(cacheFactory: $factory));
	}//end overALedger()
}//end class
