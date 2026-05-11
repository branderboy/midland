=== Midland pCloud Embed ===
Contributors: tagglefish
Tags: pcloud, embed, shortcode, iframe, gallery
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Embed a pCloud public folder/file viewer in any WordPress page or post with a simple shortcode.

== Description ==

Drop a pCloud public link into any page using a shortcode. Useful for showcasing portfolios, project folders, or any files you keep in pCloud without re-uploading to WordPress.

== Usage ==

Add this shortcode to a page or post:

`[pcloud_embed code="VZHpIO5Z7KvjcxUp7Dho35lBGEeKe0uTT2CV"]`

Attributes:

* `code`   - (required) The pCloud public link code (the value after `?code=` in the share URL).
* `height` - (optional) Iframe height. Default `800`. Accepts plain pixels (`600`) or units (`80vh`, `100%`).
* `width`  - (optional) Container width. Default `100%`.
* `title`  - (optional) Iframe accessibility title. Default `pCloud`.

== Notes ==

The shortcode embeds pCloud's official public viewer at `u.pcloud.link/publink/show`. If pCloud serves the page with an `X-Frame-Options: DENY` or `Content-Security-Policy: frame-ancestors` header, the iframe will be blocked by the browser — in that case you'll need to switch to a native gallery built against the pCloud API instead.
