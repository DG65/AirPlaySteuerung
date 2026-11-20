<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Bausteine der AirPlay-2-Kopplung, rein in PHP ohne Symcon:
// Binär-Plist, TLV8, SRP-6a (3072 Bit, SHA-512), ChaCha20-Poly1305, HKDF.
// Ablauf nach pyatv (MIT, github.com/postlund/pyatv). Die SRP-Rechnung folgt
// bitgenau srptools, das pyatv nutzt (Prüfstand .tools/test-airplay.php).
// Benötigt die PHP-Erweiterungen gmp und sodium.
// ===========================================================================

/** Rohe Bytes in einer Plist (Typ „data"), Abgrenzung zu Zeichenketten. */
final class AirPlayBplistData
{
    public function __construct(public string $bytes)
    {
    }
}

final class AirPlayBplist
{
    /** @var array<int, array> */
    private array $objekte = [];

    public static function encode(mixed $wert): string
    {
        $w = new self();
        $w->hinzu($wert);
        $anzahl = count($w->objekte);
        $refGroesse = $anzahl < 256 ? 1 : 2;
        $out = 'bplist00';
        $offsets = [];
        foreach ($w->objekte as $obj) {
            $offsets[] = strlen($out);
            $out .= self::serialisieren($obj, $refGroesse);
        }
        $tabelle = strlen($out);
        $offGroesse = $tabelle < 256 ? 1 : ($tabelle < 65536 ? 2 : 4);
        foreach ($offsets as $o) {
            $out .= self::uint($o, $offGroesse);
        }
        return $out . str_repeat("\0", 6) . chr($offGroesse) . chr($refGroesse)
            . pack('J', $anzahl) . pack('J', 0) . pack('J', $tabelle);
    }

    private function hinzu(mixed $v): int
    {
        $idx = count($this->objekte);
        $this->objekte[] = [];
        if (is_array($v) && $v !== [] && array_is_list($v)) {
            $refs = [];
            foreach ($v as $e) {
                $refs[] = $this->hinzu($e);
            }
            $this->objekte[$idx] = ['array', $refs];
        } elseif (is_array($v) && $v !== []) {
            $schluessel = [];
            $werte = [];
            foreach (array_keys($v) as $k) {
                $schluessel[] = $this->hinzu((string)$k);
            }
            foreach ($v as $e) {
                $werte[] = $this->hinzu($e);
            }
            $this->objekte[$idx] = ['dict', $schluessel, $werte];
        } else {
            $this->objekte[$idx] = ['wert', $v];
        }
        return $idx;
    }

    private static function uint(int $v, int $groesse): string
    {
        return match ($groesse) {
            1 => chr($v),
            2 => pack('n', $v),
            4 => pack('N', $v),
            default => pack('J', $v),
        };
    }

    private static function marke(int $typ, int $laenge): string
    {
        return $laenge < 15 ? chr(($typ << 4) | $laenge) : chr(($typ << 4) | 0xF) . self::ganzzahl($laenge);
    }

    private static function ganzzahl(int $v): string
    {
        if ($v >= 0 && $v < 256) {
            return "\x10" . chr($v);
        }
        if ($v >= 0 && $v < 65536) {
            return "\x11" . pack('n', $v);
        }
        if ($v >= 0 && $v < 4294967296) {
            return "\x12" . pack('N', $v);
        }
        return "\x13" . pack('J', $v);
    }

    private static function serialisieren(array $obj, int $ref): string
    {
        if ($obj[0] === 'array') {
            $s = self::marke(0xA, count($obj[1]));
            foreach ($obj[1] as $r) {
                $s .= self::uint($r, $ref);
            }
            return $s;
        }
        if ($obj[0] === 'dict') {
            $s = self::marke(0xD, count($obj[1]));
            foreach (array_merge($obj[1], $obj[2]) as $r) {
                $s .= self::uint($r, $ref);
            }
            return $s;
        }
        $v = $obj[1];
        return match (true) {
            $v === null => "\x00",
            $v === false => "\x08",
            $v === true => "\x09",
            is_int($v) => self::ganzzahl($v),
            is_float($v) => "\x23" . pack('E', $v),
            $v instanceof AirPlayBplistData => self::marke(0x4, strlen($v->bytes)) . $v->bytes,
            is_string($v) && preg_match('/^[\x00-\x7F]*$/', $v) === 1 => self::marke(0x5, strlen($v)) . $v,
            is_string($v) => self::utf16($v),
            $v === [] => "\xD0",
            default => throw new InvalidArgumentException('bplist: unsupported type'),
        };
    }

    private static function utf16(string $v): string
    {
        $u = (string)mb_convert_encoding($v, 'UTF-16BE', 'UTF-8');
        return self::marke(0x6, intdiv(strlen($u), 2)) . $u;
    }

