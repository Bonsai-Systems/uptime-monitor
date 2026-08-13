<?php
/**
 * SSL certificate and domain (WHOIS) expiry lookups.
 *
 * Both are best-effort: a failed lookup returns a null expiry and an error
 * string rather than throwing, since a slow/unreachable WHOIS server or an
 * unusual certificate chain must never take the checker cron down.
 */

/**
 * Reads the expiry date directly off the live TLS certificate — reliable and
 * requires no external service. Peer verification is deliberately disabled;
 * we only want to read whatever certificate is presented, not validate trust.
 */
function um_check_ssl_expiry(string $host, int $timeoutSeconds = 10): array {
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $client = @stream_socket_client(
        "ssl://{$host}:443",
        $errno,
        $errstr,
        $timeoutSeconds,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$client) {
        return ['expires_at' => null, 'error' => $errstr ?: 'Could not open a TLS connection'];
    }

    $params = stream_context_get_params($client);
    fclose($client);

    if (empty($params['options']['ssl']['peer_certificate'])) {
        return ['expires_at' => null, 'error' => 'No certificate returned'];
    }

    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    if (!$cert || empty($cert['validTo_time_t'])) {
        return ['expires_at' => null, 'error' => 'Could not parse certificate'];
    }

    $expiresAt = new DateTime();
    $expiresAt->setTimestamp($cert['validTo_time_t']);

    return ['expires_at' => $expiresAt, 'error' => null];
}

/**
 * Best-effort registrable domain (e.g. "sub.example.co.uk" -> "example.co.uk").
 * Not a full public-suffix list — covers the common UK/AU/NZ two-label suffixes
 * Bonsai clients actually use. Anything else falls back to the last two labels.
 */
function um_get_registrable_domain(string $host): string {
    $host = strtolower(rtrim($host, '.'));
    $labels = explode('.', $host);

    if (count($labels) <= 2) {
        return $host;
    }

    $twoLabelSuffixes = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'co.za', 'com.br',
    ];

    $lastTwo = implode('.', array_slice($labels, -2));
    if (in_array($lastTwo, $twoLabelSuffixes, true) && count($labels) >= 3) {
        return implode('.', array_slice($labels, -3));
    }

    return $lastTwo;
}

/**
 * Opens a raw WHOIS (port 43) connection and returns the response body, or
 * null on any connection failure. Never throws.
 */
function um_whois_query(string $server, string $query, int $timeoutSeconds = 10): ?string {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($server, 43, $errno, $errstr, $timeoutSeconds);
    if (!$fp) {
        return null;
    }

    stream_set_timeout($fp, $timeoutSeconds);
    fwrite($fp, $query . "\r\n");

    $response = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) {
            break;
        }
        $response .= $chunk;

        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out'])) {
            break;
        }
    }
    fclose($fp);

    return $response !== '' ? $response : null;
}

/**
 * Looks up a domain's registry/registrar WHOIS expiry date.
 *
 * Note: Nominet (.uk registry) reports this under a "Renewal date:" field
 * rather than "Expiry Date:"/"Registry Expiry Date:" — um_parse_whois_expiry()
 * checks for both.
 */
function um_check_domain_expiry(string $host, int $timeoutSeconds = 10): array {
    $domain = um_get_registrable_domain($host);
    $labels = explode('.', $domain);
    $tld = end($labels);

    $ianaResponse = um_whois_query('whois.iana.org', $tld, $timeoutSeconds);
    if ($ianaResponse === null) {
        return ['expires_at' => null, 'error' => 'Could not reach whois.iana.org'];
    }

    // gTLDs (.com, .net, .io, ...) return a "refer:" field; ccTLDs (.uk, .co.uk
    // via the "uk" TLD, .de, ...) return "whois:" instead — need to check both.
    if (!preg_match('/^(?:refer|whois):\s*(\S+)/mi', $ianaResponse, $m)) {
        return ['expires_at' => null, 'error' => "No WHOIS referral found for .{$tld}"];
    }
    $registryServer = trim($m[1]);

    $response = um_whois_query($registryServer, $domain, $timeoutSeconds);
    if ($response === null) {
        return ['expires_at' => null, 'error' => "Could not reach {$registryServer}"];
    }

    // Thin registries (.com/.net/...) point back to the registrar's own WHOIS
    // for full detail — follow one extra hop if offered, best-effort.
    if (preg_match('/Registrar WHOIS Server:\s*(\S+)/i', $response, $m)) {
        $registrarServer = trim($m[1]);
        $registrarResponse = um_whois_query($registrarServer, $domain, $timeoutSeconds);
        if ($registrarResponse !== null) {
            $response .= "\n" . $registrarResponse;
        }
    }

    $expiresAt = um_parse_whois_expiry($response);
    if (!$expiresAt) {
        return ['expires_at' => null, 'error' => 'No expiry date found in WHOIS response'];
    }

    return ['expires_at' => $expiresAt, 'error' => null];
}

/**
 * Matches the common WHOIS field names across registries/registrars and
 * parses whichever date format that registry happens to use.
 */
function um_parse_whois_expiry(string $whoisText): ?DateTime {
    $patterns = [
        '/Registry Expiry Date:\s*(.+)/i',
        '/Registrar Registration Expiration Date:\s*(.+)/i',
        '/Registrar Registration Expiry Date:\s*(.+)/i',
        '/Expiration Date:\s*(.+)/i',
        '/Expiry Date:\s*(.+)/i',
        '/Expiry date:\s*(.+)/i',
        '/expires(?:\s+on)?:\s*(.+)/i',
        '/paid-till:\s*(.+)/i',
        '/Renewal date:\s*(.+)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $whoisText, $m)) {
            $timestamp = strtotime(trim($m[1]));
            if ($timestamp !== false) {
                $date = new DateTime();
                $date->setTimestamp($timestamp);
                return $date;
            }
        }
    }

    return null;
}
