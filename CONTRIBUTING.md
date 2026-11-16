# Contributing to nyuRuns

Contributions are welcome: a report of what goes wrong, a fix, a test, a
part of the documentation, or a new feature.

## Before you start

- For anything larger than a small fix, open an issue first and describe
  what you want to change, so that we can agree on it before you write it.
- Report a security problem privately, by mail to dev@nyuark.com, not in a
  public issue.

## What a pull request needs

1. **Passing tests.** Run the suite and add the checks that cover your
   change:

   ```
   php test/run.php
   ```

   `test/README.md` tells how the tests are organised, and which PHP
   extensions and servers the optional ones need.

2. **The conventions of the code.** Names have fixed lengths (methods 14
   characters, parameters 12, properties 10, local variables 8, loop
   variables 6, constants 9 as `XXXX_YYYY`), built as `NAMERULES.md` says;
   types are
   declared and written capitalised (`String`, `Array`, `Bool`), and every
   public method has its docblock. The audit group checks them:

   ```
   php test/run.php audit
   ```

3. **A sign-off on every commit**, as explained below.

## Sign-off: the Developer Certificate of Origin

nyuRuns uses the Developer Certificate of Origin (DCO), the one the Linux
kernel uses. By signing off a commit, you certify that you wrote the
contribution, or otherwise have the right to give it under the licence of
the project. Your copyright stays yours: you give your contribution under
the MIT License, as the rest of nyuRuns.

Sign off each commit by adding a line to its message:

```
Signed-off-by: Your Name <your.email@example.org>
```

Git writes that line for you with `-s`:

```
git commit -s -m "What the commit does"
```

The name must be your real name, and the address one you can be reached at.
A commit without a sign-off cannot be merged; one you forgot can be signed
afterwards with `git commit --amend -s`, or for several commits with
`git rebase --signoff`.

The certificate, as published at https://developercertificate.org:

```
Developer Certificate of Origin
Version 1.1

Copyright (C) 2004, 2006 The Linux Foundation and its contributors.

Everyone is permitted to copy and distribute verbatim copies of this
license document, but changing it is not allowed.


Developer's Certificate of Origin 1.1

By making a contribution to this project, I certify that:

(a) The contribution was created in whole or in part by me and I
    have the right to submit it under the open source license
    indicated in the file; or

(b) The contribution is based upon previous work that, to the best
    of my knowledge, is covered under an appropriate open source
    license and I have the right under that license to submit that
    work with modifications, whether created in whole or in part
    by me, under the same open source license (unless I am
    permitted to submit under a different license), as indicated
    in the file; or

(c) The contribution was provided directly to me by some other
    person who certified (a), (b) or (c) and I have not modified
    it.

(d) I understand and agree that this project and the contribution
    are public and that a record of the contribution (including all
    personal information I submit with it, including my sign-off) is
    maintained indefinitely and may be redistributed consistent with
    this project or the open source license(s) involved.
```

## Licence

nyuRuns is released under the MIT License (see `LICENSE`). The libraries it
uses keep their own licences (see `NOTICE`); a new dependency must have a
licence that allows nyuRuns to stay under MIT.
