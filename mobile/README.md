# PollProof agent app

The Flutter app for polling-unit agents, ward and LGA supervisors and collation agents. It talks to the `command/` API.

## What it does

**Agents**
- Sign in with a one-time SMS code. On first sign-in the phone makes an Ed25519 signing key (the seed stays in Android secure storage) and registers its public key.
- **Get ready:** a practice result signed for the unit code `PRACTICE` (never counted), the 10-question quiz (marked on the server, 8 to pass), and the eve-of-election confirmation checklist, or "I can't make it", which alerts the supervisor.
- **Check in:** GPS against the unit's location and the campaign's radius and accuracy limit, with an optional or required selfie. Outside the area, the agent can ask the supervisor to confirm.
- **Result:** photo of the EC8A → figures, with live totals and an over-vote warning → review → sign. The signed figures go first; the photo follows and must hash to the fingerprint that was signed. Corrections are new records linked to the first one.
- **Outbox:** everything is saved on the phone before it is sent and retried until it goes (survives restarts). Results, check-ins and incidents can be resent safely; the server recognises repeats. If data doesn't come back, **Send result by SMS** opens the SMS app with the result in the fallback format.
- Incidents with a photo, messages from the command room, and the supervisor's number to call.

**Ward and LGA supervisors:** agents in their ward or LGA (present, absent, to confirm, reserves), approving or rejecting outside-area check-ins with the selfie, sending a reserve to an absent agent's unit, and setting a unit's GPS pin while standing at it.

**Collation agents:** type the declared EC8B (ward) or EC8C (LGA) figures and see at once how they compare with our signed unit results.

## Signing contract

`lib/core/signing.dart` builds the canonical payload exactly as the server's `ResultSigner` does. Both test suites check `../shared/signing-fixture.json`, so the phone and the server agree byte for byte on the payload, its SHA-256 and the Ed25519 signature.

## Run it

Requires Flutter 3.47 or newer.

```bash
cd mobile
flutter pub get
flutter test
flutter run --dart-define=API_URL=http://10.0.2.2:8000     # Android emulator against a local `php artisan serve`
flutter build apk --release --dart-define=API_URL=https://command.your-campaign.ng
```

Long-press the logo on the sign-in screen to point the app at another server. Demo agents are `08000001001`–`08000001012`, ward supervisor `08000000100`, collation agent `08000000200`; with `POLLPROOF_SMS_DRIVER=log` the code is in `command/storage/logs/laravel.log`.

The web build (`flutter build web --no-web-resources-cdn`) is used to test the app in a browser against a local API.

## Before release

- Sign the APK with the campaign's own key (today release builds use the debug key from the Flutter template).
- Set the production `API_URL` and `POLLPROOF_SMS_NUMBER` on the server.
- Voting-progress updates and USSD are not built yet.
