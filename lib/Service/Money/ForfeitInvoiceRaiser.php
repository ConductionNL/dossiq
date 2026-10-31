<?php

/**
 * Dossiq ForfeitInvoiceRaiser
 *
 * When an enforcement act is forfeited and its case type says a forfeit raises
 * an invoice, this asks the finance app for a payment request with an invoice
 * behind it: the forfeited amount, billed to the person on the case in the
 * configured payer role, linked to the case. Which role pays, which finance
 * administration bills and which kind of payment it is are case type
 * configuration; nothing here names a procedure.
 *
 * Nothing is raised without the setting, without a payer with an email
 * address, or without the finance app. A case that cannot be read or a request
 * the finance app refuses throws ForfeitInvoiceNotRaisedException; the caller
 * decides what that means for the act.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises the invoice for a forfeited enforcement act.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */
class ForfeitInvoiceRaiser {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and schema settings, and the object service.
	 * @param PersonLinkReader $people The people on the case, to find who pays.
	 * @param CasePaymentReader $payments Hands out the finance app's payment leaf.
	 * @param LoggerInterface $logger Records why nothing was raised.
	 * @param IL10N $l10n Words the invoice line in the instance's language.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly PersonLinkReader $people,
		private readonly CasePaymentReader $payments,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Raise the invoice for a forfeited act, when its case type says so.
	 *
	 * @param array<string, mixed> $action The forfeited enforcement act.
	 *
	 * @return string|null The payment request id, or null when the case type does not ask
	 *                     for an invoice, nobody can be billed or there is no finance app.
	 *
	 * @throws ForfeitInvoiceNotRaisedException When the case cannot be read or the finance app refuses.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
	 */
	public function raise(array $action): ?string {
		$caseId = (string)($action['case'] ?? '');
		$case = $this->read(schemaKey: 'case_schema', id: $caseId, caseId: $caseId);
		$caseType = $this->read(schemaKey: 'case_type_schema', id: (string)($case['caseType'] ?? ''), caseId: $caseId);
		if (($caseType['forfeitRaisesInvoice'] ?? false) !== true) {
			return null;
		}

		$payer = $this->payerOn(caseId: $caseId, roleType: (string)($caseType['forfeitPayerRoleType'] ?? ''));
		$leaf = $this->payments->paymentLeaf();
		if ($payer === null || $leaf === null || method_exists($leaf, 'createAsApp') === false) {
			$this->logger->info('Dossiq: a forfeited penalty raised no invoice: no payer with an email address, or no finance app', ['case' => $caseId]);
			return null;
		}

		try {
			$created = $leaf->createAsApp(
				Application::APP_ID,
				CasePaymentReader::CASE_REGISTER,
				CasePaymentReader::CASE_SCHEMA,
				$caseId,
				[
					'requestType' => (string)($caseType['forfeitRequestType'] ?? 'dwangsom'),
					'amount' => (float)($action['penaltyPaymentAmount'] ?? 0),
					'currency' => 'EUR',
					'description' => trim($this->l10n->t('Forfeited penalty %s', [(string)($case['identifier'] ?? '')])),
					'debtor' => $payer,
					'invoiceRequested' => true,
					'administrationId' => (string)($caseType['forfeitAdministration'] ?? ''),
					'subjectType' => 'case',
				]
			);
		} catch (Throwable $e) {
			throw new ForfeitInvoiceNotRaisedException(reasonCode: ForfeitInvoiceNotRaisedException::FINANCE_REFUSED, caseId: $caseId, previous: $e);
		}

		return (string)($created['id'] ?? '');
	}//end raise()

	/**
	 * The person on the case in the payer role, as a debtor, or null when nobody can be billed.
	 *
	 * @param string $caseId The case.
	 * @param string $roleType The role type that pays.
	 *
	 * @return array{name: string, email: string}|null The debtor.
	 */
	private function payerOn(string $caseId, string $roleType): ?array {
		if ($roleType === '') {
			return null;
		}

		foreach ($this->people->peopleOn(caseId: $caseId) as $link) {
			$email = $this->people->emailOf(link: $link);
			if ((string)($link['role'] ?? '') === $roleType && $email !== '') {
				return ['name' => $this->people->nameOf(link: $link), 'email' => $email];
			}
		}

		return null;
	}//end payerOn()

	/**
	 * One object by the schema a setting names, or [] when it is not configured or not held.
	 *
	 * @param string $schemaKey The setting that names the schema.
	 * @param string $id The object id.
	 * @param string $caseId The case the read is for.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws ForfeitInvoiceNotRaisedException When the store cannot be read.
	 */
	private function read(string $schemaKey, string $id, string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $id === '' || $schema === '') {
			return [];
		}

		try {
			return ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id) ?? []);
		} catch (Throwable $e) {
			throw new ForfeitInvoiceNotRaisedException(reasonCode: ForfeitInvoiceNotRaisedException::STORE_UNREADABLE, caseId: $caseId, previous: $e);
		}
	}//end read()
}//end class