    public static function decode(string $daten): mixed
    {
        if (!str_starts_with($daten, 'bplist00') || strlen($daten) < 40) {
            throw new AirPlayFehler('bplist: invalid data');
        }
        $t = substr($daten, -32);
        $offGroesse = ord($t[6]);
        $refGroesse = ord($t[7]);
        $anzahl = unpack('J', substr($t, 8, 8))[1];
        $oben = unpack('J', substr($t, 16, 8))[1];
        $tabelle = unpack('J', substr($t, 24, 8))[1];
        $offsets = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $offsets[] = self::lesenUint($daten, $tabelle + $i * $offGroesse, $offGroesse);
        }
        return self::lesen($daten, $offsets, $oben, $refGroesse, 0);
    }

    private static function lesenUint(string $d, int $pos, int $groesse): int
    {
        $v = 0;
        for ($i = 0; $i < $groesse; $i++) {
            $v = ($v << 8) | ord($d[$pos + $i]);
        }
        return $v;
    }

    private static function laenge(string $d, int &$pos, int $niedrig): int
    {
        if ($niedrig !== 0xF) {
            return $niedrig;
        }
        $groesse = 1 << (ord($d[$pos]) & 0xF);
        $l = self::lesenUint($d, $pos + 1, $groesse);
        $pos += 1 + $groesse;
        return $l;
    }

    private static function lesen(string $d, array $off, int $ref, int $rg, int $tiefe): mixed
    {
        if ($tiefe > 32 || !isset($off[$ref])) {
            throw new AirPlayFehler('bplist: invalid reference');
        }
        $pos = $off[$ref];
        $m = ord($d[$pos]);
        $typ = $m >> 4;
        $niedrig = $m & 0xF;
        $pos++;
        switch ($typ) {
            case 0x0:
                return $niedrig === 8 ? false : ($niedrig === 9 ? true : null);
            case 0x1:
                $groesse = 1 << $niedrig;
                if ($groesse >= 8) {
                    return unpack('J', substr($d, $pos + $groesse - 8, 8))[1];
                }
                return self::lesenUint($d, $pos, $groesse);
            case 0x2:
                return $niedrig === 2 ? unpack('G', substr($d, $pos, 4))[1] : unpack('E', substr($d, $pos, 8))[1];
            case 0x3:
                return unpack('E', substr($d, $pos, 8))[1] + 978307200;
            case 0x4:
                $l = self::laenge($d, $pos, $niedrig);
                return new AirPlayBplistData(substr($d, $pos, $l));
            case 0x5:
                $l = self::laenge($d, $pos, $niedrig);
                return substr($d, $pos, $l);
            case 0x6:
                $l = self::laenge($d, $pos, $niedrig);
                return (string)mb_convert_encoding(substr($d, $pos, $l * 2), 'UTF-8', 'UTF-16BE');
            case 0x8:
                return self::lesenUint($d, $pos, $niedrig + 1);
            case 0xA:
                $l = self::laenge($d, $pos, $niedrig);
                $out = [];
                for ($i = 0; $i < $l; $i++) {
                    $out[] = self::lesen($d, $off, self::lesenUint($d, $pos + $i * $rg, $rg), $rg, $tiefe + 1);
                }
                return $out;
            case 0xD:
                $l = self::laenge($d, $pos, $niedrig);
                $out = [];
                for ($i = 0; $i < $l; $i++) {
                    $k = self::lesen($d, $off, self::lesenUint($d, $pos + $i * $rg, $rg), $rg, $tiefe + 1);
                    $out[(string)$k] = self::lesen($d, $off, self::lesenUint($d, $pos + ($l + $i) * $rg, $rg), $rg, $tiefe + 1);
                }
                return $out;
        }
        return null;
    }
}

final class AirPlayTlv8
{
    /** @param array<int, array{0:int,1:string}> $eintraege */
    public static function encode(array $eintraege): string
    {
        $out = '';
        foreach ($eintraege as [$tag, $wert]) {
            if ($wert === '') {
                $out .= chr($tag) . "\0";
                continue;
            }
            foreach (str_split($wert, 255) as $stueck) {
                $out .= chr($tag) . chr(strlen($stueck)) . $stueck;
            }
        }
        return $out;
    }

    /** @return array<int, string> */
    public static function decode(string $daten): array
    {
        $out = [];
        $pos = 0;
        $n = strlen($daten);
        while ($pos + 2 <= $n) {
            $tag = ord($daten[$pos]);
            $laenge = ord($daten[$pos + 1]);
            $out[$tag] = ($out[$tag] ?? '') . substr($daten, $pos + 2, $laenge);
            $pos += 2 + $laenge;
        }
        return $out;
    }
}

