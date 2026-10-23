<?php

/**
 * Email template moment and channel tests
 *
 * A message template says when it goes out (`moment`, a case event) and how
 * (`channel`). Creating a template stores both; a new version keeps the ones
 * the edit does not name, and the message category with them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Email\EmailTemplateRepository;
use OCA\Dossiq\Service\EmailTemplateService;
use OCA\Dossiq\Service\IntegrationStatusService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Moment and channel through createTemplate() and updateTemplate().
 *
 * @covers \OCA\Dossiq\Service\EmailTemplateService
 *
 * @spec openspec/changes/case-type-messages-moment-channel/specs/case-types/spec.md
 */
class EmailTemplateMomentChannelTest extends TestCase {

	/**
	 * @var EmailTemplateRepository&MockObject
	 */
	private EmailTemplateRepository $repository;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	private EmailTemplateService $service;

	/**
	 * Build the service over a repository that records what it saves.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(EmailTemplateRepository::class);
		$this->repository->method('saveTemplate')->willReturnCallback(
			function (array $payload): array {
				$this->saved[] = $payload;
				return $payload;
			}
		);
		$this->service = new EmailTemplateService(
			repository: $this->repository,
			logger: new NullLogger(),
			integrationStatus: $this->createMock(IntegrationStatusService::class),
		);
	}//end setUp()

	/**
	 * A new template stores its moment and channel.
	 *
	 * @return void
	 */
	public function testCreateStoresMomentAndChannel(): void {
		$saved = $this->service->createTemplate(
			caseTypeId: 'ct-1',
			data: ['name' => 'Ontvangstbevestiging', 'moment' => 'case-received', 'channel' => 'mailbox-then-email'],
		);

		$this->assertSame('case-received', $saved['moment']);
		$this->assertSame('mailbox-then-email', $saved['channel']);
	}//end testCreateStoresMomentAndChannel()

	/**
	 * A template without them stores neither, rather than an empty string.
	 *
	 * @return void
	 */
	public function testCreateLeavesOutAnUnsetMomentAndChannel(): void {
		$saved = $this->service->createTemplate(caseTypeId: 'ct-1', data: ['name' => 'Los bericht']);

		$this->assertArrayNotHasKey('moment', $saved);
		$this->assertArrayNotHasKey('channel', $saved);
	}//end testCreateLeavesOutAnUnsetMomentAndChannel()

	/**
	 * A new version keeps the moment, channel and category the edit does not name.
	 *
	 * @return void
	 */
	public function testUpdateKeepsWhatTheEditDoesNotName(): void {
		$this->repository->method('findTemplate')->willReturn(
			[
				'id'              => 't-1',
				'caseType'        => 'ct-1',
				'name'            => 'Besluit',
				'subject'         => 'Besluit',
				'body'            => 'Tekst',
				'version'         => 2,
				'moment'          => 'decision-made',
				'channel'         => 'mailbox',
				'messageCategory' => 'besluit',
			]
		);

		$saved = $this->service->updateTemplate(templateId: 't-1', data: ['body' => 'Nieuwe tekst', 'channel' => 'post']);

		$this->assertSame('decision-made', $saved['moment']);
		$this->assertSame('post', $saved['channel']);
		$this->assertSame('besluit', $saved['messageCategory']);
		$this->assertSame(3, $saved['version']);
	}//end testUpdateKeepsWhatTheEditDoesNotName()

	/**
	 * An edit that names a field as null or empty clears it on the new version.
	 *
	 * @return void
	 */
	public function testUpdateClearsAFieldTheEditSetsToNothing(): void {
		$this->repository->method('findTemplate')->willReturn(
			['id' => 't-1', 'caseType' => 'ct-1', 'name' => 'Besluit', 'version' => 1, 'moment' => 'decision-made', 'channel' => 'mailbox']
		);

		$saved = $this->service->updateTemplate(templateId: 't-1', data: ['moment' => null, 'channel' => '']);

		$this->assertArrayNotHasKey('moment', $saved);
		$this->assertArrayNotHasKey('channel', $saved);
	}//end testUpdateClearsAFieldTheEditSetsToNothing()
}//end class
