<?php
declare(strict_types=1);

// Generates four abstract sample photos for the demo (no external assets, no licences):  php tools/make-samples.php
$out = __DIR__ . '/../demo/images';
@mkdir($out, 0755, true);

function lerp(float $a, float $b, float $t): float { return $a + ($b - $a) * $t; }

$palettes = [
    'dusk'   => [[18, 12, 40], [255, 91, 46], [255, 200, 120]],
    'forest' => [[6, 28, 24], [40, 160, 110], [220, 245, 200]],
    'ocean'  => [[4, 16, 48], [30, 120, 220], [180, 235, 255]],
    'ember'  => [[24, 6, 8], [220, 50, 40], [255, 225, 160]],
];
$seed = 1;
foreach ($palettes as $name => [$a, $b, $c]) {
    mt_srand($seed++ * 7919);
    $w = 1800;
    $h = 1200;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $col = $t < 0.6
            ? [lerp($a[0], $b[0], $t / 0.6), lerp($a[1], $b[1], $t / 0.6), lerp($a[2], $b[2], $t / 0.6)]
            : [lerp($b[0], $c[0], ($t - 0.6) / 0.4), lerp($b[1], $c[1], ($t - 0.6) / 0.4), lerp($b[2], $c[2], ($t - 0.6) / 0.4)];
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) $col[0], (int) $col[1], (int) $col[2]));
    }
    imagealphablending($im, true);
    for ($i = 0; $i < 46; $i++) {
        $r = mt_rand(40, 360);
        $x = mt_rand(0, $w);
        $y = mt_rand((int) ($h * 0.15), (int) ($h * 0.95));
        $tone = mt_rand(0, 2);
        $col = [$a, $b, $c][$tone];
        imagefilledellipse($im, $x, $y, $r, $r, imagecolorallocatealpha($im, $col[0], $col[1], $col[2], mt_rand(95, 120)));
    }
    // horizon band and a bright focal point on the right third, so cropping demos are easy to read
    imagefilledrectangle($im, 0, (int) ($h * 0.78), $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 92));
    imagefilledellipse($im, (int) ($w * 0.7), (int) ($h * 0.42), 220, 220, imagecolorallocatealpha($im, 255, 255, 255, 40));
    imagefilledellipse($im, (int) ($w * 0.7), (int) ($h * 0.42), 120, 120, imagecolorallocate($im, 255, 255, 255));
    imagejpeg($im, "$out/$name.jpg", 78);
    imagedestroy($im);
    echo "$name.jpg  " . round(filesize("$out/$name.jpg") / 1024) . " KB\n";
}
