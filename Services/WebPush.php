<?php

namespace Modules\Refresh\Services;

/**
 * In-house Web Push (installable PWA): replaces the paid "Mobile Notifications" module, which only serves
 * the FreeScout app. Pure PHP 7.4 (OpenSSL), no composer dependency:
 *  - VAPID (RFC 8292): ES256 JWT signed with the server's key, generated on first use;
 *  - aes128gcm content encryption (RFC 8291 / 8188): only the subscribed phone can read the notification.
 * Storage (outside the web root, storage/app/refresh, chmod 600): vapid.json (keys) and push_subscriptions.json (subscriptions).
 */
class WebPush
{
    const TTL = 86400;                               // an undelivered notification expires after 24h

    protected static function dir()
    {
        $dir = storage_path('app/refresh');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    protected static function b64u($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    protected static function unb64u($str)
    {
        return base64_decode(strtr($str, '-_', '+/').str_repeat('=', (4 - strlen($str) % 4) % 4));
    }

    protected static function writeJson($file, $data)
    {
        $path = self::dir().'/'.$file;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($path, 0600);
    }

    protected static function readJson($file)
    {
        $path = self::dir().'/'.$file;
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string)file_get_contents($path), true);
    }

    /** Raw public key (65 bytes, uncompressed point) of an OpenSSL EC key. */
    protected static function rawPublic($key)
    {
        $d = openssl_pkey_get_details($key);
        return "\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    protected static function newEcKey()
    {
        return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    /** Server VAPID keys ['private' => PEM, 'public' => base64url], created on first call. */
    public static function vapid()
    {
        $k = self::readJson('vapid.json');
        if (!$k || empty($k['private']) || empty($k['public'])) {
            $key = self::newEcKey();
            openssl_pkey_export($key, $pem);
            $k = ['private' => $pem, 'public' => self::b64u(self::rawPublic($key))];
            self::writeJson('vapid.json', $k);
        }
        return $k;
    }

    public static function publicKey()
    {
        return self::vapid()['public'];
    }

    /* ---------------------------------------------------------------- subscriptions */

    public static function subscriptions()
    {
        return self::readJson('push_subscriptions.json') ?: [];
    }

    public static function subscribe($user_id, $sub, $ua = '')
    {
        if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])
            || !preg_match('#^https://#', $sub['endpoint'])
        ) {
            return false;
        }
        $all = array_values(array_filter(self::subscriptions(), function ($s) use ($sub) {
            return $s['endpoint'] !== $sub['endpoint'];
        }));
        $all[] = [
            'user_id'  => (int)$user_id,
            'endpoint' => $sub['endpoint'],
            'p256dh'   => $sub['keys']['p256dh'],
            'auth'     => $sub['keys']['auth'],
            'ua'       => mb_substr((string)$ua, 0, 200),
            'created'  => date('Y-m-d H:i:s'),
        ];
        self::writeJson('push_subscriptions.json', $all);
        return true;
    }

    public static function unsubscribe($endpoint)
    {
        $all = self::subscriptions();
        $kept = array_values(array_filter($all, function ($s) use ($endpoint) {
            return $s['endpoint'] !== $endpoint;
        }));
        if (count($kept) != count($all)) {
            self::writeJson('push_subscriptions.json', $kept);
        }
    }

    /* ---------------------------------------------------------------- sending */

    /** Sends ['title','body','url','tag'] to all of the agent's devices. Returns the number of accepted sends. */
    public static function sendToUser($user_id, array $payload)
    {
        $ok = 0;
        foreach (self::subscriptions() as $sub) {
            if ((int)$sub['user_id'] !== (int)$user_id) {
                continue;
            }
            $code = self::send($sub, $payload);
            if ($code >= 200 && $code < 300) {
                $ok++;
            } elseif ($code == 404 || $code == 410) {
                // subscription expired or revoked (app uninstalled, notifications turned off): drop it
                self::unsubscribe($sub['endpoint']);
            } else {
                \Log::warning('[Refresh][WebPush] HTTP '.$code.' for user '.$user_id);
            }
        }
        return $ok;
    }

    /** Sends encrypted content to a subscription; returns the push service's HTTP code (0 on network/encryption failure). */
    public static function send(array $sub, array $payload)
    {
        $body = self::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
        if ($body === null) {
            return 0;
        }
        $jwt = self::vapidJwt($sub['endpoint']);
        if ($jwt === null) {
            return 0;
        }
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t='.$jwt.', k='.self::publicKey(),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: '.self::TTL,
                'Urgency: high',
            ],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /** VAPID ES256 JWT for the push service's origin. */
    protected static function vapidJwt($endpoint)
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $input = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => \Modules\Refresh\Services\Settings::pushContact()], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private(self::vapid()['private']);
        if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return $input.'.'.self::b64u(self::derToRaw($der));
    }

    /** ECDSA DER signature (SEQUENCE { INTEGER r, INTEGER s }) -> r || s, 32 bytes each (JWS format). */
    protected static function derToRaw($der)
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    protected static function hkdf($salt, $ikm, $info, $len)
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info."\x01", $prk, true), 0, $len);
    }

    /** aes128gcm encryption (RFC 8291) of the content for the subscription's p256dh key / auth secret. */
    protected static function encrypt($plaintext, $p256dh_b64u, $auth_b64u)
    {
        $ua_public = self::unb64u($p256dh_b64u);
        $auth = self::unb64u($auth_b64u);
        if (strlen($ua_public) !== 65 || strlen($auth) < 16) {
            return null;
        }
        // phone's public key -> OpenSSL object (SubjectPublicKeyInfo P-256)
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$ua_public;
        $ua_key = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n");
        $as_key = self::newEcKey(); // ephemeral key, one per message
        if (!$ua_key || !$as_key) {
            return null;
        }
        $as_public = self::rawPublic($as_key);
        $ecdh = openssl_pkey_derive($ua_key, $as_key, 32);
        if ($ecdh === false) {
            return null;
        }
        $ikm = self::hkdf($auth, $ecdh, "WebPush: info\0".$ua_public.$as_public, 32);
        $salt = random_bytes(16);
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return null;
        }
        // header: salt (16) | record size (4, 4096) | key id length (1) | ephemeral public key (65)
        return $salt.pack('N', 4096).chr(65).$as_public.$cipher.$tag;
    }
}
