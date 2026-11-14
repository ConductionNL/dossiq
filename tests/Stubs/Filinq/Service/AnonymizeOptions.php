<?php

/**
 * Stand-in for filinq's AnonymizeOptions, the options of one anonymise call,
 * with the same immutable `with...` methods, for an instance without filinq.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Filinq\Service;

/**
 * The options of one anonymise call; the readers exist only on the stand-in, for the fake.
 */
final class AnonymizeOptions {

	private string $outputFormat = 'pdf';

	private string $userId = '';

	private bool $wooCase = false;

	public function withOutputFormat(string $outputFormat): self {
		$copy = clone $this;
		$copy->outputFormat = $outputFormat;
		return $copy;
	}//end withOutputFormat()

	public function withUserId(string $userId): self {
		$copy = clone $this;
		$copy->userId = $userId;
		return $copy;
	}//end withUserId()

	public function withWooCase(bool $wooCase): self {
		$copy = clone $this;
		$copy->wooCase = $wooCase;
		return $copy;
	}//end withWooCase()

	public function outputFormat(): string {
		return $this->outputFormat;
	}//end outputFormat()

	public function userId(): string {
		return $this->userId;
	}//end userId()

	public function wooCase(): bool {
		return $this->wooCase;
	}//end wooCase()
}//end class
