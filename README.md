# imgserve

Resize, crop and convert images on the fly with one PHP file. Ask for the size you need, get it back as WebP or JPEG, cached and ready for CDNs. No Composer, no framework, nothing but the GD extension that most PHP hosts already have.

**Live demo:** [img.bilgen.dev](https://img.bilgen.dev)

```html
<img src="imgserve.php?src=photos/cat.jpg&w=800&h=500&fit=cover&fmt=auto"
     srcset="imgserve.php?src=photos/cat.jpg&w=480&h=300 480w,
             imgserve.php?src=photos/cat.jpg&w=800&h=500 800w,
             imgserve.php?src=photos/cat.jpg&w=1280&h=800 1280w"
     sizes="(max-width: 700px) 100vw, 800px" alt="">
```

## Why

A 6 MB camera original does not belong on a phone. Generating every size by hand, or running a build step, is a chore. imgserve makes the first request pay and every later one cheap: the result is stored on disk and served with `ETag` and `Cache-Control`, so browsers and CDNs stop asking.

## Install

1. Copy `imgserve.php` and the `src/` folder to your server.
2. Copy `config.example.php` to `config.php`, then set `root` (where your originals live) and `cache_dir` (writable by the web server).
3. Request `imgserve.php?src=<file inside root>&w=800`.

Needs PHP 8.0 or newer with the GD extension (JPEG, PNG and WebP support). `exif` is optional and is used to fix the orientation of phone photos.

## Parameters

| Parameter | Meaning | Default |
|---|---|---|
| `src` | Path of the original, relative to `root`. Sub folders are fine. | required |
| `w`, `h` | Target width and height. Give one or both. Values snap to the nearest entry of `sizes`. | at least one required |
| `fit` | `cover` fills the box exactly and crops. `contain` fits inside it and keeps the whole picture. | `cover` |
| `fx`, `fy` | Focal point for `cover`, from `0` to `1`. `fx=0` keeps the left edge. | `0.5` |
| `fmt` | `auto`, `webp`, `jpeg` or `png`. `auto` sends WebP to browsers that accept it. | `auto` |
| `q` | Quality from 40 to 95 for JPEG and WebP. | `82` |

It never enlarges an image beyond its original size. With `fmt=auto` the response carries `Vary: Accept`, so shared caches keep the formats apart.

## What it protects against

- **Path traversal.** Only files that resolve inside `root` are served. `..`, absolute paths, backslashes, null bytes and symlinks that point outside are all refused.
- **Cache flooding.** Sizes snap to a fixed list, so `w=701`, `w=702` and `w=703` share one cached file instead of three.
- **Memory bombs.** Originals above `max_source_pixels` (40 megapixels by default) are refused before decoding.
- **Information leaks.** Errors are short plain-text messages with no file system paths, and are never cached.
- Only GET and HEAD are accepted, and only image extensions are served.

## Response headers

`Content-Type`, `Content-Length`, `ETag`, `Last-Modified`, `Cache-Control: public, max-age=…, stale-while-revalidate=…`, `X-Content-Type-Options: nosniff` and `X-Imgserve: hit|miss`. A matching `If-None-Match` gets a `304`.

## Housekeeping

```bash
php imgserve.php prune 30   # delete cached files untouched for 30 days
```

Run it from cron. The cache folder is safe to delete at any time; it simply refills.

If you serve the cache folder from a path that is reachable from the web, block directory listing there. The `imgserve.php` entry point is all that needs to be public.

### nginx example

```nginx
location = /imgserve.php {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/imgserve.php;
}
location ~ ^/(cache|src|tests|config\.php) { deny all; }
```

## Limits

- GD cannot write AVIF, so `auto` chooses between WebP, JPEG and PNG.
- Animated GIFs are flattened to their first frame.
- It is a small, single-purpose tool. If you need face detection or signed URLs, look at a dedicated image service.

## Develop

```bash
php tests/run.php            # 26 checks, no dependencies, needs GD
php tools/make-samples.php   # regenerates the demo images
php -S localhost:8000 -t .   # then open http://localhost:8000/demo/
```

The demo reads `config.php`; point `root` at `demo/images` to see it work locally.

## License

MIT, see [LICENSE](LICENSE).
