# PollProof

Election-day command system for governorship campaigns, by Ashlab Technologies.

PollProof verifies polling-unit agents, captures a signed copy of every EC8A result, tallies in parallel and flags collation discrepancies while there is still time to object.

| Folder | What | Status |
|---|---|---|
| [`command/`](command/README.md) | Laravel + Vue + MySQL monolith: the API for the agent app and the command-room web app | Setup, election-day, after-election and admin screens built and tested |
| [`mobile/`](mobile/README.md) | Flutter app for agents, supervisors and collation agents | Built and tested against the API |
| `shared/` | Signing fixture both apps test against | |

See [`command/README.md`](command/README.md) to run it locally, and [`deploy/hostinger/README.md`](deploy/hostinger/README.md) to put it on Hostinger web hosting.
