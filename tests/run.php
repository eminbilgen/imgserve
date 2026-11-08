<?php
declare(strict_types=1);

// Dependency-free test runner:  php tests/run.php   (needs the GD extension)
require __DIR__ . '/../src/Imgserve.php';

use Imgserve\Config;
use Imgserve\HttpError;
use Imgserve\Imgserve;
use Imgserve\Params;

$pass = 0;
$fail = 0;
function t(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        $pass++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
    }
}
function eq(mixed $a, mixed $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ' got ' . var_export($a, true));
    }
}
function throwsStatus(callable $fn, int $status): void
{
    try {
        $fn();
    } catch (HttpError $e) {
        eq($e->status, $status, 'status');
        return;
    }
    throw new RuntimeException("expected HttpError $status");
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imgserve-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/images/sub', 0777, true);
mkdir($tmp . '/cache', 0777, true);
file_put_contents($tmp . '/secret.txt', 'secret');

// fixtures: a 1200x800 JPEG with a left-red / right-blue split, a 600x600 PNG with transparency, a 100x60 JPEG
$im = imagecreatetruecolor(1200, 800);
imagefilledrectangle($im, 0, 0, 599, 799, imagecolorallocate($im, 220, 30, 30));
imagefilledrectangle($im, 600, 0, 1199, 799, imagecolorallocate($im, 30, 30, 220));
imagejpeg($im, $tmp . '/images/wide.jpg', 90);
imagejpeg($im, $tmp . '/images/sub/nested.jpg', 90);
$png = imagecreatetruecolor(600, 600);
imagealphablending($png, false);
imagesavealpha($png, true);
imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
imagefilledellipse($png, 300, 300, 400, 400, imagecolorallocatealpha($png, 10, 200, 10, 0));
imagepng($png, $tmp . '/images/logo.png');
$small = imagecreatetruecolor(100, 60);
imagejpeg($small, $tmp . '/images/small.jpg', 90);
file_put_contents($tmp . '/images/notes.txt', 'not an image');

$cfg = new Config($tmp . '/images', $tmp . '/cache');
$get = fn (array $q, array $s = []) => Imgserve::handle($q, $s, $cfg);

echo "parse\n";
t('snaps widths to the nearest allowed size', function () use ($cfg) {
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '700'], $cfg)->w, 640);
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '740'], $cfg)->w, 800);
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '99999'], $cfg)->w, 2400);
});
t('needs at least w or h', fn () => throwsStatus(fn () => Imgserve::parse(['src' => 'a.jpg'], $cfg), 400));
t('rejects non numeric sizes', fn () => throwsStatus(fn () => Imgserve::parse(['src' => 'a.jpg', 'w' => '12abc'], $cfg), 400));
t('rejects an unknown fit and format', function () use ($cfg) {
    throwsStatus(fn () => Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'fit' => 'stretch'], $cfg), 400);
    throwsStatus(fn () => Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'fmt' => 'bmp'], $cfg), 400);
});
t('clamps quality between 40 and 95', function () use ($cfg) {
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'q' => '5'], $cfg)->q, 40);
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'q' => '100'], $cfg)->q, 95);
});
t('quantizes quality and focal point so they cannot flood the cache', function () use ($cfg) {
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'q' => '83'], $cfg)->q, 85);
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'q' => '82'], $cfg)->q, 80);
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'fx' => '0.512'], $cfg)->fx, 0.5);
    eq(Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'fx' => '0.99'], $cfg)->fx, 1.0);
    $a = Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'q' => '81', 'fx' => '0.51'], $cfg);
    $b = Imgserve::parse(['src' => 'a.jpg', 'w' => '300', 'q' => '79', 'fx' => '0.49'], $cfg);
    eq([$a->q, $a->fx], [$b->q, $b->fx], 'nearby values share one cache entry');
});

