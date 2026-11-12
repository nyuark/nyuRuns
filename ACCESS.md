# Access

The access rules of nyuRuns: stores of users, roles, carriers and checks, limits, codes, sign-ups and application keys.

## Stores of users
- The framework is light by default: the example access config logs in through the users file (`userFile`), so a first install needs no database. The native userbase is one answer away (`install complete`, `--useStore=nativeDb`); devices need a `dvcToken` block in `access.json`.
- `userFile` reads `conf/users.lst` (`listFile`), a JSON `{ "users": [ ... ] }` with the field names of the `users` table. It is only read: `retainAliveFlg`, `onlineNowArray`, `makeToknString`, `buildTwoFactor` and `savePasswdFlag` answer false.
- A users file that cannot be used is refused as a whole (`Credential store unavailable`), and is not counted against the address.
- `php nyuRun install userBase` creates the users table, and the devices table when `access.json` holds a `dvcToken` block, from conf/schema; a table that exists is left as it is. It asks first which basic accounts to create, the simple version (`userDemo`, `bossDemo`) or the full one, one per role (`userDemo`, `powrDemo`, `deptDemo`, `bossDemo`; `Deploy::USER_LIST`), then the name of each, those suggested; `--noPrompt` takes the simple version with them.
- `php nyuRun install moveUser <nativeDb|userFile>` moves the users between the two stores and points the login at the new one, once every user is in it. The store moved to must hold no users; the one left is kept as it was. Identifiers, password hashes, roles, statuses, addresses, languages, personal tokens and 2FA secrets go along (re-encrypted when the `tfaCrypt` differs). Active devices stop a move to the users file; `--dropsAll` revokes them. `--yesToAll` is the yes given beforehand; `--noPrompt` alone moves nothing.
- `UserFile::readUsersArray()` and `fillEmptyStore()` carry hashes and secrets, for that move only: their values are never shown.

## Roles
- Roles are ordered, `standard` < `advanced` < `delegate` < `managers`: a role asked for is a minimum (`Checker::rleAccessMeets()`).
- A preset asks for the least role of a route with the `minRoles` key of its `security` block, a list of `route: role` written as `openList` (`app/action`, `app/*`, `/declared/path`). When several entries name the route, the highest role applies; a request naming no action takes the highest of its application. A `/declared/path` naming a route to an action gives its role to that action on every path that reaches it: autopath, a Run route, another alias.
- An action asks for a role itself with `needsActionRle()`, or tests it with `holdsActionRle()`. The preset and the action do not override each other: both must pass.
- In `Users`, a `delegate` reads the users and approves the sign-ups (`index`, `view`, `approve`: `Users::ACTN_ROLE`); every other action, and any one added later, asks for `managers`. The delivered presets give `users/*` to `delegate` and name each action changing an account to stay `managers`. Approving a sign-up leaves its role (`standard`).
- A logged user without the role gets a 404 `Not Found`, as for a route that does not exist, with one warning on `access` (`Access refused for user ... to ...: role ... needed`); nobody logged gets the 401 first on a route, a 404 from the helpers. Application keys and the command line keep their 403.
- A check that gives no role (`htpasswd`, `regdKeys`, a certificate without a store, a plug-in) makes a `standard` user: a higher role needs a store (`nativeDb`, `userFile`). A `jwtToken` without a store keeps the role it was issued with.
- A device acts with the lower of its cap (`deviceTops`) and its user's current role, so demoting the user lowers all their devices; an empty cap is the user's role; a cap that is not a role refuses the device as `Invalid API key`, with a warning on `access`, not counted against the address. A device never changes its user's account: `Actionset::needsPasswdLgn()` answers 403 to a device token (`auth/setpass`, and `users/setmail` on the device's own user). `install checkAll` warns about the uncapped devices of a user above `standard`, and fails on a devices table without the column (`device caps`).

## Configurations refused
- A `minRoles` that cannot be applied refuses every request with a 500 (`Access misconfigured`): an unknown role, a route also in `openList`, `"required": false`, a `/path` that no route declares, or a check given no router; `install checkAll` fails its `access route` row on such a path.
- Route files hold no access key: a role is never declared in a `.rte` file.
- A carrier and a check that cannot work together (`httpBasc` with `regdKeys` or no check, `apiHeadr` with `htpasswd` or no check) refuse every request with a 500 (`Checker::checkPairFlaws()`, `Checker::PAIR_LIST`).
- Without an access config (`conf/access.json` missing or empty), a preset that checks access answers 500 `Access misconfigured` before its carrier runs; an open preset is still served.

## Credentials, limits and sessions
- A right password on an account that is not active is answered `Invalid credentials`, as a wrong one, and counted against the address; the status is told in the log only.
- Counted against the address: a wrong password, key or code, and a forged token. Not counted: a token that ended, an unknown session id, a missing credential.
- Counted against the address of the client: every sign-up and every reset asked, beside the wrong codes.
- A counter of the address whose hold time has passed is removed when it is read (`Limiter::heldUntilCount()`).
- A token is refused as `Invalid token` or `Expired token` only; one without `exp` or `userName` is not valid, nor one whose `iss` or `aud` is not the block's, which is not counted against the address.
- The `Auth` actions answer 400 to a password sent in the query string (`Request::isSentViaQuery()`).
- A PHP session ends after `lifeSecs` unused or `fullSecs` in all; a login closes the session the request came with.

## Codes, sign-ups, resets and languages
- A code (activation, reset) is 10 characters without look-alikes, `XXXX-XXXX-XX`, read whatever its case and dashes; only its hash is stored. A code handed over lasts 300 minutes (`UserBase::HAND_MINS`, `--noExpiry` on the command line only for none), a mailed one `codeMins`.
- A sign-up (`auth/signup`) confirms its address with its code, then waits in `inReview` until a manager approves it (`users/approve`, `account approves`); refusing is removing. A name or an address taken gets the same 202, and the address a notice. Unconfirmed sign-ups are removed once their code ends. The `tryLimit` block bounds them: `maxQueue` waiting at once (256, then a 503), `maxPerIp` per client within `holdSecs` (16, counted apart from wrong credentials), and `alertsAt` the counts at which the managers are mailed (`waitlist`; 128, 200, 256).
- A reset asked by the user (`auth/forgot`, by name or address, always 202) keeps the password until `auth/recover` uses the code; a reset by a manager voids it at once.
- A mail goes in the language of the user it is for (`userLocale`), else in the one of the request when that user is the one asking (sign-up, forgot, activation), else in `DEF_LANG`; a manager's request never gives its own. An address taken is told in the language of its owner. The activation gives its language to a user who has none, and never replaces one.
- `auth/usrlang` sets the language of the logged user, `users/langs` the one of any user; `users/create` takes it as `saveLang`.

## Application keys
- An application calls for one of its users with its key and a token it signed, checked with its `publFile` (RS256), on every method (`Checker::verifyAppCalls()`). The user is not looked up in the project's own store, the role is capped at `roleTops`, and the result names the application under `appsName`. A route the key does not list (written as `openList`) is a 403; a wrong key is a 401, counted against the address; an `openList` route ignores the key.
