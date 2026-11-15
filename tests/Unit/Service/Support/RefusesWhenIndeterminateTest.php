<?php

/**
 * RefusesWhenIndeterminate unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A host exposing the trait's read seam.
 */
class IndeterminateHost {

	use RefusesWhenIndeterminate;

	/**
	 * @param LoggerInterface $logger The logger the trait writes to.
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * @param callable $read The read.
	 * @return mixed What the read answered.
	 */
	public function read(callable $read): mixed {
		return $this->readOrRefuse(read: $read, what: 'the roles on a case', rule: 'roles-unreadable', sentence: 'We could not check your role.');
	}//end read()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Support\RefusesWhenIndeterminate
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class RefusesWhenIndeterminateTest extends TestCase {

	/**
	 * A read that succeeds is answered as is, without logging.
	 *
	 * @return void
	 */
	public function testASuccessfulReadIsAnswered(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		self::assertSame(['handler'], (new IndeterminateHost(logger: $logger))->read(read: static fn (): array => ['handler']));
		self::assertSame([], (new IndeterminateHost(logger: $logger))->read(read: static fn (): array => []));
	}//end testASuccessfulReadIsAnswered()

	/**
	 * A read that throws is logged and refused as indeterminate, never empty.
	 *
	 * @return void
	 */
	public function testAFailingReadIsRefusedAsIndeterminate(): void {
		$cause  = new RuntimeException('register down');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Dossiq: the roles on a case could not be read: register down');

		try {
			(new IndeterminateHost(logger: $logger))->read(read: static function () use ($cause): never {
				throw $cause;
			});
			self::fail('The failing read was not refused.');
		} catch (RefusedException $e) {
			self::assertSame('roles-unreadable', $e->getRule());
			self::assertSame('We could not check your role.', $e->getSentence());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertSame(503, $e->getStatus());
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testAFailingReadIsRefusedAsIndeterminate()
}//end class
