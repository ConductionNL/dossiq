<?php

/**
 * The refusals ZgwRulesBase builds for a URL or a combination it cannot accept.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwRulesBase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every refusal the base builds, pinned word for word.
 *
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 * @uses   \OCA\Dossiq\Service\FieldValidator
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses   \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ZgwRulesBaseRefusalsTest extends TestCase {

	/**
	 * A type URL without a UUID refuses the field as a bad URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testATypeUrlWithoutAUuidIsABadUrl(): void {
		$this->assertSame(
			expected: $this->refusal(
				field: 'zaaktype',
				code: 'bad-url',
				detail: 'De zaaktype URL is ongeldig.',
				reason: 'De zaaktype URL is ongeldig of wijst niet naar een zaaktype resource.'
			),
			actual: $this->rules()->typeUrl(url: 'https://example.org/zaaktypen/geen-uuid', field: 'zaaktype')
		);
	}//end testATypeUrlWithoutAUuidIsABadUrl()

	/**
	 * An informatieobject URL that is no URL refuses the informatieobject.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnInformatieobjectUrlThatIsNoUrlIsRefused(): void {
		$this->assertSame(
			expected: $this->refusal(
				field: 'informatieobject',
				code: 'bad-url',
				detail: 'De informatieobject URL is ongeldig.',
				reason: 'Ongeldige URL.'
			),
			actual: $this->rules()->informatieobjectUrl(url: 'geen url')
		);
	}//end testAnInformatieobjectUrlThatIsNoUrlIsRefused()

	/**
	 * An external URL that is no URL is refused as a bad URL; one ending in a UUID passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnExternalUrlThatIsNoUrlIsRefused(): void {
		$rules = $this->rules();

		$this->assertSame(
			expected: $this->refusal(
				field: 'besluit',
				code: 'bad-url',
				detail: 'De besluit URL is ongeldig.',
				reason: 'De besluit URL is ongeldig.'
			),
			actual: $rules->externalUrl(url: 'geen url', field: 'besluit')
		);
		$this->assertNull(
			actual: $rules->externalUrl(url: 'https://example.org/besluiten/0f0e8a5c-3c2b-4c1d-9a7e-1b2c3d4e5f60', field: 'besluit')
		);
	}//end testAnExternalUrlThatIsNoUrlIsRefused()

	/**
	 * The refusal shape: a 400 with one field.
	 *
	 * @param string $field  The field refused.
	 * @param string $code   Its code.
	 * @param string $detail The detail.
	 * @param string $reason The reason.
	 *
	 * @return array<string, mixed> The refusal.
	 */
	private function refusal(string $field, string $code, string $detail, string $reason): array {
		return [
			'valid' => false,
			'status' => 400,
			'detail' => $detail,
			'invalidParams' => [['name' => $field, 'code' => $code, 'reason' => $reason]],
			'enrichedBody' => [],
		];
	}//end refusal()

	/**
	 * A rules class that opens the three URL checks of the base.
	 *
	 * @return object The rules.
	 */
	private function rules(): object {
		return new class(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			fieldValidator: new FieldValidator()
		) extends ZgwRulesBase {
			/**
			 * The base's type URL check.
			 *
			 * @param string $url   The URL.
			 * @param string $field The field.
			 *
			 * @return array|null The refusal, or null.
			 */
			public function typeUrl(string $url, string $field): ?array {
				return $this->validateTypeUrl(typeUrl: $url, fieldName: $field, schemaKey: 'zaaktype_schema');
			}//end typeUrl()

			/**
			 * The base's informatieobject URL check.
			 *
			 * @param string $url The URL.
			 *
			 * @return array|null The refusal, or null.
			 */
			public function informatieobjectUrl(string $url): ?array {
				return $this->validateInformatieobjectUrl(ioUrl: $url);
			}//end informatieobjectUrl()

			/**
			 * The base's external URL check.
			 *
			 * @param string $url   The URL.
			 * @param string $field The field.
			 *
			 * @return array|null The refusal, or null.
			 */
			public function externalUrl(string $url, string $field): ?array {
				return $this->validateExternalUrl(url: $url, fieldName: $field);
			}//end externalUrl()
		};
	}//end rules()
}//end class
