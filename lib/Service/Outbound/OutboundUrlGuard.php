<?php

/**
 * Decides which address an outbound request may connect to
 *
 * The one guard against server-side request forgery. A URL that comes from
 * outside (a request body, a subscription, a setting) is resolved here before
 * anything connects to it, and the answer is the address to connect to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Outbound
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Outbound;

use Psr\Log\LoggerInterface;

/**
 * Resolves an outbound URL to the one address it may be connected to.
 *
 * Two questions, and they are inverses. {@see self::publicAddress()} is for a
 * service somewhere else: https only, and no address inside the instance's own
 * network. {@see self::ownNetworkAddress()} is for a service that must stay
 * inside: http or https, and only an address inside it.
 *
 * Both fail closed. A URL without a host, a name nothing answers for, and a
 * name with one address that does not pass are all refused. Every address a
 * name answers with has to pass, not only the first: a name with one good
 * address and one bad one is a name whose next answer cannot be predicted.
 *
 * Both answer with an address, not a yes. A caller that is told yes and then
 * connects by name resolves the name a second time, and the second answer
 * belongs to whoever owns the DNS record (DNS rebinding). Connect with
 * {@see self::requestOptions()} and the request goes to the address that was
 * inspected, follows no redirect and verifies TLS.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class OutboundUrlGuard {
	/**
	 * Where a service that is somewhere else cannot be.
	 *
	 * Private (RFC 1918), loopback, link-local (where every cloud parks its
	 * metadata service, 169.254.169.254), their IPv6 counterparts, and the
	 * unspecified address, which a connect treats as this machine.
	 *
	 * @var string[]
	 */
	private const NOT_PUBLIC = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'::/128',
		'::1/128',
		'fc00::/7',
		'fe80::/10',
	];

	/**
	 * The whole of what "inside the instance's own network" means.
	 *
	 * An allow-list. Loopback, and the private space a containerised instance
	 * shares with its sidecars. Link-local is absent on purpose: the metadata
	 * service lives there, so it is refused here too.
	 *
	 * @var string[]
	 */
	private const OWN_NETWORK = [
		'10.0.0.0/8',
		'127.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'::1/128',
		'fc00::/7',
	];

	/**
	 * The name lookup.
	 *
	 * @var HostResolver
	 */
	private HostResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger; every refusal is logged at warning.
	 * @param HostResolver|null $resolver The name lookup; the real one when left out.
	 */
	public function __construct(private readonly LoggerInterface $logger, ?HostResolver $resolver = null) {
		$this->resolver = ($resolver ?? new HostResolver());
	}//end __construct()

	/**
	 * The address of a service outside the instance's own network.
	 *
	 * @param string $url The URL to connect to.
	 *
	 * @return string|null The address to connect to, or null when the URL is refused.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function publicAddress(string $url): ?string {
		return $this->addressFor(url: $url, schemes: ['https'], insideOwnNetwork: false);
	}//end publicAddress()

	/**
	 * The address of a service inside the instance's own network.
	 *
	 * Plain http is accepted here only because the allow-list has already
	 * confined the connection to the instance's own network.
	 *
	 * @param string $url The URL to connect to.
	 *
	 * @return string|null The address to connect to, or null when the URL is refused.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function ownNetworkAddress(string $url): ?string {
		return $this->addressFor(url: $url, schemes: ['http', 'https'], insideOwnNetwork: true);
	}//end ownNetworkAddress()

	/**
	 * The Guzzle request options that hold a request to an approved address.
	 *
	 * No redirect is followed: a redirect is a second URL, and nobody inspected
	 * it. TLS is verified. The host is pinned to the approved address, so the
	 * check and the connection cannot disagree; the Host header and TLS SNI
	 * still carry the name. An IP literal needs no pin, it resolves to itself.
	 *
	 * @param string $url The URL the address was approved for.
	 * @param string $address The address one of the two questions answered with.
	 *
	 * @return array<string, mixed> Options to merge into the request.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function requestOptions(string $url, string $address): array {
		$options = ['allow_redirects' => false, 'verify' => true];

		$parsed = $this->parsed(url: $url);
		if ($parsed['host'] === '' || filter_var($parsed['host'], FILTER_VALIDATE_IP) !== false) {
			return $options;
		}

		// CURLOPT_RESOLVE matches on host:port, and an entry that names the
		// wrong port is ignored without a word. So the port is always stated.
		$port = $parsed['port'];
		if ($port === 0) {
			$port = 80;
			if ($parsed['scheme'] === 'https') {
				$port = 443;
			}
		}

		$options['curl'] = [CURLOPT_RESOLVE => [$parsed['host'] . ':' . $port . ':' . $address]];

		return $options;
	}//end requestOptions()

	/**
	 * Resolve a URL and judge every address it answers with.
	 *
	 * @param string $url The URL.
	 * @param string[] $schemes The schemes this question accepts.
	 * @param bool $insideOwnNetwork True when the address must be inside the own network, false when it must be outside.
	 *
	 * @return string|null The first address, or null when the URL is refused.
	 */
	private function addressFor(string $url, array $schemes, bool $insideOwnNetwork): ?string {
		$parsed = $this->parsed(url: $url);
		$host = $parsed['host'];
		if ($host === '' || in_array($parsed['scheme'], $schemes, true) === false) {
			$this->refused(reason: 'scheme or host', context: ['scheme' => $parsed['scheme'], 'host' => $host]);
			return null;
		}

		$addresses = [$host];
		if (filter_var($host, FILTER_VALIDATE_IP) === false) {
			$addresses = $this->resolver->addressesOf(host: $host);
		}

		if (count($addresses) === 0) {
			$this->refused(reason: 'host does not resolve', context: ['host' => $host, 'detail' => $this->resolver->lastWarning()]);
			return null;
		}

		foreach ($addresses as $address) {
			if ($this->passes(address: $address, insideOwnNetwork: $insideOwnNetwork) === false) {
				$this->refused(
					reason: 'address not allowed',
					context: ['host' => $host, 'ip' => $address, 'insideOwnNetwork' => $insideOwnNetwork]
				);
				return null;
			}
		}

		return $addresses[0];
	}//end addressFor()

	/**
	 * Whether one address passes.
	 *
	 * An IPv4 address written as IPv6 (::ffff:127.0.0.1) connects to the IPv4
	 * address, so a service outside is judged on that one. A service inside is
	 * judged on the address as written: the allow-list does not list the
	 * mapped form, so it stays refused.
	 *
	 * @param string $address The address.
	 * @param bool $insideOwnNetwork True when it must be inside the own network.
	 *
	 * @return bool True when the address passes.
	 */
	private function passes(string $address, bool $insideOwnNetwork): bool {
		// A resolver answer that is no address cannot be shown to be safe.
		if (filter_var($address, FILTER_VALIDATE_IP) === false) {
			return false;
		}

		if ($insideOwnNetwork === true) {
			return $this->inAny(address: $address, ranges: self::OWN_NETWORK);
		}

		return $this->inAny(address: $this->withoutMapping(address: $address), ranges: self::NOT_PUBLIC) === false;
	}//end passes()

	/**
	 * Scheme, host and port of a URL, each empty when the URL has none.
	 *
	 * The scheme and host are lower-cased. An IPv6 literal loses its brackets:
	 * parse_url() keeps them and nothing after it wants them.
	 *
	 * @param string $url The URL.
	 *
	 * @return array{scheme: string, host: string, port: int}
	 */
	private function parsed(string $url): array {
		$parts = parse_url($url);
		if (is_array($parts) === false) {
			$parts = [];
		}

		return [
			'scheme' => strtolower((string)($parts['scheme'] ?? '')),
			'host' => strtolower(trim((string)($parts['host'] ?? ''), '[]')),
			'port' => (int)($parts['port'] ?? 0),
		];
	}//end parsed()

	/**
	 * An IPv4-mapped IPv6 address as the IPv4 address it connects to.
	 *
	 * @param string $address The address.
	 *
	 * @return string The IPv4 address when the input was mapped, else the input.
	 */
	private function withoutMapping(string $address): string {
		$packed = inet_pton($address);
		if ($packed === false || strlen($packed) !== 16 || str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff") === false) {
			return $address;
		}

		return (string)inet_ntop(substr($packed, 12));
	}//end withoutMapping()

	/**
	 * Whether an address is inside one of a set of ranges.
	 *
	 * @param string $address The address.
	 * @param string[] $ranges The ranges, in CIDR notation.
	 *
	 * @return bool True when it is inside at least one.
	 */
	private function inAny(string $address, array $ranges): bool {
		$packed = inet_pton($address);
		if ($packed === false) {
			return false;
		}

		foreach ($ranges as $range) {
			if ($this->inRange(packed: $packed, range: $range) === true) {
				return true;
			}
		}

		return false;
	}//end inAny()

	/**
	 * Whether a packed address is inside one range.
	 *
	 * Compares the leading bits of the two packed addresses. An address of the
	 * other family is never inside.
	 *
	 * @param string $packed The address as inet_pton() returns it.
	 * @param string $range The range, in CIDR notation.
	 *
	 * @return bool True when the address is inside the range.
	 */
	private function inRange(string $packed, string $range): bool {
		[$network, $prefix] = explode('/', $range);
		$networkPacked = (string)inet_pton($network);
		if (strlen($networkPacked) !== strlen($packed)) {
			return false;
		}

		$bits = (int)$prefix;
		$whole = intdiv($bits, 8);
		if (strncmp($packed, $networkPacked, $whole) !== 0) {
			return false;
		}

		$rest = ($bits % 8);
		if ($rest === 0) {
			return true;
		}

		$mask = ((0xFF << (8 - $rest)) & 0xFF);

		return (ord($packed[$whole]) & $mask) === (ord($networkPacked[$whole]) & $mask);
	}//end inRange()

	/**
	 * Log a refusal.
	 *
	 * @param string $reason Why the URL is refused.
	 * @param array<string, mixed> $context What was looked at.
	 *
	 * @return void
	 */
	private function refused(string $reason, array $context): void {
		$this->logger->warning('Outbound URL refused: ' . $reason, $context);
	}//end refused()
}//end class
