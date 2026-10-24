<?php

/**
 * Unit tests for ForfeitInvoiceListener.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\ForfeitInvoiceListenerRegistrar;
use OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException;
use OCA\Dossiq\Listener\ForfeitInvoiceListener;
use OCA\Dossiq\Service\Money\ForfeitInvoiceRaiser;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The raise runs once, when an enforcement act becomes forfeited.
 */
final class ForfeitInvoiceListenerTest extends TestCase {
	/**
	 * An enforcement act entity.
	 *
	 * @param string $status Its status.
	 * @param string $schema Its schema.
	 *
	 * @return ObjectEntity
	 */
	private static function act(string $status, string $schema = '42'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('act-1');
		$entity->setSchema($schema);
		$entity->setObject(['case' => 'case-7', 'status' => $status, 'penaltyPaymentAmount' => 2500]);

		return $entity;
	}//end act()

	/**
	 * The listener over a raiser that expects the given number of raises.
	 *
	 * @param int $raises How often the raise must run.
	 *
	 * @return ForfeitInvoiceListener
	 */
	private function listener(int $raises): ForfeitInvoiceListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($key === 'handhavingsactie_schema') ? '42' : '');
		$raiser = $this->createMock(ForfeitInvoiceRaiser::class);
		$raiser->expects(self::exactly($raises))->method('raise')
			->with(self::callback(static fn (array $action): bool => $action['status'] === 'verbeurd' && $action['case'] === 'case-7'));

		return new ForfeitInvoiceListener(settingsService: $settings, raiser: $raiser, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	public function testBecomingForfeitedRaisesOnce(): void {
		$this->listener(raises: 1)->handle(new ObjectUpdatedEvent(self::act('verbeurd'), self::act('opgelegd')));
	}//end testBecomingForfeitedRaisesOnce()

	public function testAnythingElseRaisesNothing(): void {
		$listener = $this->listener(raises: 0);
		$listener->handle(new ObjectUpdatedEvent(self::act('verbeurd'), self::act('verbeurd')));
		$listener->handle(new ObjectUpdatedEvent(self::act('geeffectueerd'), self::act('opgelegd')));
		$listener->handle(new ObjectUpdatedEvent(self::act('verbeurd', schema: '7'), self::act('opgelegd', schema: '7')));
	}//end testAnythingElseRaisesNothing()

	public function testAnInvoiceNotRaisedIsLoggedAndTheSaveStands(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($key === 'handhavingsactie_schema') ? '42' : '');
		$raiser = $this->createMock(ForfeitInvoiceRaiser::class);
		$raiser->method('raise')->willThrowException(
			new ForfeitInvoiceNotRaisedException(reasonCode: ForfeitInvoiceNotRaisedException::FINANCE_REFUSED, caseId: 'case-7', previous: new RuntimeException('403'))
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with(self::anything(), ['case' => 'case-7', 'reason' => 'finance-refused', 'error' => '403']);

		(new ForfeitInvoiceListener(settingsService: $settings, raiser: $raiser, logger: $logger))
			->handle(new ObjectUpdatedEvent(self::act('verbeurd'), self::act('opgelegd')));
	}//end testAnInvoiceNotRaisedIsLoggedAndTheSaveStands()

	public function testTheListenerIsRegisteredOnTheUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new ForfeitInvoiceListenerRegistrar())->register(context: $context);

		self::assertSame([[ObjectUpdatedEvent::class, ForfeitInvoiceListener::class]], $registered);
	}//end testTheListenerIsRegisteredOnTheUpdate()
}//end class
