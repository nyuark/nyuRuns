# Hosting nyuRuns

What the person who puts a nyuRuns project on a server has to set, and the
limits to know before real users come. It goes with the security review of
version 1.0.

## 1. Purpose and split of duties

The framework handles a request from the moment PHP receives it: routing,
access, inputs, the reply. Everything before that belongs to the host, and the
framework never edits it:

- the configuration of the web server (virtual host, TLS, client certificates);
- the firewall and what can reach the PHP server;
- `php.ini`;
- the reverse proxy or CDN, when there is one.

The framework's own settings are in `conf/` and `.env`, and are read and
written with `php nyuRun config`.

## 2. Going live

```bash
php nyuRun deploy start
```

It checks the server, creates the local files from their examples (`.env`,
`conf/access.json`, `conf/database.json`), makes the secrets that are still
empty, creates the users, and ends on the health check. Run again, it replaces
nothing that exists.

```bash
php nyuRun deploy check
```

Each row is `fine`, `warn` (deserves a look) or `fail` (requests cannot be
served, or are exposed). The command exits with a failure code when a row is a
`fail`, so a deployment script can stop on it.

Before real users come:

- Set `ENV_NAME = PROD` in `conf/system.ini`. In PROD nothing of an error is
  shown to a client, whatever `ERR_SHOW` says.
- Keep `.env` readable by its owner only (`chmod 600 .env`). It holds every
  secret; the config files only name them (`env:NAME`).
- Serve the `www` folder and nothing else. `conf`, `runs`, `src`, `temp`,
  `test` and `vendor` must not be reachable over HTTP.
- Remove from `www` what your site does not use.

## 3. Reverse proxy

Without a proxy, leave `PRX_LIST` empty: the address of a client is the one of
its connection, and no header is read.

Behind a proxy, a load balancer or a CDN, three keys of `conf/system.ini` tell
the framework whom to believe:

```ini
; Addresses or CIDR ranges of your proxies
PRX_LIST = "10.0.0.5, 192.168.0.0/24, fd00::/8"
; Header in which they give the address of the client
PRX_HEAD = X-Forwarded-For
; Header in which they give the scheme the client used
PRX_SCHM = X-Forwarded-Proto
```

Only a request whose connection comes from an address of `PRX_LIST` has those
headers read. The address header is read from right to left, and the first
address that is not one of your proxies is the client.

On the proxy itself, with nginx:

```nginx
location / {
    proxy_pass http://10.0.0.20:8080;
    proxy_set_header X-Forwarded-For   $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

With Apache (`mod_proxy` adds `X-Forwarded-For` by itself):

```apache
ProxyPass        / http://10.0.0.20:8080/
ProxyPassReverse / http://10.0.0.20:8080/
RequestHeader set X-Forwarded-Proto "https"
```

Rules:

- List only proxies you operate. Never a whole range of a provider you do not
  control, and never `0.0.0.0/0`: any client could then give itself the address
  it wants. The health check fails on it.
- The proxy must write the header itself, or add to it. One that passes the
  client's own header through untouched lets the client choose its address.
- The PHP server must not be reachable around the proxy: bind it to the private
  interface or close it at the firewall. Otherwise listing the proxy protects
  nothing.
- A CDN publishes its ranges, and they change. The list needs upkeep.

The client address matters: the limit on wrong credentials (section 5) and the
access log use it. A wrong `PRX_LIST` makes every client one address, or lets a
client pick its own.

Not understood by the framework:

- An address written with its port (`203.0.113.7:51234`), or the `Forwarded`
  header of RFC 7239. The address then falls back to the one of the connection.
  Configure the proxy to send a plain address in `X-Forwarded-For`.
- `X-Forwarded-Host`. The name of the site comes from the web server's own
  configuration, so set the server name on the PHP server to the public one.

## 4. Client certificates

The `clcert` carrier does no TLS itself. The web server checks the certificate
during the handshake and reports the outcome in server variables; the carrier
reads them. Its block in `conf/access.json` names those variables:

```json
"dfcert": { "chkvar": "SSL_CLIENT_VERIFY", "usrvar": "SSL_CLIENT_S_DN_CN", "issvar": "SSL_CLIENT_I_DN_CN", "issuer": "" }
```

- `chkvar` must hold `SUCCESS`;
- `usrvar` gives the name of the user;
- `issvar` gives the name of the issuer, compared with `issuer` when one is set.

A variable whose name starts with `HTTP_` is a request header, which anyone can
send: the carrier refuses it.

Apache:

```apache
SSLCACertificateFile /etc/apache2/ca/clients-ca.pem
SSLVerifyClient      require
SSLVerifyDepth       2
SSLOptions           +StdEnvVars
```

nginx, with PHP-FPM. nginx gives the whole subject, so the common name is taken
out of it:

```nginx
map $ssl_client_s_dn $client_cn { default ""; "~(^|,)CN=(?<cn>[^,]+)" $cn; }
map $ssl_client_i_dn $issuer_cn { default ""; "~(^|,)CN=(?<cn>[^,]+)" $cn; }

