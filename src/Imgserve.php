<?php
declare(strict_types=1);

namespace Imgserve;

/** An error that maps straight to an HTTP status. Messages never contain file system paths. */
final class HttpError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

final class Config
{
    /**
     * @param string $root             Directory that holds the original images. Nothing outside it can be served.
     * @param string $cacheDir         Where resized files are stored.
     * @param int[]  $sizes            Allowed widths and heights. Requests snap to the nearest one, which bounds the cache.
     * @param int    $maxSourcePixels  Refuse originals bigger than this many pixels (decoding memory is roughly 4 bytes per pixel).
     * @param int    $maxAge           Cache-Control max-age in seconds.
     */
    public function __construct(
        public readonly string $root,
        public readonly string $cacheDir,
        public readonly array $sizes = [160, 240, 320, 480, 640, 800, 1024, 1280, 1600, 1920, 2400],
        public readonly int $maxSourcePixels = 40_000_000,
        public readonly int $defaultQuality = 80,
        public readonly int $maxAge = 86400,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            root: (string) $a['root'],
            cacheDir: (string) $a['cache_dir'],
            sizes: $a['sizes'] ?? [160, 240, 320, 480, 640, 800, 1024, 1280, 1600, 1920, 2400],
            maxSourcePixels: (int) ($a['max_source_pixels'] ?? 40_000_000),
            defaultQuality: (int) ($a['default_quality'] ?? 80),
            maxAge: (int) ($a['max_age'] ?? 86400),
        );
    }
}

final class Params
{
    public function __construct(
        public readonly string $src,
        public readonly int $w,       // 0 means "derive from height / keep source"
        public readonly int $h,
        public readonly string $fit,  // cover | contain
        public readonly string $fmt,  // auto | webp | jpeg | png
        public readonly int $q,
        public readonly float $fx,    // focal point for cover, 0..1
        public readonly float $fy,
    ) {
    }
}

final class Imgserve
{
    private const EXT = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
    private const MIME = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** Reads and validates the query string. */
    public static function parse(array $q, Config $c): Params
    {
        $src = $q['src'] ?? '';
        if (!is_string($src) || $src === '') {
            throw new HttpError('Missing src.', 400);
        }
        $w = self::intParam($q['w'] ?? null, 'w');
        $h = self::intParam($q['h'] ?? null, 'h');
        if ($w === 0 && $h === 0) {
            throw new HttpError('Give at least w or h.', 400);
        }
        $fit = (string) ($q['fit'] ?? 'cover');
        if (!in_array($fit, ['cover', 'contain'], true)) {
            throw new HttpError('fit must be cover or contain.', 400);
        }
        $fmt = strtolower((string) ($q['fmt'] ?? 'auto'));
        if ($fmt === 'jpg') {
            $fmt = 'jpeg';
        }
        if (!in_array($fmt, ['auto', 'webp', 'jpeg', 'png'], true)) {
            throw new HttpError('fmt must be auto, webp, jpeg or png.', 400);
        }
        $qual = isset($q['q']) ? self::intParam($q['q'], 'q') : $c->defaultQuality;
        // quality and focal point are quantized too, so they cannot be used to multiply cache files
        $qual = (int) (round(max(40, min(95, $qual ?: $c->defaultQuality)) / 5) * 5);
        $fx = round(self::unit($q['fx'] ?? 0.5, 'fx') * 20) / 20;
        $fy = round(self::unit($q['fy'] ?? 0.5, 'fy') * 20) / 20;
        return new Params($src, $w ? self::snap($w, $c->sizes) : 0, $h ? self::snap($h, $c->sizes) : 0, $fit, $fmt, $qual, $fx, $fy);
    }

