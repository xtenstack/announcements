# Announcements changelog

## 1.0.1 — 2026-10-31

- `views/partials/topnav.phtml` carries app_skeleton's current top-nav partial
  (ellipsis account menu and Applications switcher), so those controls no
  longer vanish on the module's own screens. Same change as the module
  monorepo's 31 Oct fix; no other change.

## 1.0.0 — 2026-10-31

First public release, MIT, in its own repository (`xtenstack/announcements`).
The code is the plugin as it ran on XTen's own Stack since August 2026
(provider status feeds since 25 September 2026); this release adds
`LICENSE`, `NOTICE`, this changelog and the README, and declares
`"license": {"keyRequired": false}` (free, no licence key) and
`"license": "MIT"` in `composer.json`.
