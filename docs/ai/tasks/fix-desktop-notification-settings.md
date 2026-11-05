# fix/desktop-notification-settings

## Problem
Inside Brainitive Desktop, Profile → Notifications offered browser push ("This device isn't
registered" + "Register this device"). The desktop app never uses browser push: its tray poller
shows system notifications itself (desktop 1.0.0, alerts.rs), so the button did nothing useful.

## Change
- `NotificationSettingsForm`: new reason `desktop` (`isDesktop()` from `@/lib/desktop`) shows an
  explanation instead of the turn on / register controls; the device list stays.
- e2e: `push-devices.spec.ts` "inside Brainitive Desktop" (desktop user agent).
- Changelog: v1.49.0 (desktop apps) tagged `Major Feature`, so the website's What's new
  highlights (the last three Major Feature releases) show it.
