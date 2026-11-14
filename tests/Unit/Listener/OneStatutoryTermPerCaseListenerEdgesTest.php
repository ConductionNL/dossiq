<?php

/**
 * The paths of the one-statutory-term listener that write nothing, or fail softly.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Listener\OneStatutoryTermPerCaseListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of OneStatutoryTermPerCaseListener.
 *
 * @covers \OCA\Dossiq\Listener\OneStatutoryTermPerCaseListener
 * @uses   \OCA\Dossiq\Service\TermKind
 * @uses   \OCA\Dossiq\Service\Support\ReferencedUuid
 */
class OneStatutoryTermPerCaseListenerEdgesTest extends TestCase {

	/**
	 * A term instance about to be created is not yet a term to withdraw others for.
	 *
	 * @return void
	 */
	public function testATermBeingCreatedIsNotActedOn(): void {
		$terms = $this->createMock(TermijnServiceInterface::class);
		$terms->expects(self::never())->method('instancesForCase');

		$this->listener(terms: $terms)->handle(new ObjectCreatingEvent(self::term()));
	}//end testATermBeingCreatedIsNotActedOn()

	/**
	 * Without a configured term-instance schema nothing is recognised as a term.
	 *
	 * @return void
	 */
	public function testWithoutATermSchemaNothingIsWithdrawn(): void {
		$terms = $this->createMock(TermijnServiceInterface::class);
		$terms->expects(self::never())->method('instancesForCase');

		$this->listener(terms: $terms, schema: '')->handle(new ObjectCreatedEvent(self::term()));
	}//end testWithoutATermSchemaNothingIsWithdrawn()

	/**
	 * A term without a case has no sibling to withdraw.
	 *
	 * @return void
	 */
	public function testATermWithoutACaseWithdrawsNothing(): void {
		$terms = $this->createMock(TermijnServiceInterface::class);
		$terms->expects(self::never())->method('instancesForCase');

		$this->listener(terms: $terms)->handle(new ObjectCreatedEvent(self::term(case: '')));
	}//end testATermWithoutACaseWithdrawsNothing()

	/**
	 * A lookup that fails is logged with the case and does not escape the event.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsLoggedWithTheCase(): void {
		$terms = $this->createMock(TermijnServiceInterface::class);
		$terms->method('instancesForCase')->willThrowException(new RuntimeException('register down'));
		$terms->expects(self::never())->method('updateTermijnInstance');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq: a new statutory term could not withdraw the one running before it',
			['case' => 'case-1', 'exception' => 'register down'],
		);

		$this->listener(terms: $terms, logger: $logger)->handle(new ObjectCreatedEvent(self::term()));
	}//end testAFailingLookupIsLoggedWithTheCase()

	/**
	 * A withdrawal that fails part-way is logged once and stops there.
	 *
	 * @return void
	 */
	public function testAFailingWithdrawalIsLogged(): void {
		$terms = $this->createMock(TermijnServiceInterface::class);
		$terms->method('instancesForCase')->willReturn([
			['id' => 'engine-term', 'case' => 'case-1', 'status' => 'lopend'],
			['id' => 'set-term', 'case' => 'case-1', 'kind' => 'statutory', 'status' => 'lopend'],
		]);
		$terms->expects(self::once())->method('updateTermijnInstance')
			->with('engine-term', ['status' => 'withdrawn'])
			->willThrowException(new RuntimeException('refused'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq: a new statutory term could not withdraw the one running before it',
			['case' => 'case-1', 'exception' => 'refused'],
		);

		$this->listener(terms: $terms, logger: $logger)->handle(new ObjectCreatedEvent(self::term()));
	}//end testAFailingWithdrawalIsLogged()

	/**
	 * The listener.
	 *
	 * @param TermijnServiceInterface $terms  The term service.
	 * @param string                  $schema The configured term-instance schema.
	 * @param LoggerInterface|null    $logger The logger.
	 *
	 * @return OneStatutoryTermPerCaseListener The listener.
	 */
	private function listener(TermijnServiceInterface $terms, string $schema = '17', ?LoggerInterface $logger = null): OneStatutoryTermPerCaseListener {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnMap([['termijn_instance_schema', '', $schema]]);

		return new OneStatutoryTermPerCaseListener(
			settingsService: $settings,
			terms: $terms,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A statutory term instance on a case.
	 *
	 * @param string $case The case uuid.
	 *
	 * @return ObjectEntity The term instance.
	 */
	private static function term(string $case = 'case-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('set-term');
		$entity->setSchema('17');
		$entity->setObject(['case' => $case, 'kind' => 'statutory', 'status' => 'lopend']);

		return $entity;
	}//end term()
}//end class
