<?php
/**
 * Minimal WebAuthn verification for platform authenticators (fingerprint,
 * Windows Hello). Only ES256 / P-256 credentials produced with
 * attestation "none" are supported, which is what browsers return for
 * platform authenticators when attestation is not requested.
 */
class WebAuthn
{
    public static function rpId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return preg_replace('/:\d+$/', '', $host) ?: 'localhost';
    }

    public static function origin(): string
    {
        $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $txt): string
    {
        $txt = strtr(trim($txt), '-_', '+/');
        $pad = strlen($txt) % 4;
        if ($pad) {
            $txt .= str_repeat('=', 4 - $pad);
        }
        $bin = base64_decode($txt, true);
        return $bin === false ? '' : $bin;
    }

    /**
     * Verify a registration response and return the usable credential.
     *
     * @return array{credential_id:string, public_key:string}
     * @throws RuntimeException
     */
    public static function verifyRegistration(string $challenge, string $clientDataB64u, string $attestationB64u): array
    {
        $clientRaw = self::b64uDecode($clientDataB64u);
        $client    = json_decode($clientRaw, true);
        if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.create') {
            throw new RuntimeException('Invalid registration response.');
        }
        if (!hash_equals(self::b64uEncode($challenge), (string)($client['challenge'] ?? ''))) {
            throw new RuntimeException('Registration challenge mismatch.');
        }
        if (($client['origin'] ?? '') !== self::origin()) {
            throw new RuntimeException('Registration origin mismatch.');
        }

        $attBin = self::b64uDecode($attestationB64u);
        $offset = 0;
        $att    = self::cborDecode($attBin, $offset);
        $authData = $att['authData'] ?? null;
        if (!is_string($authData) || strlen($authData) < 55) {
            throw new RuntimeException('Missing authenticator data.');
        }

        self::checkAuthData($authData);

        $flags  = ord($authData[32]);
        $offset = 37; // rpIdHash + flags + signCount
        if (!($flags & 0x40)) {
            throw new RuntimeException('The authenticator did not return a credential.');
        }

        $credLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        $credId  = substr($authData, $offset, $credLen);
        $offset += $credLen;
        if ($credId === '') {
            throw new RuntimeException('Empty credential id.');
        }

        $cose = self::cborDecode($authData, $offset);
        if (!is_array($cose)) {
            throw new RuntimeException('Missing credential public key.');
        }

        return [
            'credential_id' => self::b64uEncode($credId),
            'public_key'    => self::coseToPem($cose),
        ];
    }

    /**
     * Verify an authentication (assertion) response.
     *
     * @param array{credential_id:string, public_key:string} $stored
     */
    public static function verifyAssertion(
        array $stored,
        string $challenge,
        string $credentialIdB64u,
        string $clientDataB64u,
        string $authDataB64u,
        string $signatureB64u
    ): bool {
        if (!hash_equals((string)$stored['credential_id'], $credentialIdB64u)) {
            return false;
        }

        $clientRaw = self::b64uDecode($clientDataB64u);
        $client    = json_decode($clientRaw, true);
        if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.get') {
            return false;
        }
        if (!hash_equals(self::b64uEncode($challenge), (string)($client['challenge'] ?? ''))) {
            return false;
        }
        if (($client['origin'] ?? '') !== self::origin()) {
            return false;
        }

        $authData = self::b64uDecode($authDataB64u);
        if (strlen($authData) < 37) {
            return false;
        }
        try {
            self::checkAuthData($authData);
        } catch (RuntimeException $e) {
            return false;
        }

        $signed = $authData . hash('sha256', $clientRaw, true);
        $sig    = self::b64uDecode($signatureB64u);
        $key    = openssl_pkey_get_public((string)$stored['public_key']);
        if (!$key || $sig === '') {
            return false;
        }

        return openssl_verify($signed, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @throws RuntimeException */
    private static function checkAuthData(string $authData): void
    {
        if (!hash_equals(hash('sha256', self::rpId(), true), substr($authData, 0, 32))) {
            throw new RuntimeException('Authenticator is bound to a different site.');
        }
        $flags = ord($authData[32]);
        if (!($flags & 0x01)) {
            throw new RuntimeException('User presence was not confirmed.');
        }
        if (!($flags & 0x04)) {
            throw new RuntimeException('User verification (fingerprint or PIN) is required.');
        }
    }

    private static function coseToPem(array $cose): string
    {
        if (($cose[1] ?? null) !== 2 || ($cose[3] ?? null) !== -7 || ($cose[-1] ?? null) !== 1) {
            throw new RuntimeException('Only ES256 (P-256) credentials are supported.');
        }
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Invalid public key coordinates.');
        }

        // DER SPKI header for id-ecPublicKey / prime256v1
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Decode one CBOR item. Supports the subset used by WebAuthn structures
     * (unsigned/negative integers, byte and text strings, arrays, maps).
     *
     * @return mixed
     */
    private static function cborDecode(string $bin, int &$offset)
    {
        if ($offset >= strlen($bin)) {
            throw new RuntimeException('Truncated CBOR data.');
        }

        $initial = ord($bin[$offset]);
        $offset++;
        $major = $initial >> 5;
        $info  = $initial & 0x1f;

        $len = $info;
        if ($info === 24) {
            $len = ord($bin[$offset]);
            $offset += 1;
        } elseif ($info === 25) {
            $len = unpack('n', substr($bin, $offset, 2))[1];
            $offset += 2;
        } elseif ($info === 26) {
            $len = unpack('N', substr($bin, $offset, 4))[1];
            $offset += 4;
        } elseif ($info === 27) {
            $len = unpack('J', substr($bin, $offset, 8))[1];
            $offset += 8;
        } elseif ($info >= 28) {
            if ($info === 31) {
                throw new RuntimeException('Indefinite-length CBOR is not supported.');
            }
            throw new RuntimeException('Unsupported CBOR length.');
        }

        switch ($major) {
            case 0: // unsigned int
                return $len;
            case 1: // negative int
                return -1 - $len;
            case 2: // byte string
                $v = substr($bin, $offset, $len);
                $offset += $len;
                return $v;
            case 3: // text string
                $v = substr($bin, $offset, $len);
                $offset += $len;
                return $v;
            case 4: // array
                $out = [];
                for ($i = 0; $i < $len; $i++) {
                    $out[] = self::cborDecode($bin, $offset);
                }
                return $out;
            case 5: // map
                $out = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = self::cborDecode($bin, $offset);
                    $out[$k] = self::cborDecode($bin, $offset);
                }
                return $out;
            case 7:
                if ($info === 20) return false;
                if ($info === 21) return true;
                if ($info === 22) return null;
                throw new RuntimeException('Unsupported CBOR simple value.');
            default:
                throw new RuntimeException('Unsupported CBOR type.');
        }
    }
}
