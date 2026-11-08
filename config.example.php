<?php
// Copy to config.php and adjust. config.php is ignored by git.
return [
    // Folder that holds your original images. Only files inside it can ever be served.
    'root' => __DIR__ . '/images',

    // Where resized files are cached. Must be writable by the web server user.
    'cache_dir' => __DIR__ . '/cache',

    // Allowed widths and heights. Requests snap to the nearest value, so nobody can fill your disk with odd sizes.
    'sizes' => [160, 240, 320, 480, 640, 800, 1024, 1280, 1600, 1920, 2400],

    // Refuse originals above this many pixels. Decoding needs roughly 4 bytes per pixel of memory.
    'max_source_pixels' => 40000000,

    'default_quality' => 80,   // 40 to 95, in steps of 5
    'max_age' => 86400,        // Cache-Control max-age, in seconds
];
