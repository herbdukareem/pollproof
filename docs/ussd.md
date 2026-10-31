# USSD results through Africa's Talking

Agents whose phones have no data can send their result, check in, report an incident and check
their result's status by dialling a USSD code. Africa's Talking (AT) connects the code to PollProof.

## How it works

- AT posts `sessionId`, `serviceCode`, `phoneNumber`, `networkCode` and `text` to our callback for
  every screen. `text` is every answer so far, joined with `*` (empty on the first screen).
- We reply with plain text: `CON …` shows a screen and waits for an answer, `END …` closes the session.
- The agent is identified by the phone number the network reports, matched to the roster
  (`+2348031234567` and `08031234567` are the same number). Unknown, deactivated and reserve
  numbers get a short `END` message.
- A result goes through the same recorder as the app and SMS: one current result per unit,
  corrections supersede, overvotes raise an alert. It is marked `ussd` and unsigned, and waits for
  second-person verification like every other result. When the agent later sends the same unit
  from the app (with the EC8A photo), the app copy supersedes the USSD one automatically.
- A check-in by USSD has no GPS, so it waits for the supervisor to confirm (Agents → Check-ins to confirm).
- Check-in is daily. An agent already confirmed today is told so; a rejected check-in, or a new day, lets them check in again.
- A new check-in to confirm pops up in the command room for the DG, situation room and supervisors, adds to the count beside Agents, and notifies the ward supervisor and situation room (rule "Agent check-in needs confirming").
- If the server fails mid-session the phone shows "PollProof could not finish this (ref ABC123)" instead of the network's "technical problems" box. Search `storage/logs/laravel.log` for "USSD error ABC123" to see why.
- A half-typed result is kept for 30 minutes. If the network drops the session, the agent dials
  again and chooses **1. Continue result**.
- Screens stay under 182 characters, the limit on most Nigerian networks. Sessions time out after
  about 180 seconds of no answer, so agents should have the EC8A in hand before dialling.

## Setting it up

1. In the Africa's Talking dashboard, create a USSD channel (a shared code such as `*384*1234#`, or a
   dedicated code through AT's application with the networks).
2. Make a long random secret, for example `php -r "echo bin2hex(random_bytes(24));"`.
3. Set the callback URL of the channel to

   ```
   https://app.pollproof.online/api/v1/ussd/africastalking/<secret>
   ```

4. In `public_html/command/.env` on the server, add

   ```
   POLLPROOF_USSD_TOKEN=<secret>
   POLLPROOF_USSD_CODE=*384*1234#
   ```

   then run `php artisan optimize:clear`. The code is then shown in the invite SMS and on the
   app's "If the app fails" screen.
5. Test with AT's simulator (sandbox) using a phone number that is on the agent roster.

The secret is in the URL because AT can't send a header with USSD callbacks. Keep it out of
screenshots; if it leaks, change it in `.env` and in the AT dashboard.

## Menu

```
PollProof · AG-00003
01-01-003 Baro Town Hall
1. Send result            (or "Continue result" / "Correct my result")
2. Result status
3. I am at my unit
4. Report incident
0. Exit
```

- **1** → accredited voters → votes for each party in ballot order → rejected ballots → summary →
  `1. Send  2. Start again  0. Cancel`. A unit that already has a result first asks for the reason
  for the correction.
- **2** → total, reference and verification status (with the verifier's note if it was sent back).
- **3** → confirm presence at the unit → pending supervisor confirmation.
- **4** → type → how serious → a few words (or 1 to skip). Urgent ones alert the situation room.
