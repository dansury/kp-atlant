<?php
/**
 * Ported from dansury/kraskiweb src/AudioRemux.php (module 058).
 * Pure-PHP audio remux: WebM(Opus) → Ogg(Opus).
 *
 * Why: browser MediaRecorder records Opus, but Chrome/Android wrap it in a WebM
 * (Matroska) container while Yandex SpeechKit v1 short recognition only reads
 * OggOpus / LPCM / MP3. Firefox happens to record OGG and transcribes; everyone
 * else got `null`. The shared-hosting target has no ffmpeg, so we re-wrap the
 * Opus packets ourselves: parse the WebM, pull the OpusHead (CodecPrivate) and the
 * Opus frames out of the Clusters, then emit a minimal valid Ogg Opus stream.
 *
 * Entirely best-effort: any malformed input returns null and the caller falls
 * back to sending the original bytes (i.e. never worse than before).
 */
final class AudioRemux
{
    // EBML / Matroska element IDs (with their length-descriptor bytes intact).
    private const ID_SEGMENT      = 0x18538067;
    private const ID_TRACKS       = 0x1654AE6B;
    private const ID_TRACK_ENTRY  = 0xAE;
    private const ID_TRACK_NUMBER = 0xD7;
    private const ID_CODEC_ID     = 0x86;
    private const ID_CODEC_PRIVATE = 0x63A2;
    private const ID_CLUSTER      = 0x1F43B675;
    private const ID_SIMPLE_BLOCK = 0xA3;
    private const ID_BLOCK_GROUP  = 0xA0;
    private const ID_BLOCK        = 0xA1;

    /** @return string|null Ogg-Opus bytes, or null when the input can't be remuxed. */
    public static function webmOpusToOggOpus(string $bytes): ?string
    {
        $len = strlen($bytes);
        if ($len < 4 || substr($bytes, 0, 4) !== "\x1A\x45\xDF\xA3") {
            return null; // not an EBML/WebM stream
        }

        $state = ['opusTrack' => null, 'codecPrivate' => null, 'frames' => []];
        // Walk the whole file; descend only into the masters we care about.
        self::walk($bytes, 0, $len, $state);

        $head = $state['codecPrivate'];
        if (!is_string($head) || strncmp($head, 'OpusHead', 8) !== 0 || $state['frames'] === []) {
            return null;
        }
        $preSkip = strlen($head) >= 12 ? (ord($head[10]) | (ord($head[11]) << 8)) : 3840;

        return self::buildOgg($head, $state['frames'], $preSkip);
    }

    /**
     * Recursively scan EBML elements in [$pos,$end). Collects the Opus track's
     * CodecPrivate and every Opus frame (in order). @param array<string,mixed> $st
     */
    private static function walk(string $b, int $pos, int $end, array &$st): void
    {
        $curTrackNumber = null;
        $curCodecOpus = false;
        $curCodecPrivate = null;
        while ($pos < $end) {
            [$id, $idLen] = self::readId($b, $pos, $end);
            if ($id === null) {
                return;
            }
            $pos += $idLen;
            [$size, $sizeLen] = self::readSize($b, $pos, $end);
            if ($size === null) {
                return;
            }
            $pos += $sizeLen;
            if ($size < 0 || $pos + $size > $end) {
                $size = $end - $pos; // unknown / truncated → consume the rest
            }
            $dataStart = $pos;
            $dataEnd = $pos + $size;

            switch ($id) {
                case self::ID_SEGMENT:
                case self::ID_TRACKS:
                case self::ID_BLOCK_GROUP:
                case self::ID_CLUSTER:
                    self::walk($b, $dataStart, $dataEnd, $st);
                    break;
                case self::ID_TRACK_ENTRY:
                    // Parse one track entry's children to learn if it's Opus.
                    $curTrackNumber = null;
                    $curCodecOpus = false;
                    $curCodecPrivate = null;
                    self::walkTrackEntry($b, $dataStart, $dataEnd, $curTrackNumber, $curCodecOpus, $curCodecPrivate);
                    if ($curCodecOpus && $curTrackNumber !== null) {
                        $st['opusTrack'] = $curTrackNumber;
                        $st['codecPrivate'] = $curCodecPrivate;
                    }
                    break;
                case self::ID_SIMPLE_BLOCK:
                case self::ID_BLOCK:
                    self::collectFrame($b, $dataStart, $dataEnd, $st);
                    break;
                default:
                    break; // skip
            }
            $pos = $dataEnd;
        }
    }