echo "source resolution and security\n";
t('serves a file inside the root, including sub folders', function () use ($cfg, $tmp) {
    eq(basename(Imgserve::resolveSource('sub/nested.jpg', $cfg)), 'nested.jpg');
});
t('blocks path traversal in every form', function () use ($cfg) {
    foreach (['../secret.txt', '..%2Fsecret.txt', 'sub/../../secret.txt', '/etc/passwd', "wide.jpg\0.png", '..\\secret.txt', './../secret.txt', 'C:\\Windows\\win.ini'] as $bad) {
        try {
            Imgserve::resolveSource($bad, $cfg);
        } catch (HttpError $e) {
            continue;
        }
        throw new RuntimeException("not blocked: " . json_encode($bad));
    }
});
t('only allows image extensions', fn () => throwsStatus(fn () => Imgserve::resolveSource('notes.txt', $cfg), 415));
t('missing files are 404 and do not leak paths', function () use ($cfg, $tmp) {
    try {
        Imgserve::resolveSource('nope.jpg', $cfg);
    } catch (HttpError $e) {
        eq($e->status, 404);
        if (str_contains($e->getMessage(), $tmp)) {
            throw new RuntimeException('path leaked');
        }
        return;
    }
    throw new RuntimeException('expected 404');
});

echo "geometry\n";
$P = fn (int $w, int $h, string $fit = 'cover', float $fx = 0.5, float $fy = 0.5) => new Params('x.jpg', $w, $h, $fit, 'jpeg', 80, $fx, $fy);
t('width only keeps the aspect ratio', function () use ($P) {
    $r = Imgserve::plan(1200, 800, $P(600, 0));
    eq([$r['out_w'], $r['out_h']], [600, 400]);
});
t('cover fills the box exactly and crops', function () use ($P) {
    $r = Imgserve::plan(1200, 800, $P(400, 400));
    eq([$r['out_w'], $r['out_h']], [400, 400]);
    eq([$r['src_w'], $r['src_h']], [800, 800]);
    eq($r['src_x'], 200, 'centered crop');
});
t('cover respects the focal point', function () use ($P) {
    eq(Imgserve::plan(1200, 800, $P(400, 400, 'cover', 0.0, 0.5))['src_x'], 0);
    eq(Imgserve::plan(1200, 800, $P(400, 400, 'cover', 1.0, 0.5))['src_x'], 400);
});
t('contain fits inside the box without cropping', function () use ($P) {
    $r = Imgserve::plan(1200, 800, $P(400, 400, 'contain'));
    eq([$r['out_w'], $r['out_h']], [400, 267]);
    eq([$r['src_w'], $r['src_h']], [1200, 800]);
});
t('never upscales the original', function () use ($P) {
    $r = Imgserve::plan(100, 60, $P(800, 0));
    eq([$r['out_w'], $r['out_h']], [100, 60]);
    $c = Imgserve::plan(100, 60, $P(400, 400));
    eq($c['out_w'] <= 100 && $c['out_h'] <= 60, true, 'cover stays inside the original');
});

echo "format negotiation\n";
t('auto picks webp only when accepted', function () {
    eq(Imgserve::negotiate('auto', 'image/avif,image/webp,*/*', false), 'webp');
    eq(Imgserve::negotiate('auto', 'image/png,*/*', false), 'jpeg');
    eq(Imgserve::negotiate('auto', '*/*', true), 'png');
    eq(Imgserve::negotiate('png', 'image/webp', false), 'png');
});