server {
    ssl_client_certificate /etc/nginx/ca/clients-ca.pem;
    ssl_verify_client      on;

    location ~ \.php$ {
        fastcgi_param SSL_CLIENT_VERIFY  $ssl_client_verify;
        fastcgi_param SSL_CLIENT_S_DN_CN $client_cn;
        fastcgi_param SSL_CLIENT_I_DN_CN $issuer_cn;
        # ... the usual fastcgi settings
    }
}
```

A manual check, with and without a certificate:

```bash
curl --cert client.pem --key client.key https://your-site.example/your/route
```

```bash
curl https://your-site.example/your/route
```

The second must be refused: by the web server when it requires a certificate,
or by the framework with a 401 "No verified client certificate".

Limits:

- TLS ended by a separate proxy is not supported in 1.0. The certificate would
  only reach PHP as request headers, which the carrier refuses.
- The issuer is matched by its common name only. Trust is decided by the
  authority file given to the web server, not by this name.
- Revocation (CRL, OCSP) is the web server's to check.
- A certificate is a factor of its own: a user with a 2FA secret is let in by
  its certificate alone.

## 5. Limit by address and account holds

Wrong credentials are counted per client address. The `limits` block sets it:

```json
"limits": { "maxtry": 10, "holdtm": 600, "folder": "temp/limits" }
```

After `maxtry` wrong credentials within `holdtm` seconds, the address is
answered 429 with `Retry-After` for `holdtm` seconds, whatever it sends. An
IPv6 address is counted with its whole /64 network. `maxtry` 0 turns the limit
off, which the health check reports.

```bash
php nyuRun cleans holds
```

```bash
php nyuRun cleans frees 203.0.113.7
```

The first lists the addresses counted and held, the second releases one.

To know:

- If the counters cannot be written, nothing is limited: the mistake is logged
  on `access`, and requests are answered as without a limit. Keep the folder
  writable.
- Counted: a wrong password, API key, 2FA code or activation code, a forged
  token, and a right password on an account that is not active.
- Not counted: a token that ended, an unknown or expired session id, a request
  without credentials.
- The native userbase also holds an account after `maxtry` failures (5 by
  default, in the `native` block). Someone who knows a user name can therefore
  keep that account held by failing on purpose. The limit by address slows it
  from one address; it does not prevent it from many.
- Several attempts sent at the very same moment can pass the count of an
  account by a few. The limit by address still applies.

## 6. Password files

The `htpass` check reads an htpasswd file.

- bcrypt, and SHA-256 or SHA-512 crypt, are accepted.
- apr1 (Apache's MD5) is accepted too, because it is what Apache's tool writes
  by default. It is weak by today's measure: write new lines with bcrypt
  (`php nyuRun config secrt htpass <user>` does).
- `{SHA}`, DES crypt and MD5 crypt are refused unless the block sets
  `"unsecure": true`, which the health check reports.
- The time of a refusal does not tell whether a name exists only when the
  file's hashes are bcrypt. With other formats, a careful measure could tell.

## 7. Two-factor codes

A code is accepted once: its 30-second step and those before it are closed for
that user.

The secrets are stored encrypted when the block of the store gives a key:

```json
"native": { ..., "tfakey": "env:NYU_TFA_KEY" }
```

```bash
php nyuRun config secrt tfakey --block=native
```

- Without a key of 32 characters, secrets are stored readable, with a warning
  on `access` each time one is made.
- Keep a copy of the key. If it is lost or changed, every encrypted secret is
  unreadable and those users are refused. Each then needs
  `php nyuRun manage twofa <user> --clear` and a new secret.
- A secret stored readable before a key was set keeps working, and is not
  encrypted until a new one is made.
- The key is not rotated in place.
- With the users file (`usrlst`), the last step is not remembered, because
  requests only read that file: a code can be used again within about 90
  seconds.
- A personal API token is a factor of its own, as a certificate is: it is not
  asked for a code.

## 8. Tokens

- A token (JWT) cannot be taken back. It is accepted until it ends, unless the
  preset names a store of users (`"authby": "native"` or `"usrlst"`): the user
  is then confirmed on every request, and a lock or a role change applies at
  once.
- Keep `lifetm` short where there is no store.
- Replacing the secret (`php nyuRun config secrt jtoken`) ends every token at
  once.
- A client is only told "Invalid token" or "Expired token". Why a token was
  refused is in the `access` log, in `FULL` mode.

## 9. PHP sessions

```json
"dfsess": { "cookie": "nyuSessn", "lifetm": 3600, "fulltm": 43200, "samest": "strict", "secure": true, "folder": "" }
```

- `secure` true sends the cookie over HTTPS only. Keep it true outside a local
  machine; behind a proxy that ends TLS, set `PRX_SCHM` (section 3).
- `samest` is the only defence against a request forged from another site.
  `strict` is the safe value. With `relaxd`, never change anything on a GET.
- `lifetm` ends a session left unused; `fulltm` ends it whatever its use.
- `folder` empty keeps the sessions in PHP's own folder, which other PHP
  applications of the same server may share. On a shared server, give it a
  folder of the project, such as `temp/sessns`.
- An unknown or expired session id is not counted against an address.

Credentials written in an address (`https://name:password@host/...`) are turned
into a header by the client and never reach the server's logs, but they stay in
the client's own history and scripts. The framework cannot see or prevent that.

