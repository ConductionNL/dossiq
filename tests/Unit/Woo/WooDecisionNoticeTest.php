<?php

/**
 * Woo Decision Notice Test
 *
 * The message a resident gets when the decision on their Woo request is
 * published: one language, a subject that says what happened, a link to the
 * publication on the site, dossiq's own rule key so portaliq sends the
 * e-mail, and a link back to the case. The written message is checked
 * against portaliq's real `portalMessage` schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDecisionNotice;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Woo\WooDecisionNotice
 * @uses   \OCA\Dossiq\Portal\PortalContributionProvider
 */
class WooDecisionNoticeTest extends TestCase {

	/**
	 * The store the message lands in.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The languages asked for.
	 *
	 * @var array<int, string>
	 */
	private array $languages = [];

	/**
	 * The writer under test.
	 *
	 * @var WooDecisionNotice
	 */
	private WooDecisionNotice $notice;

	/**
	 * A writer over an in-memory store, a Dutch translator and a site on a port.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'http://localhost:8080' . $path);

		$dutch = [
			'The decision on your Woo request has been published' => 'Het besluit op uw Woo-verzoek is gepubliceerd',
			'We have published the decision on your Woo request "%1$s".' => 'Wij hebben het besluit op uw Woo-verzoek "%1$s" gepubliceerd.',
			'Read the decision and the documents made public here: %1$s' => 'Lees het besluit en de openbaar gemaakte documenten hier: %1$s',
		];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($dutch[$text] ?? $text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $lang = null) use ($l10n): IL10N {
				$this->languages[] = $app . ':' . (string)$lang;
				return $l10n;
			}
		);

		$this->notice = new WooDecisionNotice(
			settingsService: $settings,
			urlGenerator: $urls,
			l10nFactory: $factory,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The message says the decision is published, in Dutch, and links to the publication on the site.
	 *
	 * @return void
	 */
	public function testTheMessageSaysWhatHappenedAndLinksToThePublication(): void {
		$told = $this->notice->tell(
			case: ['title' => 'Verlichting fietspad', 'portalSubject' => 'subject-1'],
			caseId: 'case-1',
			publicationId: 'pub-1',
		);

		self::assertTrue($told);
		$messages = $this->store->all(schema: 'portalMessage');
		self::assertCount(1, $messages);
		$message = array_values($messages)[0];

		self::assertSame('subject-1', $message['subjectRef']);
		self::assertSame('Het besluit op uw Woo-verzoek is gepubliceerd', $message['subject']);
		self::assertSame(
			"Wij hebben het besluit op uw Woo-verzoek \"Verlichting fietspad\" gepubliceerd.\n\n"
			. 'Lees het besluit en de openbaar gemaakte documenten hier: http://localhost:8080/index.php/apps/portaliq/site?route=/publicatie/pub-1',
			$message['body']
		);
		self::assertSame(PortalContributionProvider::RULE_WOO_REQUEST_PUBLISHED, $message['ruleKey']);
		self::assertSame(['app' => 'dossiq', 'collection' => 'mijnZaken', 'id' => 'case-1'], $message['recordLink']);
		self::assertFalse($message['read']);
		self::assertSame(['dossiq:nl'], $this->languages);
	}//end testTheMessageSaysWhatHappenedAndLinksToThePublication()

	/**
	 * The message fits portaliq's real portalMessage schema: known properties, the right types.
	 *
	 * @return void
	 */
	public function testTheMessageFitsPortaliqsPortalMessageSchema(): void {
		$this->notice->tell(case: ['title' => 'Verlichting fietspad', 'portalSubject' => 'subject-1'], caseId: 'case-1', publicationId: 'pub-1');
		$message = array_values($this->store->all(schema: 'portalMessage'))[0];
		unset($message['id']);

		// The fragment as portaliq ships it (lib/Settings/portaliq_register.json, portalMessage 0.6.0).
		$properties = [
			'subjectRef' => 'string', 'subject' => 'string', 'body' => 'string', 'read' => 'boolean',
			'receivedAt' => 'string', 'ruleKey' => 'string', 'recordLink' => 'array',
		];
		foreach (['subjectRef', 'subject'] as $required) {
			self::assertNotSame('', (string)($message[$required] ?? ''));
		}

		foreach ($message as $key => $value) {
			self::assertArrayHasKey($key, $properties, 'portalMessage has no property ' . $key);
			self::assertSame($properties[$key], gettype($value) === 'array' ? 'array' : gettype($value), $key);
		}

		self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $message['receivedAt']));
	}//end testTheMessageFitsPortaliqsPortalMessageSchema()

	/**
	 * A case nobody follows on the portal gets no message.
	 *
	 * @return void
	 */
	public function testACaseWithoutAResidentTellsNobody(): void {
		self::assertFalse($this->notice->tell(case: ['title' => 'Intern'], caseId: 'case-1', publicationId: 'pub-1'));
		self::assertFalse($this->notice->tell(case: ['portalSubject' => 'subject-1'], caseId: 'case-1', publicationId: ''));
		self::assertSame([], $this->store->all(schema: 'portalMessage'));
	}//end testACaseWithoutAResidentTellsNobody()

	/**
	 * A message that cannot be written costs the notice, never the publish.
	 *
	 * @return void
	 */
	public function testAFailedWriteDoesNotThrow(): void {
		$settings = $this->createMock(SettingsService::class);
		$broken = $this->createMock(InMemoryRegister::class);
		$broken->method('saveObject')->willThrowException(new RuntimeException('portaliq register missing'));
		$settings->method('getObjectService')->willReturn($broken);

		$notice = new WooDecisionNotice(
			settingsService: $settings,
			urlGenerator: $this->createMock(IURLGenerator::class),
			l10nFactory: $this->createMock(IFactory::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertFalse($notice->tell(case: ['title' => 'T', 'portalSubject' => 'subject-1'], caseId: 'case-1', publicationId: 'pub-1'));
	}//end testAFailedWriteDoesNotThrow()
}//end class