echo "rendering and the full request\n";
t('produces the requested size and format', function () use ($get) {
    $r = $get(['src' => 'wide.jpg', 'w' => '480', 'fmt' => 'jpeg']);
    eq($r['status'], 200);
    $info = getimagesizefromstring(file_get_contents($r['file']));
    eq([$info[0], $info[1], $info[2]], [480, 320, IMAGETYPE_JPEG]);
});
t('cover crop really keeps the focal side', function () use ($get) {
    $r = $get(['src' => 'wide.jpg', 'w' => '320', 'h' => '320', 'fit' => 'cover', 'fx' => '0', 'fmt' => 'png']);
    $im = imagecreatefromstring(file_get_contents($r['file']));
    $c = imagecolorsforindex($im, imagecolorat($im, 160, 160));
    eq($c['red'] > 150 && $c['blue'] < 100, true, 'left focus should be red');
    $r = $get(['src' => 'wide.jpg', 'w' => '320', 'h' => '320', 'fit' => 'cover', 'fx' => '1', 'fmt' => 'png']);
    $im = imagecreatefromstring(file_get_contents($r['file']));
    $c = imagecolorsforindex($im, imagecolorat($im, 160, 160));
    eq($c['blue'] > 150 && $c['red'] < 100, true, 'right focus should be blue');
});
t('keeps transparency for png and webp, flattens it for jpeg', function () use ($get) {
    $r = $get(['src' => 'logo.png', 'w' => '240', 'fmt' => 'png']);
    $im = imagecreatefromstring(file_get_contents($r['file']));
    eq((imagecolorat($im, 2, 2) >> 24) & 0x7F, 127, 'png corner transparent');
    $r = $get(['src' => 'logo.png', 'w' => '240', 'fmt' => 'webp']);
    $im = imagecreatefromstring(file_get_contents($r['file']));
    eq(((imagecolorat($im, 2, 2) >> 24) & 0x7F) > 100, true, 'webp corner transparent');
    $r = $get(['src' => 'logo.png', 'w' => '240', 'fmt' => 'jpeg']);
    $im = imagecreatefromstring(file_get_contents($r['file']));
    $c = imagecolorsforindex($im, imagecolorat($im, 2, 2));
    eq($c['red'] > 240 && $c['green'] > 240 && $c['blue'] > 240, true, 'jpeg corner white');
});
t('second identical request is a cache hit', function () use ($get) {
    $first = $get(['src' => 'wide.jpg', 'w' => '800', 'fmt' => 'webp']);
    $second = $get(['src' => 'wide.jpg', 'w' => '800', 'fmt' => 'webp']);
    eq($second['headers']['X-Imgserve'], 'hit');
    eq($second['file'], $first['file']);
});
t('different parameters never share a cache file', function () use ($get) {
    $a = $get(['src' => 'wide.jpg', 'w' => '640', 'fmt' => 'jpeg', 'q' => '60']);
    $b = $get(['src' => 'wide.jpg', 'w' => '640', 'fmt' => 'jpeg', 'q' => '90']);
    eq($a['file'] !== $b['file'], true);
});
t('answers 304 when the ETag matches', function () use ($get) {
    $first = $get(['src' => 'wide.jpg', 'w' => '1024', 'fmt' => 'jpeg']);
    $again = $get(['src' => 'wide.jpg', 'w' => '1024', 'fmt' => 'jpeg'], ['HTTP_IF_NONE_MATCH' => $first['headers']['ETag']]);
    eq($again['status'], 304);
    eq($again['body'], null);
});
t('auto sends Vary: Accept and honours the Accept header', function () use ($get) {
    $r = $get(['src' => 'wide.jpg', 'w' => '480'], ['HTTP_ACCEPT' => 'image/webp,*/*']);
    eq($r['headers']['Content-Type'], 'image/webp');
    eq($r['headers']['Vary'], 'Accept');
    $r = $get(['src' => 'wide.jpg', 'w' => '480'], ['HTTP_ACCEPT' => 'text/html']);
    eq($r['headers']['Content-Type'], 'image/jpeg');
});
t('refuses originals above the pixel limit', function () use ($tmp) {
    $tight = new Config($tmp . '/images', $tmp . '/cache', maxSourcePixels: 1000);
    $r = Imgserve::handle(['src' => 'wide.jpg', 'w' => '320'], [], $tight);
    eq($r['status'], 422);
});
t('a corrupt file gives 422, not a crash', function () use ($tmp, $get) {
    file_put_contents($tmp . '/images/broken.jpg', 'definitely not a jpeg');
    eq($get(['src' => 'broken.jpg', 'w' => '320'])['status'], 422);
});
t('errors are plain text and never cached', function () use ($get) {
    $r = $get(['src' => '../secret.txt', 'w' => '320']);
    eq($r['status'], 404);
    eq($r['headers']['Cache-Control'], 'no-store');
});
t('prune removes old cache files only', function () use ($cfg, $tmp, $get) {
    $r = $get(['src' => 'wide.jpg', 'w' => '240', 'fmt' => 'jpeg']);
    touch($r['file'], time() - 40 * 86400);
    $fresh = $get(['src' => 'wide.jpg', 'w' => '320', 'fmt' => 'jpeg']);
    $n = Imgserve::prune($cfg, 30);
    eq($n >= 1, true);
    eq(is_file($r['file']), false);
    eq(is_file($fresh['file']), true);
});

// clean up
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