## 10. Other sites calling in

A preset decides which sites' pages may read its replies, in its `rnder` block:

```json
"origns": ["https://app.example.org"]
```

With a list, only a listed origin is named in `Access-Control-Allow-Origin`.
Without one, the headers of the preset are sent as written, and several shipped
presets write `*`.

`*` is acceptable for an API whose clients send a key or a token in a header:
another site's page does not hold it. It is not acceptable with a session
cookie, which the browser sends by itself. The health check warns on that case.

## 11. Request sizes

```ini
REQ_SIZE = 8192
REQ_PART = 200
```

- `REQ_SIZE` is the largest body the framework reads, in KB. A larger one is
  answered 413, and is not kept in memory.
- `REQ_PART` bounds the parts of a multipart body sent with `PUT` or `PATCH`.
- PHP parses `POST` forms and uploads itself, before the framework: those are
  bounded by `php.ini` (`post_max_size`, `upload_max_filesize`,
  `max_file_uploads`, `max_input_vars`).
- Set the web server's own limit too (`client_max_body_size` in nginx,
  `LimitRequestBody` in Apache): it refuses a large body before PHP starts.

## 12. Errors and logs

- `ERR_SHOW` shows the details of an error to the client. It is ignored in
  PROD; keep it off in PREV too.
- `LOG_MODE`: `MINI` writes errors, warnings and notices; `BASE` adds info;
  `FULL` adds debug and the context of an error (its stack, without the
  arguments of the calls). `BASE` is enough in production.
- The log folder (`LOG_PATH`, `temp/logs` by default) must be writable by PHP
  and not served.
- The framework never logs a password, a code or a token. Keep
  `zend.exception_ignore_args = On` in `php.ini` in production anyway: it is
  PHP's own guard for what other code logs.

## 13. Routing notes

- An action that a declared route answers with is only reached through that
  route, with the methods it allows. The same action asked for by its path
  (`/app/action`) is a 404. Declare a route when you want to restrict the
  methods of an action.
- An application is named with letters and digits only.
- A key that nothing reads in a route file is told on the `runner` log at each
  request.
- A site can answer an error with its own page: `errors/<status>.html` in the
  folder of its pages (`htroot`), with `%code%` and `%error%` filled in. It is
  used for a page or a static file, and for a path no route answers in a preset
  that finds its routes `manually`. A request for an action keeps the format of
  the preset; so does an unknown path in an `autopath` preset.
