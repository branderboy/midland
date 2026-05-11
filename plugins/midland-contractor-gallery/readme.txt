=== Midland Contractor Gallery ===
Contributors: tagglefish
Tags: gallery, pcloud, contractor, portfolio, lightbox
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Native WordPress gallery for contractor work photos hosted on pCloud public links. Responsive grid + lightbox. No pCloud OAuth needed.

== Description ==

A contractor's "Our Work" gallery that pulls images directly from a pCloud public folder and renders them as a native WordPress gallery — no iframe, no pCloud UI chrome, no pCloud ads. Click any thumbnail for a full-screen lightbox with prev/next and keyboard navigation.

Uses pCloud's public-link API (`showpublink`, `getpubthumb`) which works for any public folder code without an app key, OAuth, or partner approval. The share link being public is the only thing needed.

== Usage ==

Minimum:

`[contractor_gallery code="VZHpIO5Z7KvjcxUp7Dho35lBGEeKe0uTT2CV"]`

With options:

`[contractor_gallery code="VZHp..." columns="4" sort="newest" limit="24" thumb="400x400" full="2048x2048"]`

Attributes:

* `code`     - (required) pCloud public link code (value after `?code=` in the share URL).
* `columns`  - Desktop column count (1-8). Default `4`. Mobile collapses to 2-3 automatically.
* `gap`      - Pixel gap between thumbnails. Default `8`.
* `thumb`    - Thumbnail size, `NxN` format. Default `320x320` (cropped to fill the square).
* `full`     - Lightbox image size, `NxN` format. Default `1600x1600` (aspect preserved).
* `sort`     - `pcloud` (default, pCloud's own order) | `name` | `newest` | `oldest`.
* `limit`    - Cap rendered images. `0` = all. Default `0`.
* `cache`    - Folder-listing cache seconds. Default `3600`. Set `0` to disable while debugging.

== Cache ==

Tools → Contractor Gallery has a "Flush all gallery caches" button — use it after uploading new photos to pCloud so the gallery picks them up immediately instead of waiting up to an hour.

== Notes ==

* The pCloud folder must be a public share. If pCloud returns an error the shortcode shows a message to logged-in editors only (visitors see nothing).
* Subfolders inside the pCloud share are walked recursively — all images at any depth are flattened into one gallery.
* Image file types detected: jpg, jpeg, png, gif, webp, avif, bmp, tif, tiff, heic, heif.
