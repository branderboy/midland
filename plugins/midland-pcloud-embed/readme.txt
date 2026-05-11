=== Midland pCloud Embed ===
Contributors: tagglefish
Tags: pcloud, embed, shortcode, iframe, gallery
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Embed a pCloud public folder/file viewer in any WordPress page or post with a simple shortcode.

== Description ==

Drop a pCloud public link into any page using a shortcode. Useful for showcasing portfolios, project folders, or any files you keep in pCloud without re-uploading to WordPress.

== Usage ==

Add this shortcode to a page or post:

`[pcloud_embed code="VZHpIO5Z7KvjcxUp7Dho35lBGEeKe0uTT2CV"]`

Attributes:

* `code`          - (required) The pCloud public link code (the value after `?code=` in the share URL).
* `height`        - (optional) Visible viewport height. Default `800`. Accepts plain pixels (`600`) or units (`80vh`, `100%`).
* `width`         - (optional) Container width. Default `100%`.
* `title`         - (optional) Iframe accessibility title. Default `pCloud`.
* `hide_chrome`   - (optional) `true` (default) crops pCloud's header (and optional footer) out of view. Set `false` to show the full pCloud viewer.
* `header_offset` - (optional) Pixels to crop off the top of the iframe. Default `120` — matches pCloud's logo + folder/Download/Save toolbar. Tune up/down if pCloud changes their layout.
* `footer_offset` - (optional) Pixels to crop off the bottom of the iframe. Default `0`. Bump this if pCloud renders a footer bar.

Example with a taller viewport and a footer to crop:

`[pcloud_embed code="VZHpIO5Z..." height="900" header_offset="120" footer_offset="40"]`

== How the chrome hiding works ==

pCloud's viewer is on a different origin (`u.pcloud.link`), so we can't reach inside the iframe to delete elements with CSS or JavaScript. Instead the shortcode wraps the iframe in a clipping container: the iframe is rendered taller than the wrapper and shifted up by `header_offset` pixels, so the wrapper's `overflow: hidden` clips pCloud's top bar (logo, account avatar, folder name, Download / Save to pCloud buttons, view toggles) out of view. The footer offset works the same way on the bottom. The hidden elements still load — they're just not visible.

== Notes ==

The shortcode embeds pCloud's official public viewer at `u.pcloud.link/publink/show`. If pCloud serves the page with an `X-Frame-Options: DENY` or `Content-Security-Policy: frame-ancestors` header, the iframe will be blocked by the browser — in that case you'll need to switch to a native gallery built against the pCloud API instead.
