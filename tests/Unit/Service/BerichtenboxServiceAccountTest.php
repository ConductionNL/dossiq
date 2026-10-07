<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Berichtenbox letter record writes as the background service account
 * when nobody is signed in.
 *
 * Integriq raises DigitalPostDeliveredEvent from its own background work, so
 * the status update reached BerichtenboxService with nobody signed in and
 * OpenRegister refused the caseBerichtenboxMessage write as Anonymous: the
 * case went on showing "sent" for a letter that was delivered, read or
 * failed. A send with no session (a flow, an occ run) had the same hole, and
 * worse: the letter left and its record was refused. These tests run the real
 * service and a real journal against a register that refuses a write from
 * nobody, the way OpenRegister does.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the identity the letter record writes as.
 */
class BerichtenboxServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The schema id the register hands out for caseBerichtenboxMessage.
	 */
	private const SCHEMA = '31';

	/**
	 * The register the service writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How many letters the adapter handed over.
	 *
	 * @var int
	 */
	private int $sends = 0;

	/**
	 * Reset the session and the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acting = null;
		$this->configuredAccount = 'dossiq-achtergrond';
		$this->adminNotices = 0;
		$this->sends = 0;
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: self::SCHEMA,
			id: 'message-1',
			row: ['case' => 'case-1', 'externalMessageId' => 'dp-4711', 'status' => 'sent', 'subject' => 'Besluit']
		);
	}//end setUp()

	/**
	 * A status integriq reports with nobody signed in lands as the account.
	 *
	 * @return void
	 */
	public function testAStatusWithNobodySignedInWritesAsTheServiceAccount(): void {
		$recorded = $this->service()->recordDeliveryStatus(externalMessageId: 'dp-4711', status: 'delivered');

		$this->assertTrue($recorded);
		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: [self::SCHEMA]);
		$this->assertSame('delivered', $this->register->row(schema: self::SCHEMA, id: 'message-1')['status']);
	}//end testAStatusWithNobodySignedInWritesAsTheServiceAccount()

	/**
	 * A send with nobody signed in records the letter as the account.
	 *
	 * @return void
	 */
	public function testASendWithNobodySignedInRecordsTheLetterAsTheServiceAccount(): void {
		$this->service()->sendMessage(
			caseId: 'case-2',
			bsn: '999993653',
			subject: 'Uw aanvraag',
			body: 'Wij hebben uw aanvraag ontvangen.',
			typeCode: 'besluit'
		);

		$this->assertSame(1, $this->sends);
		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: [self::SCHEMA]);
	}//end testASendWithNobodySignedInRecordsTheLetterAsTheServiceAccount()

	/**
	 * A handler who pressed Send stays the writer.
	 *
	 * @return void
	 */
	public function testASignedInHandlerStaysTheWriter(): void {
		$this->acting = $this->backgroundUser(uid: 'behandelaar-1');

		$this->service()->sendMessage(
			caseId: 'case-2',
			bsn: '999993653',
			subject: 'Uw aanvraag',
			body: 'Wij hebben uw aanvraag ontvangen.',
			typeCode: 'besluit'
		);

		$this->assertSame([], $this->register->refusals);
		$this->assertSame(['behandelaar-1'], $this->register->writers());
		$this->assertSame('behandelaar-1', $this->actingUid());
	}//end testASignedInHandlerStaysTheWriter()

	/**
	 * Without an account nothing leaves and nothing is written.
	 *
	 * A letter that goes out with nobody able to record it is the worst of the
	 * two outcomes, so the send is refused before the adapter is asked.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountNothingIsSentOrWritten(): void {
		$this->configuredAccount = '';

		$sent = $this->service()->sendMessage(
			caseId: 'case-2',
			bsn: '999993653',
			subject: 'Uw aanvraag',
			body: 'Wij hebben uw aanvraag ontvangen.',
			typeCode: 'besluit'
		);
		$recorded = $this->service()->recordDeliveryStatus(externalMessageId: 'dp-4711', status: 'read');

		$this->assertSame(0, $this->sends);
		$this->assertArrayHasKey('error', $sent);
		$this->assertFalse($recorded);
		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('sent', $this->register->row(schema: self::SCHEMA, id: 'message-1')['status']);
	}//end testWithoutAnAccountNothingIsSentOrWritten()

	/**
	 * The real service over the refusing register.
	 *
	 * @return BerichtenboxService The service.
	 */
	private function service(): BerichtenboxService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '7',
				'berichtenbox_message_schema' => self::SCHEMA,
				default => '',
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'integriq']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService());

		$adapter = $this->createMock(BerichtenboxAdapterInterface::class);
		$adapter->method('sendMessage')->willReturnCallback(
			function (): array {
				$this->sends++;
				return ['messageId' => 'dp-5000', 'status' => 'sent', 'sentAt' => '2026-10-07T12:00:00+00:00'];
			}
		);

		$timeline = $this->getMockBuilder(CaseTimeline::class)->disableOriginalConstructor()->getMock();

		return $this->buildWith(
			BerichtenboxService::class,
			[
				'settingsService' => $settings,
				'appManager' => $apps,
				'container' => $container,
				'logger' => new NullLogger(),
				'owningCase' => $this->getMockBuilder(OwningCaseResolver::class)->disableOriginalConstructor()->getMock(),
				'adapter' => $adapter,
				'journal' => new BerichtenboxJournal($timeline),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end service()

	/**
	 * The OpenRegister contract, answered by the refusing register.
	 *
	 * @return object The double.
	 */
	private function objectService(): object {
		$register = $this->register;
		$service = $this->createMock('OCA\\OpenRegister\\Contract\\ObjectServiceInterface');
		$service->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => $register->findAll(config: $config)
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $registerId = null, string|int|null $schema = null, ?string $uuid = null) use ($register): object {
				// The mock hands the arguments on by position, in the
				// contract's order: extend, register, schema, uuid.
				$stored = $register->saveObject(object: $object, register: $registerId, schema: $schema, uuid: $uuid);
				$entity = $this->createMock('OCA\\OpenRegister\\Contract\\ObjectEntityInterface');
				$entity->method('jsonSerialize')->willReturn($stored);

				return $entity;
			}
		);

		return $service;
	}//end objectService()
}//end class
