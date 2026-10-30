<?php

/**
 * Unit tests for ConsultationTerms: the Adviseringsbeheer settings read.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use DateTimeImmutable;
use OCA\Dossiq\Service\Consultation\ConsultationTerms;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Each setting has a reader.
 */
class ConsultationTermsTest extends TestCase {

	/**
	 * Terms over these settings, on 24 October 2026.
	 *
	 * @param array<string, string> $config The stored settings.
	 *
	 * @return ConsultationTerms
	 */
	private function terms(array $config = []): ConsultationTerms {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ConsultationTerms(settingsService: $settings, today: new DateTimeImmutable('2026-10-24'));
	}//end terms()

	/**
	 * A request without a deadline gets today plus the default term; a given one stays.
	 *
	 * @return void
	 */
	public function testTheDefaultTermFillsAMissingDeadline(): void {
		$this->assertSame('2026-11-14', $this->terms(['consultation_default_deadline_days' => '21'])->deadlineFor(given: ''));
		$this->assertSame('2026-11-21', $this->terms()->deadlineFor(given: null));
		$this->assertSame('2026-11-21', $this->terms(['consultation_default_deadline_days' => 'nonsense'])->deadlineFor(given: ''));
		$this->assertSame('2026-11-21', $this->terms(['consultation_default_deadline_days' => '0'])->deadlineFor(given: ''));
		$this->assertSame('2026-12-01', $this->terms()->deadlineFor(given: '2026-12-01'));
	}//end testTheDefaultTermFillsAMissingDeadline()

	/**
	 * Due soon: deadline today or within the offset, open or in handling.
	 *
	 * @return void
	 */
	public function testDueSoonIsWithinTheWarningOffset(): void {
		$list = [
			['id' => 'a', 'status' => 'open', 'latestResponseDate' => '2026-10-26'],
			['id' => 'b', 'status' => 'open', 'latestResponseDate' => '2026-10-30'],
			['id' => 'c', 'status' => 'in_handling', 'latestResponseDate' => '2026-10-24'],
			['id' => 'd', 'status' => 'open', 'latestResponseDate' => '2026-10-20'],
			['id' => 'e', 'status' => 'closed', 'latestResponseDate' => '2026-10-25'],
		];
		$terms = $this->terms();
		$this->assertSame(5, $terms->warningOffsetDays());
		$this->assertSame(['a', 'c'], array_column($terms->dueSoon(consultations: $list), 'id'));
		$this->assertSame(['a', 'b', 'c'], array_column($this->terms(['consultation_warning_offset_days' => '7'])->dueSoon(consultations: $list), 'id'));
	}//end testDueSoonIsWithinTheWarningOffset()

	/**
	 * The response address: the configured base plus the link's token.
	 *
	 * @return void
	 */
	public function testTheExternalResponseAddressUsesTheConfiguredBase(): void {
		$this->assertSame('https://cloud.zuiddrecht.nl/l/abc', $this->terms()->responseAddress(linkUrl: 'https://cloud.zuiddrecht.nl/l/abc'));
		$base = ['consultation_external_response_url' => 'https://loket.zuiddrecht.nl/advies/'];
		$this->assertSame('https://loket.zuiddrecht.nl/advies/abc', $this->terms($base)->responseAddress(linkUrl: 'https://cloud.zuiddrecht.nl/l/abc'));
		$plain = ['consultation_external_response_url' => 'http://loket.zuiddrecht.nl/advies'];
		$this->assertSame('https://cloud.zuiddrecht.nl/l/abc', $this->terms($plain)->responseAddress(linkUrl: 'https://cloud.zuiddrecht.nl/l/abc'));
	}//end testTheExternalResponseAddressUsesTheConfiguredBase()

	/**
	 * The invitation message names the subject, the deadline and the address.
	 *
	 * @return void
	 */
	public function testTheInvitationMessageCarriesTheAddress(): void {
		$message = $this->terms()->invitationMessage(
			consultation: ['subject' => 'Gevelwijziging Keizersgracht 123', 'latestResponseDate' => '2026-11-21'],
			address: 'https://loket.zuiddrecht.nl/advies/abc'
		);
		$this->assertStringContainsString('Gevelwijziging Keizersgracht 123', $message);
		$this->assertStringContainsString('2026-11-21', $message);
		$this->assertStringContainsString('https://loket.zuiddrecht.nl/advies/abc', $message);
	}//end testTheInvitationMessageCarriesTheAddress()

	/**
	 * Bodies whose late share over 30 days passes the threshold are flagged.
	 *
	 * @return void
	 */
	public function testBottlenecksFlagBodiesAboveTheThreshold(): void {
		$list = [];
		for ($i = 0; $i < 10; $i++) {
			$late = ($i < 3);
			$list[] = [
				'adviceAuthority' => 'Welstandscommissie',
				'createdAt' => '2026-10-10T09:00:00+00:00',
				'status' => ($late === true ? 'open' : 'advice_uitgebracht'),
				'latestResponseDate' => ($late === true ? '2026-10-20' : '2026-11-10'),
			];
		}

		$list[] = ['adviceAuthority' => 'Brandweer', 'createdAt' => '2026-10-12T09:00:00+00:00', 'status' => 'open', 'latestResponseDate' => '2026-11-10'];
		$list[] = ['adviceAuthority' => 'Brandweer', 'createdAt' => '2026-08-01T09:00:00+00:00', 'status' => 'open', 'latestResponseDate' => '2026-08-20'];
		$list[] = ['adviceAuthority' => 'Brandweer', 'createdAt' => '2026-10-01T09:00:00+00:00', 'status' => 'advice_uitgebracht', 'latestResponseDate' => '2026-10-05', 'closedAt' => '2026-10-07T10:00:00+00:00'];

		$report = $this->terms()->bottlenecks(consultations: $list);
		$this->assertSame(0.2, $report['threshold']);
		$bodies = array_column($report['bodies'], null, 'adviceAuthority');
		$this->assertSame(['total' => 10, 'late' => 3, 'lateShare' => 0.3, 'flagged' => true], array_intersect_key($bodies['Welstandscommissie'], ['total' => 1, 'late' => 1, 'lateShare' => 1, 'flagged' => 1]));
		$this->assertSame(2, $bodies['Brandweer']['total'], 'The August request is outside the 30 days');
		$this->assertSame(1, $bodies['Brandweer']['late'], 'Answered after the deadline is late');
		$this->assertTrue($bodies['Brandweer']['flagged']);
		$this->assertFalse($this->terms(['consultation_bottleneck_threshold' => '0.6'])->bottlenecks(consultations: $list)['bodies'][0]['flagged']);
	}//end testBottlenecksFlagBodiesAboveTheThreshold()
}//end class
