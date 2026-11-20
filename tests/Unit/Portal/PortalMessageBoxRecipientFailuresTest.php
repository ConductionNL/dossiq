<?php

/**
 * Portal message box recipient failures test
 *
 * The applicant's BSN is handed out only when everything checks; a read that
 * throws is logged without the identity and names nobody, and a BSN of nine
 * zeroes, which passes the weighted sum, is still refused.
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

use OCA\Dossiq\Portal\PortalMessageBoxRecipient;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Portal\PortalMessageBoxRecipient
 */
class PortalMessageBoxRecipientFailuresTest extends TestCase {

	/**
	 * The service over an object service.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param LoggerInterface        $logger  The logger.
	 *
	 * @return PortalMessageBoxRecipient
	 */
	private function service(ObjectServiceInterface $objects, LoggerInterface $logger): PortalMessageBoxRecipient {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'portaal_bericht_schema' => 'portaalBericht',
		][$key] ?? '');
		return new PortalMessageBoxRecipient($settings, $logger);
	}//end service()

	/**
	 * A read that throws names nobody and logs only the exception's class.
	 *
	 * @return void
	 */
	public function testAFailingReadNamesNobody(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('find')->willThrowException(new RuntimeException('999993653 not readable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the message box recipient could not be read',
			['exception' => RuntimeException::class]
		);

		$this->assertNull($this->service($objects, $logger)->forMessage('msg-letter'));
	}//end testAFailingReadNamesNobody()

	/**
	 * Nine zeroes pass the weighted sum with zero and are refused.
	 *
	 * @return void
	 */
	public function testAllZeroesIsNoBsn(): void {
		$rows = [
			'msg-letter' => ['direction' => 'handler_to_citizen', 'caseId' => 'case-1', 'recipientRef' => 'subject-a'],
			'case-1' => ['initiatorType' => 'person', 'initiatorSourceId' => '000000000', 'portalSubject' => 'subject-a'],
		];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('find')->willReturnCallback(function ($id) use ($rows) {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($rows[$id]);
			return $entity;
		});

		$this->assertNull($this->service($objects, new NullLogger())->forMessage('msg-letter'));
	}//end testAllZeroesIsNoBsn()
}//end class