    /** Read TrackNumber / CodecID / CodecPrivate from a TrackEntry's children. */
    private static function walkTrackEntry(string $b, int $pos, int $end, ?int &$num, bool &$opus, ?string &$priv): void
    {
        while ($pos < $end) {
            [$id, $idLen] = self::readId($b, $pos, $end);
            if ($id === null) {
                return;
            }
            $pos += $idLen;
            [$size, $sizeLen] = self::readSize($b, $pos, $end);
            if ($size === null) {
                return;
            }
            $pos += $sizeLen;
            if ($size < 0 || $pos + $size > $end) {
                return;
            }
            $data = substr($b, $pos, $size);
            if ($id === self::ID_TRACK_NUMBER) {
                $num = self::uintOf($data);
            } elseif ($id === self::ID_CODEC_ID) {
                $opus = \str_starts_with($data, 'A_OPUS');
            } elseif ($id === self::ID_CODEC_PRIVATE) {
                $priv = $data;
            }
            $pos += $size;
        }
    }

    /** Extract the Opus frame(s) from a (Simple)Block belonging to the Opus track. */
    private static function collectFrame(string $b, int $pos, int $end, array &$st): void
    {
        [$track, $tLen] = self::readVint($b, $pos, $end); // block track number (vint)
        if ($track === null) {
            return;
        }
        $pos += $tLen;
        if ($st['opusTrack'] !== null && $track !== $st['opusTrack']) {
            return;
        }
        $pos += 2; // int16 relative timecode
        if ($pos >= $end) {
            return;
        }
        $flags = ord($b[$pos]);
        $pos += 1;
        $lacing = $flags & 0x06;
        if ($lacing === 0x00) {
            if ($pos < $end) {
                $st['frames'][] = substr($b, $pos, $end - $pos);
            }

            return;
        }
        // Laced blocks are rare for MediaRecorder audio; skip rather than corrupt.
    }

    // ---- Ogg muxing -------------------------------------------------------

    /**
     * Build an Ogg Opus stream: BOS page (OpusHead), a page with OpusTags, then the
     * audio packets paged ≤255 segments each. @param list<string> $frames
     */
    private static function buildOgg(string $head, array $frames, int $preSkip): string
    {
        $serial = random_int(1, 0x7FFFFFFF);
        $seq = 0;
        $out = '';
        // BOS page — OpusHead alone (Ogg Opus spec: identification header, own page).
        $out .= self::page([$head], 0, 0x02, $serial, $seq++);
        // Comment header — minimal OpusTags, own page.
        $vendor = 'kraskiweb';
        $tags = 'OpusTags' . self::u32(strlen($vendor)) . $vendor . self::u32(0);
        $out .= self::page([$tags], 0, 0x00, $serial, $seq++);

        // Audio pages: whole packets grouped ≤255 lacing segments per page. Granule
        // position accumulates decoded samples (48 kHz) and starts at the pre-skip.
        $granule = $preSkip;
        $pktQueue = [];
        $segCount = 0;
        $n = count($frames);
        for ($i = 0; $i < $n; $i++) {
            $f = $frames[$i];
            $segs = intdiv(strlen($f), 255) + 1;
            if ($segCount + $segs > 255 && $pktQueue !== []) {
                $out .= self::page($pktQueue, $granule, 0x00, $serial, $seq++);
                $pktQueue = [];
                $segCount = 0;
            }
            $pktQueue[] = $f;
            $segCount += $segs;
            $granule += self::opusSamples($f);
            if ($i === $n - 1) {
                $out .= self::page($pktQueue, $granule, 0x04, $serial, $seq++);
                $pktQueue = [];
                $segCount = 0;
            }
        }

        return $out;
    }

    /**
     * Emit an Ogg page from a list of whole packets (correct multi-packet lacing).
     * @param list<string> $packets
     */
    private static function page(array $packets, int $granule, int $headerType, int $serial, int $seq): string
    {
        $segTable = '';
        $body = '';
        foreach ($packets as $p) {
            $l = strlen($p);
            $full = intdiv($l, 255);
            for ($k = 0; $k < $full; $k++) {
                $segTable .= "\xFF";
            }
            $segTable .= chr($l % 255);
            $body .= $p;
        }
        $segCount = strlen($segTable);

        $header = 'OggS'
            . "\x00"                    // stream structure version
            . chr($headerType)         // header type flag
            . self::u64($granule)       // granule position
            . self::u32($serial)        // bitstream serial
            . self::u32($seq)           // page sequence number
            . "\x00\x00\x00\x00"        // CRC placeholder
            . chr($segCount)
            . $segTable;
        $page = $header . $body;
        $crc = self::crc32Ogg($page);
        // Splice the CRC into its fixed offset (22).
        return substr($page, 0, 22) . self::u32($crc) . substr($page, 26);
    }

