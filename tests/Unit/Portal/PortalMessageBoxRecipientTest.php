<?php

/**
 * Portal message box recipient test
 *
 * dossiq#3192: portaliq sends a portal inbox letter to the resident's
 * government message box too, but holds no BSN, so the case app names the
 * recipient. Contract read at portaliq development 4176916 (#913):
 * `lib/Contribution/MessageBoxConfigNormaliser.php` keeps
 * `messageBox: {recipientProvider}` only on a `kind: inbox` collection whose
 * method name `TimelineProviderMethod::accepts()`, and
 * `lib/Service/Notifications/MessageBoxSender.php::recipient()` calls
 * `$provider->{$method}($messageId)` and sends nothing unless it gets a
 * non-empty string.
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
 * @spec openspec/changes/portal-message-box-recipient/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Portal\PortalMessageBoxRecipient;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Portal\PortalMessageBoxRecipient
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 */
class PortalMessageBoxRecipientTest extends TestCase {
	private const BSN = '999993653';

	/**
	 * Objects by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Whether a read ran inside runAsSystem.
	 *
	 * @var bool
	 */
	private bool $asSystem = false;

	protected function setUp(): void {
		parent::setUp();
		$letter = ['direction' => 'handler_to_citizen', 'caseId' => 'case-1', 'recipientRef' => 'subject-a', 'subject' => 'Uw aanvraag'];
		$this->objects = [
			'case-1' => ['initiatorType' => 'person', 'initiatorSourceId' => self::BSN, 'portalSubject' => 'subject-a'],
			'case-company' => ['initiatorType' => 'company', 'initiatorSourceId' => '12345678', 'portalSubject' => 'subject-a'],
			'case-badbsn' => ['initiatorType' => 'person', 'initiatorSourceId' => '123456789', 'portalSubject' => 'subject-a'],
			'msg-letter' => $letter,
			'msg-reply' => ['direction' => 'citizen_to_handler'] + $letter,
			'msg-representative' => ['recipientRef' => 'subject-b'] + $letter,
			'msg-company' => ['caseId' => 'case-company'] + $letter,
			'msg-badbsn' => ['caseId' => 'case-badbsn'] + $letter,
			'msg-nocase' => ['caseId' => ''] + $letter,
		];
	}

	/**
	 * The service over a real-contract object service double.
	 *
	 * @return PortalMessageBoxRecipient
	 */
	protected function service(): PortalMessageBoxRecipient {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(function (callable $operation) {
			$this->asSystem = true;
			$result = $operation();
			$this->asSystem = false;
			return $result;
		});
		$objects->method('find')->willReturnCallback(function ($id) {
			$this->assertTrue($this->asSystem, 'the read runs as the system');
			if (isset($this->objects[$id]) === false) {
				return null;
			}

			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($this->objects[$id]);
			return $entity;
		});

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'portaal_bericht_schema' => 'portaalBericht',
		][$key] ?? '');

		return new PortalMessageBoxRecipient($settings, new NullLogger());
	}

	/**
	 * The organisation's letter to the applicant goes to the applicant's BSN.
	 *
	 * @return void
	 */
	public function testALetterToTheApplicantNamesTheirBsn(): void {
		$this->assertSame(self::BSN, $this->service()->forMessage('msg-letter'));
	}

	/**
	 * Everything else answers null, so portaliq sends nothing.
	 *
	 * @return void
	 */
	public function testEverythingElseNamesNobody(): void {
		$service = $this->service();
		foreach (['msg-reply', 'msg-representative', 'msg-company', 'msg-badbsn', 'msg-nocase', 'msg-unknown', ''] as $id) {
			$this->assertNull($service->forMessage($id), $id);
		}
	}

	/**
	 * The inbox declares the method, and the provider answers through the service.
	 *
	 * @return void
	 */
	public function testTheInboxDeclaresTheRecipientMethod(): void {
		$provider = new PortalContributionProvider(messageBox: $this->service());
		$inbox = null;
		foreach ($provider->getContribution(['audience' => 'client'])['collections'] as $collection) {
			if ($collection['id'] === 'berichten') {
				$inbox = $collection;
			}
		}

		$this->assertSame('inbox', $inbox['kind']);
		$this->assertSame(['recipientProvider' => 'messageBoxRecipient'], $inbox['messageBox']);
		$this->assertSame(self::BSN, $provider->messageBoxRecipient('msg-letter'));
		$this->assertNull((new PortalContributionProvider())->messageBoxRecipient('msg-letter'));
	}
}
