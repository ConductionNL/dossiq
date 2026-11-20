<?php

/**
 * Portal case messages failures test
 *
 * The resident's messages on a case are an extra on the case page: a schema
 * that is not configured, a case without a portal subject or a read that
 * throws answers an empty inbox, never an error and never someone else's mail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseMessages;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Portal\PortalCaseMessages
 */
class PortalCaseMessagesFailuresTest extends TestCase {
	private const CASE_ID = '22222222-2222-4222-8222-222222222222';

	/**
	 * The configured values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'case_schema' => 'case', 'portaal_bericht_schema' => 'portaalBericht'];

	/**
	 * The service over an object service.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param LoggerInterface|null   $logger  The logger.
	 *
	 * @return PortalCaseMessages
	 */
	private function service(ObjectServiceInterface $objects, ?LoggerInterface $logger = null): PortalCaseMessages {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
		return new PortalCaseMessages($settings, ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * An object service that runs operations as the system.
	 *
	 * @return ObjectServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		return $objects;
	}//end objects()

	/**
	 * A blank case id is never read.
	 *
	 * @return void
	 */
	public function testABlankCaseIdIsNotRead(): void {
		$objects = $this->objects();
		$objects->expects($this->never())->method('find');

		$this->assertSame([], $this->service($objects)->forCase('   '));
	}//end testABlankCaseIdIsNotRead()

	/**
	 * Without a message schema there is no inbox and the case is not read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredMessageSchemaAnswersNothing(): void {
		$this->config['portaal_bericht_schema'] = '';
		$objects = $this->objects();
		$objects->expects($this->never())->method('find');

		$this->assertSame([], $this->service($objects)->forCase(self::CASE_ID));
	}//end testAnUnconfiguredMessageSchemaAnswersNothing()

	/**
	 * A case without a portal subject has no resident, so no message is searched.
	 *
	 * @return void
	 */
	public function testACaseWithoutAPortalSubjectAnswersNothing(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => self::CASE_ID, 'portalSubject' => '']);
		$objects = $this->objects();
		$objects->method('find')->willReturn($entity);
		$objects->expects($this->never())->method('searchObjectsBySlug');

		$this->assertSame([], $this->service($objects)->forCase(self::CASE_ID));
	}//end testACaseWithoutAPortalSubjectAnswersNothing()

	/**
	 * A read that throws is logged and answers an empty inbox.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndAnswersNothing(): void {
		$objects = $this->objects();
		$objects->method('find')->willThrowException(new RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the portal messages of a case could not be read',
			['reason' => 'store down']
		);

		$this->assertSame([], $this->service($objects, $logger)->forCase(self::CASE_ID));
	}//end testAFailingReadIsLoggedAndAnswersNothing()

	/**
	 * A message without a direction is between nobody.
	 *
	 * @return void
	 */
	public function testAMessageWithoutADirectionIsNotTheResidents(): void {
		$service = $this->service($this->objects());

		$this->assertFalse($service->isTheResidents(['senderRef' => 'r', 'recipientRef' => 'r'], 'r'));
		$this->assertTrue($service->isTheResidents(['direction' => 'citizen_to_handler', 'senderRef' => 'r'], 'r'));
		$this->assertFalse($service->isTheResidents(['direction' => 'handler_to_citizen', 'senderRef' => 'r', 'recipientRef' => 'x'], 'r'));
	}//end testAMessageWithoutADirectionIsNotTheResidents()
}//end class
