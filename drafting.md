# Drafting files

Placeholder files kept in `src`, listed on 2026-10-12 during the authentication review.
None is read by the framework, by a test or by another file: each one only marks an idea.

## src/Access

| File | Content | Stands for | State |
|------|---------|------------|-------|
| `src/Access/Auth/LDAP.txt` | One comment line: an LDAP connector for decentralized authentication | A credential check on an LDAP directory | Deferred to version 1.1 |
| `src/Access/Auth/SSO.txt` | One comment line: a more complex authentication implying an SSO server | A credential check through an SSO server | Deferred to version 1.1 |
| `src/Access/Keys/Complex.txt` | One comment line: a javascript authentication token for access check | A key strategy | Not planned; API keys are checked by `Auth/RegKey.php` |
| `src/Access/Keys/Interface.txt` | The same comment line | The contract of the key strategies | Not planned; the contracts are `Auth/Handler.php` and `Sess/Handler.php` |
| `src/Access/Keys/Simple.txt` | The same comment line | A key strategy | Not planned; API keys are checked by `Auth/RegKey.php` |
| `src/Access/Sess/Cookie.txt` | The same comment line | A cookie session carrier | Dropped |
| `src/Access/Sess/Digest.txt` | The same comment line | An HTTP Digest session carrier | Dropped |
| `src/Access/Sess/PHPSess.txt` | The same comment line | The PHP session carrier | Written: `Sess/PHPSess.php` |

`src/Access/Keys` holds nothing but its three placeholders.

## src/App

| File | Content | Stands for | State |
|------|---------|------------|-------|
| `src/App/Controller.txt` | Empty | The application controller | Written: `App/Controller.php` |
| `src/App/Model.txt` | Empty | The models of an application | Written: `App/ModelStack.php` |
| `src/App/Render.txt` | Empty | The rendering of the output | Written: `Output/Response.php` |
