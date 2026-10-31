<?php

/**
 * Unit tests for ForfeitInvoiceRaiser.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\ForfeitInvoiceRaiser;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A forfeited penalty raises an invoice when its case type says so.
 */
final class ForfeitInvoiceRaiserTest extends TestCase {
	private FakeTermijnStore $objects;

	/**
	 * The leaf double, which logs every createAsApp call in `calls`.
	 *
	 * @var object|null
	 */
	private ?object $leaf = null;

	/**
	 * The raiser over a case of a configured case type.
	 *
	 * @param array<string, mixed> $caseType The case type's forfeit settings.
	 * @param array<int, array<string, string>> $people The people on the case.
	 * @param bool $leafPresent Whether shillinq's leaf can be had.
	 * @param string|null $refusal What the leaf refuses with, or null.
	 * @param bool $storeFails Whether every read of the store throws.
	 *
	 * @return ForfeitInvoiceRaiser
	 */
	private function raiser(array $caseType, array $people, bool $leafPresent = true, ?string $refusal = null, bool $storeFails = false): ForfeitInvoiceRaiser {
		$this->objects = new FakeTermijnStore();
		$this->objects->seed('caseType', ['id' => 'ct-1'] + $caseType);
		$this->objects->seed('case', ['id' => 'case-7', 'caseType' => 'ct-1', 'identifier' => 'HH-2026-0007']);
		$settings = $this->createMock(SettingsService::class);
		$store = $this->objects;
		if ($storeFails === true) {
			$store = new class {
				/**
				 * Every read fails.
				 *
				 * @param mixed ...$arguments Ignored.
				 *
				 * @return never
				 */
				public function find(mixed ...$arguments): never {
					throw new RuntimeException('database is gone');
				}
			};
		}
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				default => '',
			}
		);
		$peopleReader = $this->createMock(PersonLinkReader::class);
		$peopleReader->method('peopleOn')->willReturn($people);
		$peopleReader->method('emailOf')->willReturnCallback(static fn (array $link): string => (string)($link['email'] ?? ''));
		$peopleReader->method('nameOf')->willReturnCallback(static fn (array $link): string => (string)($link['displayName'] ?? ''));

		$leaf = new class($refusal) {
			/**
			 * Every createAsApp call.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param string|null $refusal What to refuse with.
			 */
			public function __construct(private ?string $refusal) {
			}

			/**
			 * @param mixed ...$arguments The call.
			 * @return array<string, mixed>
			 */
			public function createAsApp(...$arguments): array {
				if ($this->refusal !== null) {
					throw new RuntimeException($this->refusal);
				}

				$this->calls[] = $arguments;
				return ['id' => 'pr-1', 'invoiceReference' => 'inv-1'];
			}
		};
		$this->leaf = $leaf;
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$payments = $this->createMock(CasePaymentReader::class);
		$payments->method('paymentLeaf')->willReturn($leafPresent === true ? $leaf : null);

		return new ForfeitInvoiceRaiser(
			settingsService: $settings,
			people: $peopleReader,
			payments: $payments,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
		);
	}//end raiser()

	/**
	 * The forfeited act.
	 *
	 * @return array<string, mixed>
	 */
	private static function act(): array {
		return ['id' => 'act-1', 'case' => 'case-7', 'status' => 'verbeurd', 'penaltyPaymentAmount' => 2500];
	}//end act()

	public function testAForfeitRaisesAnInvoiceToThePayerOnTheCase(): void {
		$raiser = $this->raiser(
			caseType: ['forfeitRaisesInvoice' => true, 'forfeitPayerRoleType' => 'rt-offender', 'forfeitAdministration' => 'adm-1', 'forfeitRequestType' => 'dwangsom'],
			people: [
				['role' => 'rt-complainant', 'displayName' => 'Buurman', 'email' => 'buurman@example.nl'],
				['role' => 'rt-offender', 'displayName' => 'Bouwbedrijf X', 'email' => 'info@bouwbedrijfx.nl'],
			],
		);

		self::assertSame('pr-1', $raiser->raise(action: self::act()));
		self::assertCount(1, $this->leaf->calls);
		[$app, $register, $schema, $caseId, $payload] = $this->leaf->calls[0];
		self::assertSame(['dossiq', 'dossiq', 'case', 'case-7'], [$app, $register, $schema, $caseId]);
		self::assertSame(
			[
				'requestType' => 'dwangsom',
				'amount' => 2500.0,
				'currency' => 'EUR',
				'description' => 'Forfeited penalty HH-2026-0007',
				'debtor' => ['name' => 'Bouwbedrijf X', 'email' => 'info@bouwbedrijfx.nl'],
				'invoiceRequested' => true,
				'administrationId' => 'adm-1',
				'subjectType' => 'case',
			],
			$payload
		);
	}//end testAForfeitRaisesAnInvoiceToThePayerOnTheCase()

	public function testNothingIsRaisedWhenTheCaseTypeDoesNotSaySoOrNobodyCanPay(): void {
		$payer = [['role' => 'rt-offender', 'displayName' => 'X', 'email' => 'x@example.nl']];
		$cases = [
			'setting off' => [['forfeitRaisesInvoice' => false, 'forfeitPayerRoleType' => 'rt-offender', 'forfeitAdministration' => 'adm-1'], $payer, true],
			'no payer role' => [['forfeitRaisesInvoice' => true, 'forfeitAdministration' => 'adm-1'], $payer, true],
			'payer has no email' => [['forfeitRaisesInvoice' => true, 'forfeitPayerRoleType' => 'rt-offender', 'forfeitAdministration' => 'adm-1'], [['role' => 'rt-offender', 'displayName' => 'X', 'email' => '']], true],
			'no shillinq' => [['forfeitRaisesInvoice' => true, 'forfeitPayerRoleType' => 'rt-offender', 'forfeitAdministration' => 'adm-1'], $payer, false],
		];
		foreach ($cases as $label => [$caseType, $people, $leaf]) {
			self::assertNull($this->raiser(caseType: $caseType, people: $people, leafPresent: $leaf)->raise(action: self::act()), $label);
			self::assertSame([], $this->leaf->calls, $label);
		}
	}//end testNothingIsRaisedWhenTheCaseTypeDoesNotSaySoOrNobodyCanPay()

	public function testARefusalThrowsForTheCallerToDecide(): void {
		$raiser = $this->raiser(
			caseType: ['forfeitRaisesInvoice' => true, 'forfeitPayerRoleType' => 'rt-offender', 'forfeitAdministration' => 'adm-1'],
			people: [['role' => 'rt-offender', 'displayName' => 'X', 'email' => 'x@example.nl']],
			refusal: '403 This app is not granted the payment.request action (paymentActionApps).',
		);

		try {
			$raiser->raise(action: self::act());
			self::fail('a refused invoice must throw');
		} catch (ForfeitInvoiceNotRaisedException $e) {
			self::assertSame(ForfeitInvoiceNotRaisedException::FINANCE_REFUSED, $e->getReasonCode());
			self::assertSame('case-7', $e->getCaseId());
			self::assertStringContainsString('paymentActionApps', (string)$e->getPrevious()?->getMessage());
		}
	}//end testARefusalThrowsForTheCallerToDecide()

	public function testAStoreThatCannotBeReadThrowsInsteadOfReadingNoSetting(): void {
		$raiser = $this->raiser(
			caseType: ['forfeitRaisesInvoice' => true, 'forfeitPayerRoleType' => 'rt-offender', 'forfeitAdministration' => 'adm-1'],
			people: [['role' => 'rt-offender', 'displayName' => 'X', 'email' => 'x@example.nl']],
			storeFails: true,
		);

		try {
			$raiser->raise(action: self::act());
			self::fail('an unreadable store must throw');
		} catch (ForfeitInvoiceNotRaisedException $e) {
			self::assertSame(ForfeitInvoiceNotRaisedException::STORE_UNREADABLE, $e->getReasonCode());
			self::assertSame('case-7', $e->getCaseId());
		}
		self::assertSame([], $this->leaf->calls);
	}//end testAStoreThatCannotBeReadThrowsInsteadOfReadingNoSetting()
}//end class
