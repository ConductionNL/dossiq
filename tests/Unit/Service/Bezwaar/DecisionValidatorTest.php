<?php

/**
 * DecisionValidator tests
 *
 * Pins every draft-time and publication-time refusal of a bezwaarDecision
 * payload, with its exact message, and the payloads that pass.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\DecisionValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\DecisionValidator
 */
final class DecisionValidatorTest extends TestCase {

	/**
	 * A complete appeal notice for the given filing method.
	 *
	 * @param string $method The filing method.
	 *
	 * @return array<string, string>
	 */
	private static function notice(string $method): array {
		return [
			'competentCourt' => 'Rechtbank Gelderland',
			'appealTerm' => '6 weken',
			'effectiveDate' => '2026-01-01',
			'filingMethod' => $method,
			'filingUrl' => 'https://example.org/beroep',
			'filingAddress' => 'Postbus 1',
		];
	}//end notice()

	/**
	 * A publishable decision with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function decision(array $overrides): array {
		return array_merge(
			['dispositionType' => 'dismissed', 'appealNotice' => self::notice('both')],
			$overrides
		);
	}//end decision()

	/**
	 * Draft payloads and the refusal each raises, null when it passes.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function drafts(): array {
		$ok = ['dispositionType' => 'upheld_revoke', 'reasoning' => 'r', 'legalBasis' => 'l'];

		return [
			'valid' => [$ok, null],
			'valid with replacement' => [array_merge($ok, ['replacementDecision' => 'x']), null],
			'bad disposition' => [['dispositionType' => 'nope'], 'Invalid disposition — must be one of the five canonical Awb 7:11 values'],
			'no reasoning' => [array_merge($ok, ['reasoning' => '']), 'reasoning and legalBasis are required (Awb art. 7:12)'],
			'no legal basis' => [array_merge($ok, ['legalBasis' => '']), 'reasoning and legalBasis are required (Awb art. 7:12)'],
			'replacement not allowed' => [
				array_merge($ok, ['dispositionType' => 'dismissed', 'replacementDecision' => 'x']),
				'replacementDecision MUST NOT be set when disposition is not gegrond_herroepen or gegrond_wijzigen',
			],
		];
	}//end drafts()

	/**
	 * Each draft payload passes or is refused with its message.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string|null          $message The expected refusal.
	 *
	 * @return void
	 *
	 * @dataProvider drafts
	 */
	public function testDraftGuards(array $payload, ?string $message): void {
		if ($message !== null) {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage($message);
		}

		(new DecisionValidator())->assertDraftable(payload: $payload);
		$this->addToAssertionCount(1);
	}//end testDraftGuards()

	/**
	 * Decisions and the publication refusal each raises, null when it passes.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function publications(): array {
		$noticeWithout = static function (string $method, string $field): array {
			$notice = self::notice($method);
			unset($notice[$field]);
			return $notice;
		};

		return [
			'valid' => [self::decision([]), null],
			'valid amend' => [self::decision(['dispositionType' => 'upheld_amend', 'replacementDecision' => 'x']), null],
			'bad disposition' => [self::decision(['dispositionType' => '']), 'dispositionType is invalid — refusing to publish'],
			'amend without replacement' => [
				self::decision(['dispositionType' => 'upheld_amend']),
				'replacementDecision is required when disposition is upheld_amend',
			],
			'replacement not allowed' => [
				self::decision(['dispositionType' => 'upheld_maintain', 'replacementDecision' => 'x']),
				'replacementDecision MUST NOT be set when disposition is upheld_maintain',
			],
			'deviation without rationale' => [
				self::decision(['advisoryOpinion' => 'a', 'followsAdvice' => false]),
				'deviationRationale is required when followsAdvice is false (Awb art. 7:13 lid 7)',
			],
			'deviation with rationale' => [self::decision(['advisoryOpinion' => 'a', 'followsAdvice' => false, 'deviationRationale' => 'r']), null],
			'follows advice' => [self::decision(['advisoryOpinion' => 'a']), null],
			'no advice, no rationale' => [self::decision(['followsAdvice' => false]), null],
			'notice without court' => [
				self::decision(['appealNotice' => $noticeWithout('both', 'competentCourt')]),
				'Rechtsmiddelenclausule onvolledig: competentCourt ontbreekt',
			],
			'notice without method' => [
				self::decision(['appealNotice' => $noticeWithout('both', 'filingMethod')]),
				'Rechtsmiddelenclausule onvolledig: filingMethod ontbreekt',
			],
			'digital without url' => [
				self::decision(['appealNotice' => $noticeWithout('digitaal', 'filingUrl')]),
				'filingUrl is required when filingMethod is digitaal',
			],
			'both without url' => [
				self::decision(['appealNotice' => $noticeWithout('both', 'filingUrl')]),
				'filingUrl is required when filingMethod is both',
			],
			'both without address' => [
				self::decision(['appealNotice' => $noticeWithout('both', 'filingAddress')]),
				'filingAddress is required when filingMethod is both',
			],
			'written without address' => [
				self::decision(['appealNotice' => $noticeWithout('schriftelijk', 'filingAddress')]),
				'filingAddress is required when filingMethod is schriftelijk',
			],
			'written without url' => [self::decision(['appealNotice' => $noticeWithout('schriftelijk', 'filingUrl')]), null],
			'awarded not eligible' => [
				self::decision(['legalCostsCompensation' => ['awarded' => true]]),
				'Proceskostenvergoeding niet mogelijk: primair besluit niet herroepen (Awb art. 7:15 lid 2)',
			],
			'requested eligible undecided' => [
				self::decision(['dispositionType' => 'upheld_revoke', 'legalCostsCompensation' => ['requested' => true]]),
				'proceskosten.awarded MUST be explicitly set (true or false with reasoning) when the bezwaarmaker requested legalCostsCompensation',
			],
			'requested eligible refused' => [
				self::decision(['dispositionType' => 'upheld_revoke', 'legalCostsCompensation' => ['requested' => true, 'awarded' => false]]),
				null,
			],
			'requested not eligible' => [self::decision(['legalCostsCompensation' => ['requested' => true]]), null],
		];
	}//end publications()

	/**
	 * Each decision passes publication or is refused with its message.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param string|null          $message  The expected refusal.
	 *
	 * @return void
	 *
	 * @dataProvider publications
	 */
	public function testPublicationGuards(array $decision, ?string $message): void {
		if ($message !== null) {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage($message);
		}

		(new DecisionValidator())->assertPublishable(decision: $decision);
		$this->addToAssertionCount(1);
	}//end testPublicationGuards()
}//end class
