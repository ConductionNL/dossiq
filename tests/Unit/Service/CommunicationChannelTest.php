<?php

/**
 * Tests for the case's communication channel slugs and their ZGW mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
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

use OCA\Dossiq\Service\CommunicationChannel;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class CommunicationChannelTest extends TestCase {

	/**
	 * A ZGW communicatiekanaal URL from the referentielijsten.
	 */
	private const EMAIL_URL = 'https://referentielijsten.example/api/v1/communicatiekanalen/2b757868-45c2-42ca-b637-b63ddc87beb4';

	/**
	 * The channel map with one configured URL.
	 *
	 * @return CommunicationChannel
	 */
	private function channels(): CommunicationChannel {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === CommunicationChannel::MAP_KEY
				? (string)json_encode([self::EMAIL_URL => 'email', 'https://x.example/bad' => 'telefoon'])
				: $default
		);

		return new CommunicationChannel(appConfig: $config);
	}//end channels()

	/**
	 * A slug stays, words of an older intake become their slug, nothing stays nothing.
	 *
	 * @return void
	 */
	public function testSlugsAndWordsBecomeSlugs(): void {
		$channels = $this->channels();
		foreach (CommunicationChannel::SLUGS as $slug) {
			$this->assertSame($slug, $channels->toSlug(value: $slug));
			$this->assertSame($slug, $channels->toSlug(value: strtoupper($slug)));
		}

		$this->assertSame('email', $channels->toSlug(value: 'E-mail'));
		$this->assertSame('post', $channels->toSlug(value: ' Brief '));
		$this->assertSame('portal', $channels->toSlug(value: 'Mijn omgeving'));
		$this->assertSame('website', $channels->toSlug(value: 'webformulier'));
		$this->assertNull($channels->toSlug(value: ''));
		$this->assertNull($channels->toSlug(value: null));
		$this->assertNull($channels->toSlug(value: 'telefoon'));
	}//end testSlugsAndWordsBecomeSlugs()

	/**
	 * A ZGW URL becomes its configured slug, and any other URL `zgw-api`, so
	 * no case the ZGW API creates is refused. A configured slug that is not a
	 * slug is ignored.
	 *
	 * @return void
	 */
	public function testAZgwUrlAlwaysBecomesASlug(): void {
		$channels = $this->channels();
		$this->assertSame('email', $channels->toSlug(value: self::EMAIL_URL));
		$this->assertSame('zgw-api', $channels->toSlug(value: 'https://other.example/api/v1/communicatiekanalen/1'));
		$this->assertSame('zgw-api', $channels->toSlug(value: 'https://x.example/bad'));
		$this->assertTrue($channels->isUrl(value: self::EMAIL_URL));
		$this->assertFalse($channels->isUrl(value: 'email'));
	}//end testAZgwUrlAlwaysBecomesASlug()

	/**
	 * Outbound, a case answers with the URL it came in with, else the URL
	 * configured for its slug, else nothing.
	 *
	 * @return void
	 */
	public function testOutboundGivesBackAUrlOrNothing(): void {
		$channels = $this->channels();
		$this->assertSame('https://kept.example/k/1', $channels->toZgwUrl(slug: 'zgw-api', source: 'https://kept.example/k/1'));
		$this->assertSame(self::EMAIL_URL, $channels->toZgwUrl(slug: 'email', source: 'E-mail'));
		$this->assertSame('', $channels->toZgwUrl(slug: 'post', source: ''));
		$this->assertSame('', $channels->toZgwUrl(slug: null, source: null));
	}//end testOutboundGivesBackAUrlOrNothing()

	/**
	 * The normalised pair a write carries: the slug, and the original value
	 * when it was not a slug.
	 *
	 * @return void
	 */
	public function testNormaliseKeepsWhatItReplaced(): void {
		$channels = $this->channels();
		$this->assertSame(['communicationChannel' => 'email'], $channels->normalise(value: 'email'));
		$this->assertSame(
			['communicationChannel' => 'email', 'communicationChannelSource' => self::EMAIL_URL],
			$channels->normalise(value: self::EMAIL_URL)
		);
		$this->assertSame(
			['communicationChannel' => null, 'communicationChannelSource' => 'telefoon'],
			$channels->normalise(value: 'telefoon')
		);
		$this->assertSame([], $channels->normalise(value: '  '));
	}//end testNormaliseKeepsWhatItReplaced()

	/**
	 * The case schema takes exactly the slugs, and refuses a URL there.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaIsTheSlugEnum(): void {
		$register = new RealSchemaValidator();
		$property = $register->schemas['case']['properties']['communicationChannel'];
		$this->assertSame(CommunicationChannel::SLUGS, array_values(array_filter($property['enum'], static fn ($v): bool => $v !== null)));
		$this->assertArrayHasKey('communicationChannelSource', $register->schemas['case']['properties']);
		$this->assertSame([], $register->errors(slug: 'case', payload: ['communicationChannel' => 'portal', 'communicationChannelSource' => self::EMAIL_URL], creating: false));
		$this->assertNotSame([], $register->errors(slug: 'case', payload: ['communicationChannel' => self::EMAIL_URL], creating: false));
	}//end testTheCaseSchemaIsTheSlugEnum()

	/**
	 * Inbound at the ZGW boundary: the URL becomes a slug and is kept; an
	 * empty value writes nothing; a mapped URL in the channel is converted too.
	 *
	 * @return void
	 */
	public function testTheZgwBoundaryNeverWritesAUrlIntoTheChannel(): void {
		$channels = $this->channels();
		$this->assertSame(
			['title' => 'x', 'communicationChannel' => 'email', 'communicationChannelSource' => self::EMAIL_URL],
			$channels->inbound(body: ['communicatiekanaal' => self::EMAIL_URL], mapped: ['title' => 'x'])
		);
		$this->assertSame(['title' => 'x'], $channels->inbound(body: ['communicatiekanaal' => ''], mapped: ['title' => 'x', 'communicationChannel' => '']));
		$this->assertSame(['title' => 'x'], $channels->inbound(body: [], mapped: ['title' => 'x']));
		$this->assertSame(
			['communicationChannel' => 'zgw-api', 'communicationChannelSource' => 'https://o.example/k/9'],
			$channels->inbound(body: [], mapped: ['communicationChannel' => 'https://o.example/k/9'])
		);
	}//end testTheZgwBoundaryNeverWritesAUrlIntoTheChannel()

	/**
	 * Outbound at the ZGW boundary: the zaak carries the URL it came in with.
	 *
	 * @return void
	 */
	public function testTheZgwBoundaryAnswersWithTheReceivedUrl(): void {
		$channels = $this->channels();
		$zaak = $channels->outbound(case: ['communicationChannel' => 'zgw-api', 'communicationChannelSource' => 'https://o.example/k/9'], mapped: ['url' => 'u']);
		$this->assertSame(['url' => 'u', 'communicatiekanaal' => 'https://o.example/k/9'], $zaak);
		$this->assertSame('', $channels->outbound(case: ['communicationChannel' => 'post'], mapped: [])['communicatiekanaal']);
	}//end testTheZgwBoundaryAnswersWithTheReceivedUrl()
}//end class