/** SRP-6a Client, 3072 Bit, SHA-512, Rechenweise wie srptools. */
final class AirPlaySrp
{
    private const N_HEX =
        'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DD'
        . 'EF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED'
        . 'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F'
        . '83655D23DCA3AD961C62F356208552BB9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B'
        . 'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF6955817183995497CEA956AE515D2261898FA0510'
        . '15728E5A8AAAC42DAD33170D04507A33A85521ABDF1CBA64ECFB850458DBEF0A8AEA71575D060C7DB3970F85A6E1E4C7'
        . 'ABF5AE8CDB0933D71E8C94E04A25619DCEE3D2261AD2EE6BF12FFA06D98A0864D87602733EC86A64521F2B18177B200C'
        . 'BBE117577A615D6C770988C0BAD946E208E24FA074E5AB3143DB5BFCE0FD108E4B82D120A93AD2CAFFFFFFFFFFFFFFFF';

    private \GMP $n;
    private \GMP $g;
    private \GMP $a;
    public string $oeffentlichA;
    /** Sitzungsschlüssel K = H(S), 64 Bytes */
    public string $k = '';
    /** Beweis M1, 64 Bytes */
    public string $m = '';

    public function __construct(private string $benutzer = 'Pair-Setup', private string $pin = '3939', ?string $aBytes = null)
    {
        $this->n = gmp_init(self::N_HEX, 16);
        $this->g = gmp_init(5);
        $this->a = gmp_import($aBytes ?? random_bytes(32));
        $this->oeffentlichA = self::bytes(gmp_powm($this->g, $this->a, $this->n));
    }

    private static function bytes(\GMP $v): string
    {
        $s = gmp_export($v);
        return $s === '' ? "\0" : $s;
    }

    private function auffuellen(\GMP $v): string
    {
        return str_pad(self::bytes($v), 384, "\0", STR_PAD_LEFT);
    }

    private static function h(string ...$teile): string
    {
        return hash('sha512', implode('', $teile), true);
    }

    private static function hZahl(string ...$teile): \GMP
    {
        return gmp_import(self::h(...$teile));
    }

    /** Salz und öffentlichen Schlüssel B des Geräts verarbeiten; setzt k und m. */
    public function verarbeiten(string $salz, string $b): void
    {
        $B = gmp_import($b);
        if (gmp_cmp(gmp_mod($B, $this->n), 0) === 0) {
            throw new AirPlayFehler('SRP: invalid public key of the device');
        }
        $A = gmp_import($this->oeffentlichA);
        // srptools nutzt das Salz als rohe Bytes, alle Zahlen sonst minimal kodiert
        $k = self::hZahl(self::bytes($this->n), $this->auffuellen($this->g));
        $u = self::hZahl($this->auffuellen($A), $this->auffuellen($B));
        $x = self::hZahl($salz, self::h($this->benutzer . ':' . $this->pin));
        $v = gmp_powm($this->g, $x, $this->n);
        $basis = gmp_mod(gmp_sub($B, gmp_mul($k, $v)), $this->n);
        $S = gmp_powm($basis, gmp_add($this->a, gmp_mul($u, $x)), $this->n);
        $this->k = self::h(self::bytes($S));
        $hNg = gmp_xor(self::hZahl(self::bytes($this->n)), self::hZahl(self::bytes($this->g)));
        $this->m = self::h(
            self::bytes($hNg),
            self::bytes(self::hZahl($this->benutzer)),
            $salz,
            self::bytes($A),
            self::bytes($B),
            $this->k
        );
    }
}

/** ChaCha20-Poly1305 (IETF) mit 8-Byte-Zähler als Nonce, vorne vier Nullbytes. */
final class AirPlayChaCha
{
    private int $aus = 0;
    private int $ein = 0;

    public function __construct(private string $schluesselAus, private string $schluesselEin)
    {
    }

    public static function nonce(int $zaehler): string
    {
        return "\0\0\0\0" . pack('P', $zaehler);
    }

    /** Nonce, die der nächste encrypt()-Aufruf verwendet. */
    public function naechsteNonce(): string
    {
        return self::nonce($this->aus);
    }

    public function encrypt(string $daten, string $aad = ''): string
    {
        return sodium_crypto_aead_chacha20poly1305_ietf_encrypt($daten, $aad, self::nonce($this->aus++), $this->schluesselAus);
    }

    public function decrypt(string $daten, string $aad = ''): string
    {
        $klar = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($daten, $aad, self::nonce($this->ein++), $this->schluesselEin);
        if ($klar === false) {
            throw new AirPlayFehler('decryption failed');
        }
        return $klar;
    }
}

final class AirPlayHkdf
{
    public static function sha512(string $salz, string $info, string $geheimnis): string
    {
        return hash_hkdf('sha512', $geheimnis, 32, $info, $salz);
    }
}
