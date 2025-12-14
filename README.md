# EXYU WP Radio Stream Proxy

Secure PHP-based stream proxy for **WP Radio** WordPress plugin.  
Built to reliably handle real-world radio streams including:

- Shoutcast / Icecast
- ICY metadata
- HTTP/0.9 legacy endpoints
- Broken or non-standard stream servers

This plugin replaces remote radio stream URLs with a **local signed PHP proxy endpoint**, improving compatibility, stability and security.

---

## ✨ Features

- PHP stream proxy (no external services)
- ICY metadata handling (requested upstream, stripped for browser clients)
- HTTP/0.9 support for legacy Shoutcast servers
- Works with HTML5 `<audio>` players
- No hard-coded domain (auto-detects site URL)
- HMAC-signed stream URLs (prevents hotlinking)
- Content-Type passthrough from upstream
- Compatible with Cloudflare and reverse proxies
-  No nginx or PHP-FPM special config required (recommended but not mandatory)

---

## 🔌 Compatibility

This plugin is designed to work with:

- **WP Radio** WordPress plugin  
  (by SoftLab – https://softlabbd.com)

It hooks into the following filters:

- `wp_radio/stream_url`
- `wp_radio_station_stream`
- `wp_radio_player_stream`

---

## ⚠️ Important Disclaimer

> **This is a completely independent and original implementation.**

- This project is **NOT affiliated with SoftLab**
- This project is **NOT a fork**
- This project is **NOT based on** any existing WP Radio proxy addon
- This project **does NOT reuse** code from:
  - Radio Player Proxy
  - SoftLab proxy addons
  - Any commercial WP Radio extensions

All code in this repository was written **from scratch**.

Names like *WP Radio* are used **only to describe compatibility**, not ownership or endorsement.

---

## 🛡️ Why This Exists

Many radio streams in the wild:

- break when ICY metadata is exposed to browsers
- use HTTP/0.9 (curl fails without explicit support)
- close connections without proper headers
- fail behind HTTPS or Cloudflare

This proxy normalizes all of that **without modifying the original stream**.

---

## 🚀 Installation

1. Copy the plugin file into:
wp-content/mu-plugins/

or

wp-content/plugins/exyu-wp-radio-stream-proxy/

2. Make sure the directory exists:
mkdir -p wp-content/mu-plugins

3. The plugin auto-loads (no activation needed if mu-plugin)

---

## 🔐 Security

Each stream URL is signed using HMAC:

- Station ID
- Original stream URL
- Secret key (defined in plugin)

Only valid, generated URLs can be used.

---

## ⚙️ How It Works

1. WP Radio outputs a stream URL
2. This plugin replaces it with a local proxy URL
3. Client connects to your server
4. Server connects to upstream radio stream
5. ICY metadata is stripped
6. Clean audio stream is sent to browser

---

## 🧪 Tested With

- Icecast MP3 / AAC streams
- Shoutcast v1 / v2
- Legacy HTTP/0.9 endpoints
- Chrome / Safari / Firefox
- Cloudflare proxied sites

---

## 📄 License

MIT License  
Use at your own risk. No warranty.

---

## 👤 Author

**Luka Paunovic**  
Independent developer

---

## 🤝 Contributions

Issues and pull requests welcome.  
Keep changes focused and documented.