    /** Opus packet duration in 48 kHz samples, from its TOC byte(s). */
    private static function opusSamples(string $pkt): int
    {
        if ($pkt === '') {
            return 0;
        }
        $toc = ord($pkt[0]);
        $config = $toc >> 3;
        $c = $toc & 0x03;
        // Frame size (samples @48kHz) per config band.
        static $sizes = null;
        if ($sizes === null) {
            $sizes = [];
            $silk = [480, 960, 1920, 2880];          // 10,20,40,60 ms
            $hybrid = [480, 960];                    // 10,20 ms
            $celt = [120, 240, 480, 960];            // 2.5,5,10,20 ms
            for ($i = 0; $i < 12; $i++) {
                $sizes[$i] = $silk[$i % 4];
            }
            for ($i = 12; $i < 16; $i++) {
                $sizes[$i] = $hybrid[$i % 2];
            }
            for ($i = 16; $i < 32; $i++) {
                $sizes[$i] = $celt[$i % 4];
            }
        }
        $frame = $sizes[$config] ?? 960;
        $count = match ($c) {
            0 => 1,
            1, 2 => 2,
            default => strlen($pkt) >= 2 ? (ord($pkt[1]) & 0x3F) : 1,
        };

        return $frame * max(1, $count);
    }

    // ---- low-level readers ------------------------------------------------

    /** @return array{0:int|null,1:int} EBML element ID (keeps marker) + byte length. */
    private static function readId(string $b, int $pos, int $end): array
    {
        if ($pos >= $end) {
            return [null, 0];
        }
        $first = ord($b[$pos]);
        $len = self::vintLen($first);
        if ($len === 0 || $pos + $len > $end) {
            return [null, 0];
        }
        $val = $first;
        for ($i = 1; $i < $len; $i++) {
            $val = ($val << 8) | ord($b[$pos + $i]);
        }

        return [$val, $len];
    }

    /** @return array{0:int|null,1:int} EBML size (marker stripped) + byte length. */
    private static function readSize(string $b, int $pos, int $end): array
    {
        if ($pos >= $end) {
            return [null, 0];
        }
        $first = ord($b[$pos]);
        $len = self::vintLen($first);
        if ($len === 0 || $pos + $len > $end) {
            return [null, 0];
        }
        $val = $first & (0xFF >> $len); // strip the length-marker bit
        $allOnes = ($first & (0xFF >> $len)) === (0xFF >> $len);
        for ($i = 1; $i < $len; $i++) {
            $byte = ord($b[$pos + $i]);
            $allOnes = $allOnes && $byte === 0xFF;
            $val = ($val << 8) | $byte;
        }
        if ($allOnes) {
            return [-1, $len]; // unknown size
        }

        return [$val, $len];
    }

    /** Generic vint (used for block track number): value with marker stripped. @return array{0:int|null,1:int} */
    private static function readVint(string $b, int $pos, int $end): array
    {
        return self::readSize($b, $pos, $end);
    }

    private static function vintLen(int $first): int
    {
        for ($len = 1; $len <= 8; $len++) {
            if ($first & (0x80 >> ($len - 1))) {
                return $len;
            }
        }

        return 0;
    }

    private static function uintOf(string $data): int
    {
        $v = 0;
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($data[$i]);
        }

        return $v;
    }

    private static function u32(int $v): string
    {
        return pack('V', $v & 0xFFFFFFFF);
    }

    private static function u64(int $v): string
    {
        // 64-bit little-endian; PHP ints are 64-bit on the target.
        return pack('P', $v);
    }

    /** Ogg CRC-32: poly 0x04C11DB7, init 0, no reflection, no final xor. */
    private static function crc32Ogg(string $data): int
    {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $r = $i << 24;
                for ($j = 0; $j < 8; $j++) {
                    $r = ($r & 0x80000000) ? (($r << 1) ^ 0x04C11DB7) : ($r << 1);
                    $r &= 0xFFFFFFFF;
                }
                $table[$i] = $r;
            }
        }
        $crc = 0;
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $crc = (($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) & 0xFF) ^ ord($data[$i])];
        }

        return $crc & 0xFFFFFFFF;
    }
}