    private static function intParam(mixed $v, string $name): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (!is_string($v) || !ctype_digit($v) || strlen($v) > 5) {
            throw new HttpError("$name must be a whole number.", 400);
        }
        return (int) $v;
    }

    private static function unit(mixed $v, string $name): float
    {
        if (!is_numeric($v)) {
            throw new HttpError("$name must be a number between 0 and 1.", 400);
        }
        return max(0.0, min(1.0, (float) $v));
    }

    /** Snaps to the closest allowed size so that arbitrary values cannot fill the cache. */
    public static function snap(int $value, array $sizes): int
    {
        $best = $sizes[0];
        foreach ($sizes as $s) {
            if (abs($s - $value) < abs($best - $value)) {
                $best = $s;
            }
        }
        return $best;
    }

    /** Maps the requested name to a real file inside the root, or throws. Blocks traversal and symlink escapes. */
    public static function resolveSource(string $src, Config $c): string
    {
        if (str_contains($src, "\0") || str_contains($src, '\\') || str_starts_with($src, '/') || preg_match('#(^|/)\.\.?(/|$)#', $src)) {
            throw new HttpError('Not found.', 404);
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if (!isset(self::EXT[$ext])) {
            throw new HttpError('Unsupported image type.', 415);
        }
        $root = realpath($c->root);
        $real = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $src);
        if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new HttpError('Not found.', 404);
        }
        return $real;
    }

    /** Chooses the output format. "auto" uses WebP when the browser accepts it. */
    public static function negotiate(string $fmt, string $accept, bool $sourceHasAlpha): string
    {
        if ($fmt !== 'auto') {
            return $fmt;
        }
        if (stripos($accept, 'image/webp') !== false) {
            return 'webp';
        }
        return $sourceHasAlpha ? 'png' : 'jpeg';
    }

    /**
     * Works out the output size and the crop rectangle. Never enlarges beyond the original.
     * @return array{out_w:int,out_h:int,src_x:int,src_y:int,src_w:int,src_h:int}
     */
    public static function plan(int $sw, int $sh, Params $p): array
    {
        $w = $p->w;
        $h = $p->h;
        if ($w && !$h) {
            $h = (int) round($sh * $w / $sw);
        } elseif ($h && !$w) {
            $w = (int) round($sw * $h / $sh);
        }
        if ($p->fit === 'contain' || !$p->w || !$p->h) {
            // fit inside w x h, keep the aspect ratio, no crop
            $scale = min($w / $sw, $h / $sh, 1.0);
            $ow = max(1, (int) round($sw * $scale));
            $oh = max(1, (int) round($sh * $scale));
            return ['out_w' => $ow, 'out_h' => $oh, 'src_x' => 0, 'src_y' => 0, 'src_w' => $sw, 'src_h' => $sh];
        }
        // cover: fill w x h exactly, cropping around the focal point.
        // If the original is smaller than the target, shrink the target (same aspect ratio) instead of upscaling.
        $k = min($sw / $w, $sh / $h, 1.0);
        $ow = max(1, (int) round($w * $k));
        $oh = max(1, (int) round($h * $k));
        $ratio = $ow / $oh;
        if ($sw / $sh > $ratio) {
            $cw = (int) round($sh * $ratio);
            $ch = $sh;
        } else {
            $cw = $sw;
            $ch = (int) round($sw / $ratio);
        }
        $x = (int) round(($sw - $cw) * $p->fx);
        $y = (int) round(($sh - $ch) * $p->fy);
        return ['out_w' => $ow, 'out_h' => $oh, 'src_x' => $x, 'src_y' => $y, 'src_w' => $cw, 'src_h' => $ch];
    }

    public static function cacheKey(string $real, int $mtime, int $size, Params $p, string $fmt): string
    {
        return sha1(implode('|', [$real, $mtime, $size, $p->w, $p->h, $p->fit, $fmt, $p->q, round($p->fx, 3), round($p->fy, 3)]));
    }

    /** Decodes, crops, resizes and encodes. Returns the encoded bytes. */
    public static function render(string $real, Params $p, string $fmt, Config $c): string
    {
        $info = @getimagesize($real);
        if ($info === false) {
            throw new HttpError('The file is not a readable image.', 422);
        }
        [$sw, $sh, $type] = $info;
        if ($sw * $sh > $c->maxSourcePixels) {
            throw new HttpError('The original is too large to process.', 422);
        }
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($real),
            IMAGETYPE_PNG => @imagecreatefrompng($real),
            IMAGETYPE_WEBP => @imagecreatefromwebp($real),
            IMAGETYPE_GIF => @imagecreatefromgif($real),
            default => false,
        };
        if ($src === false) {
            throw new HttpError('Could not decode the image.', 422);
        }
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($real);
            $o = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
            $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($angle) {
                $rot = imagerotate($src, $angle, 0);
                if ($rot !== false) {
                    $src = $rot;
                    if ($angle !== 180) {
                        [$sw, $sh] = [$sh, $sw];
                    }
                }
            }
        }
        $plan = self::plan($sw, $sh, $p);
        $dst = imagecreatetruecolor($plan['out_w'], $plan['out_h']);
        if ($fmt === 'jpeg') {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency on white
        } else {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagealphablending($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, $plan['src_x'], $plan['src_y'], $plan['out_w'], $plan['out_h'], $plan['src_w'], $plan['src_h']);
        ob_start();
        match ($fmt) {
            'webp' => imagewebp($dst, null, $p->q),
            'png' => imagepng($dst, null, 6),
            default => imagejpeg($dst, null, $p->q),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $bytes;
    }

    /**
     * The whole request, as data, so it can be tested without a web server.
     * @return array{status:int,headers:array<string,string>,file:?string,body:?string}
     */
    public static function handle(array $query, array $server, Config $c): array
    {
        try {
            $p = self::parse($query, $c);
            $real = self::resolveSource($p->src, $c);
            $mtime = (int) filemtime($real);
            $size = (int) filesize($real);
            $info = @getimagesize($real);
            $hasAlpha = $info !== false && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
            $accept = (string) ($server['HTTP_ACCEPT'] ?? '');
            $fmt = self::negotiate($p->fmt, $accept, $hasAlpha);
            $key = self::cacheKey($real, $mtime, $size, $p, $fmt);
            $etag = '"' . substr($key, 0, 32) . '"';
            $headers = [
                'Content-Type' => self::MIME[$fmt],
                'Cache-Control' => 'public, max-age=' . $c->maxAge . ', stale-while-revalidate=' . ($c->maxAge * 7),
                'ETag' => $etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($p->fmt === 'auto') {
                $headers['Vary'] = 'Accept';
            }
            if (($server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                return ['status' => 304, 'headers' => $headers, 'file' => null, 'body' => null];
            }
            $ext = $fmt === 'jpeg' ? 'jpg' : $fmt;
            $dir = $c->cacheDir . DIRECTORY_SEPARATOR . substr($key, 0, 2) . DIRECTORY_SEPARATOR . substr($key, 2, 2);
            $file = $dir . DIRECTORY_SEPARATOR . $key . '.' . $ext;
            $state = 'hit';
            if (!is_file($file)) {
                $state = 'miss';
                $bytes = self::render($real, $p, $fmt, $c);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new HttpError('Cache directory is not writable.', 500);
                }
                $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $file)) {
                    @unlink($tmp);
                    throw new HttpError('Could not write to the cache.', 500);
                }
            }
            $headers['X-Imgserve'] = $state;
            $headers['Content-Length'] = (string) filesize($file);
            return ['status' => 200, 'headers' => $headers, 'file' => $file, 'body' => null];
        } catch (HttpError $e) {
            return ['status' => $e->status, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'file' => null, 'body' => $e->getMessage() . "\n"];
        }
    }

    /** Deletes cached files not touched for $days days. Returns how many were removed. */
    public static function prune(Config $c, int $days): int
    {
        $limit = time() - $days * 86400;
        $removed = 0;
        if (!is_dir($c->cacheDir)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($c->cacheDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getMTime() < $limit && @unlink($f->getPathname())) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Sends a result produced by handle(). */
    public static function send(array $r): void
    {
        http_response_code($r['status']);
        foreach ($r['headers'] as $k => $v) {
            header("$k: $v");
        }
        if ($r['file'] !== null) {
            readfile($r['file']);
        } elseif ($r['body'] !== null) {
            echo $r['body'];
        }
    }
}
