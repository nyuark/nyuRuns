# Naming rules

How every name of nyuRuns is built. `CONVENTIONS.md` gives the lengths; this file gives the grammar that fills them. The `audit` group of the tests checks both, reading the lists of this file.

Status: **draft, step 0** (0-C-06). The grammar of each kind of name is settled first; the lengths, the glossary, the verbs, the kind terms and the renaming come after it, in that order.

## 1. Principles

- A name is built from **slots**. Each kind of name has its own slots, always in the same order.
- A slot holds **one word**: a whole English word, or its single short form from the glossary (section 5) when the whole word cannot fit.
- **One concept, one word.** The same thing has the same word in every name, every class, every kind.
- **One word, one meaning.** A verb or a kind term never stands in for another one of a different size to reach a length.
- The length is reached by **lengthening a slot, never by adding one**: a whole word instead of its short form, a more precise word of the same meaning, a precise object instead of a vague one.
- A name that cannot be built this way is not invented: it goes to the review.
- **A yes or no is always a question** (decided 2026-11-12): a method, a parameter, a property or a local holding a Bool starts with a question verb (`is`, `has`, `can`...): `hasWrongSecret()`, `$canWriteFile`, `$isLoggedIn`, `$isLogged`. There is no `Flag` kind term. The one exception is an action verb returning its success (section 2, Methods).

## 2. Grammar by kind

| Kind | Length | Slots | Reads as | Examples |
|---|---|---|---|---|
| Method | 14 | verb + object [+ kind] | what it does, to what | `readRouteList()`, `makeAccessCode()` |
| Method answering yes or no | 14 | question verb + subject | a question | `hasWrongSecret()`, `isStrongRsaKey()` |
| Parameter | 12 | role + kind | what it is for, what it holds | `$givenUserName`, `$requiredRole` |
| Property | 10 | subject + kind | what the object keeps | `$accessConf`, `$routeList` |
| Local | 8 | one word, or two words | a plain name | `$fileName`, `$userRole`, `$response` |
| Loop variable | 6 | `one` + item, or `nth` + item | the current element, or its position | `$oneRow`, `$nthRow` |
| Class constant | 9 | `SUBJ_KIND` | what it lists or sets | `TYPE_LIST`, `HAND_MINS` |
| Class | free | PascalCase noun | what it is | `UserList`, `RunContext` |

### Methods
- The first slot is a **verb** of the closed list (section 3).
- The object says **what** the verb acts on, as precisely as the length allows (`RouteList`, not `Data`).
- The kind slot ends the name when the return value is not obvious from the verb and the object (section 4).
- A method answering yes or no starts with a **question verb** (`is`, `has`, `can`...) and reads as a question.
- A method starting with an **action verb** and returning a Bool tells whether its action succeeded (decided 2026-11-12): it keeps its action name, without `Flag` (`approveNewUser()`, not `approveUserFlag()`). A question verb asks about a state and changes nothing; an action verb acts and tells whether it worked.

### Parameters
- The **role** says what the value is for in this method: `given`, `required`, `wanted`... or the subject itself (`userName`).
- The **kind** says what the value holds (section 4).

### Properties
- The **subject** says what the object keeps; the **kind** says in what form.

### Locals
- **One word** of 8 letters, or **two words** shaped 4+4, 3+5 or 5+3, whole or in their glossary form (decided 2026-11-12, with the length of a local raised from 7 to 8): `$fileName`, `$newEntry`, `$routeKey`, `$response`.
- Never three pieces, never a piece shorter than 3 letters.
- A local is named for **what it holds**, plainly: the reader of the method should not need the docblock.

### Loop variables
- `one` + the item, for the current element (`$oneRow`, `$oneKey`); `nth` + the item, for its position (`$nthRow`) (decided 2026-11-12). The item is a whole word of 3 letters (`row`, `key`, `day`, `tag`); `nth` is a word, not a short form.
- The 3-letter labels of `test/audit/looplabels.txt` stay accepted until they are renamed.

### Class constants
- Upper case, `XXXX_YYYY`: the subject, then its kind (`TYPE_LIST`, `MAPS_ENVS`).
- A 4-letter form never reads as another word (`PTCH`, `WTCH`, `REPL` are refused).

## 3. Verbs

A closed list. Each verb has one meaning, written once; a method starts with one of them.

**One verb per meaning** (decided 2026-11-12): a meaning has a single verb, whatever the length of the name. There are no ladders: `get`, `read`, `fetch`, `retrieve` are not sizes of one verb. The length of a method is made up in its object, never by choosing a longer or shorter verb.

The list, reviewed on 2026-11-12 (the nyuRuns Verb Review artifact, from the 1,217 methods of `src/` and `runs/`):

| Verb | Meaning | Replaces |
|---|---|---|
| `get` | give a value the object holds or works out, without reading anything outside it | retrieve, obtain, fetch, take, peek, give |
| `read` | read from a source outside the object: a file, a store, a stream, the configuration | reads, gather, collect |
| `load` | read, then keep in the object for later calls | import, plug |
| `find` | look for something that may not be there | locate, pick, choose, guess, detect, resolve |
| `list` | give every item of a kind | lst |
| `count` | give how many there are | total |
| `set` | put a value in the object | assign, retain, keep, hold |
| `save` | keep for good, in a store or a file | store |
| `write` | send text or bytes to an output: a file, a stream, the terminal | writes, print, transmit |
| `drop` | take one thing away | del, remove, rem, forget, release, prune, free |
| `clear` | empty something, or set it back to its start | reset, nullify, rewind |
| `alter` | change something that already exists | change, edit, replace, switch, fix |
| `add` | add to a collection | attach, push, nest, place |
| `join` | make one out of several | merge, couple |
| `split` | make several out of one | fragment |
| `make` | make something new | makes, build, create, generate, new, compute, prepare |
| `parse` | read a structure out of text or bytes | |
| `render` | write a structure out as text, markup or an image | draw, describe, show |
| `cast` | turn a value into another form of the same thing | convert, encode, decode, recode |
| `check` | check, and tell what is wrong (a check answering yes or no is a question) | validate, verify, test |
| `match` | tell how two things relate | compare |
| `prompt` | ask the person at the terminal | ask, query |
| `treat` | run code, a command or a request | run, exec, execute, process, handle, apply |
| `open` | open a connection, a file, a session | connect, start, initiate |
| `shut` | close what was opened | close, exit |
| `stop` | end the run or the request at once | terminate, throw |
| `link` | tie two things together | bind |
| `send` | send to someone or something outside | deliver, reply, answer, posts |
| `log` | write to a log | trace, report, warns |
| `grant` | let someone in, or give a right | |
| `deny` | keep someone out, or refuse a request | refuse, decline |
| `wipe` | take out what should not be there | clean, tidy, trim |

**Question verbs** (a Bool, section 1): `is`, `has`, `can`, `needs`. The others found (`holds`, `allows`, `exists`, `starts`, `shows`, `takes`, `sends`, `used`, `reaches`, `lies`, `valid`, `secure`, `local`, `online`) are rephrased with these four.

**Domain verbs**, kept as they are, each with one meaning no verb above gives: `sign`, `seal`, `issue`, `enrol`, `register`, `activate`, `approve`, `recover`, `rehash`, `claim`, `mount`, `redirect`, `serve`, `cache`, `route`, `rotate`, `singularize`, `compact`, `fold`, `indent`, `escape`, `unescape`, `quote`, `mask`, `insert`, `update`, `delete` (SQL), `freeze`, `protect`, `fill`, `invoke` (a sub-command), `formats` (a format of Variable), `install`, `resize`, `crop`, `turn`, `follow`, `copy`, `share`, `remark`, `comment`, `shift`, `unwrap`, `mark`.
`cook`, `fake`, `owned` and `goes` are not verbs: their methods (`cookUserSidTxt`, `fakeHashString`, `ownedReplyType`, `goesIntoBundle`) take a verb of the list when they are renamed (decided 2026-11-12).

Every action verb is in its base form (`log`, `wipe`); a third-person form reads as a question.

The 341 methods whose first word is not a verb (187 words: `final`, `table`, `subs`, `from`...) take a verb of this list when they are renamed.

## 4. Kind terms

A closed list, the same for every kind of name. Each term has one meaning and is never used to pad a name.

The list, reviewed on 2026-11-12 (the Kind terms tab of the review artifact, from the endings of every method, parameter, property and local of `src/` and `runs/`):

| Term | Holds | Replaces |
|---|---|---|
| `Text` | any text that is not a name, a line, a code, an address, a path or a date | Txt, String, Tx, Message, Msg, Reason |
| `Name` | a name or an identifier, as text | Nme, Label |
| `Line` | one line of a text | Lns |
| `Code` | a code from a closed list: a status, a kind, a mode | Knd, Kind, Type, Typ, Mode |
| `Addr` | an address: a mail address, an IP address, a URL | Adr, Address |
| `Path` | a path to a file or a folder | Pth, Dir, File, Folder, Fldr |
| `Date` | a date or a time, in any form (its form in the docblock) | Time, Dates |
| `Count` | how many things there are | Num, Nb, Numb, Number (each renamed by what it holds) |
| `Size` | a measure: bytes, characters, pixels, a length | Len, Length, Bytes, Ceiling, Max |
| `Indx` | a position in a list or a text (a loop takes `nth` + item, section 2) | Idx, Pos, Pstn, At |
| `Secs`, `Mins`, `Days`, `Mnths`, `Years` | a length of time, named by its unit, always plural | Sec |
| `List` | an array of values, in order | Lst, Arr, Array, Vls, Values, Vals, Rows, Entries, Records, Parts, Prts, Pieces, Fields |
| `Dict` | an array of values by key | Map, Set, Options, Settings, Details, Info, Data, Envelope |
| `Conf` | a block of configuration | Config, Cfg, Cnf, Setup |
| `Key` | a key of a dictionary, or the key of an entry | Keys |
| `Value` | a value of any type | Val, Valu |
| `Raw` | content as it was read, not yet parsed: text or bytes | Content, Body, Xml, Html |
| `Obj` | an object, when its class says nothing more | Object, Inst, Hndl |
| the class name | an object named by its class: `Request`, `Registry`, `Response`, `Router`... | Reg, Rgst, Hndlr, Con (written as the glossary decides) |

- There is no term for a Bool: a yes or no is a question (section 1).
- `Array` and `Arr` held lists and dictionaries alike: each is renamed by what it holds.
- `Raw` is a kind only as the last word; as the first word, `raw` is a qualifier (`$rawValue`).
- Any other last word is a noun naming what the value is (`Role`, `User`, `Token`, `Hash`), allowed when it names what is returned (below).

**The kind slot is left out only when the name already says the kind** (decided 2026-11-12):
- the verb says it: `count...` gives a number, `is...` and `has...` a yes or no;
- the object is the kind: `readRoute()` gives a route, `makeAccessCode()` a code.

Everywhere else the kind is written (`readRouteList()`, not `readRoutes()`). The audit checks it: the last word is a kind term, or the verb is one that says its kind, or the object names what is returned.


## 5. Glossary

The short forms of the words that cannot always be written whole. A short form that is not listed here is refused by the audit.

**One short form per word** (decided 2026-11-12), chosen with care against the forms already in use: a short form must never be read as another one, above all on a small monospace line. `Rqst` (request) and `Rgst` (registry) are both refused for that reason, as `Acnt` (account) was against `Actn` (action).

**No short form of 3 letters** (decided 2026-11-12): three consonants leave too little to tell words apart. A short form has 4 letters at least. Only the standard abbreviations everyone reads keep 3 letters, on a fixed list (below). A whole word of 3 letters stays a whole word (`row`, `new`, `old`, `raw`, `key`).

**Standard abbreviations** (the fixed list, from the names of 2026-11-12; those of 4 letters or more are listed too, as they are not words): `Api`, `Css`, `Dom`, `Dsn`, `Dtd`, `Ics`, `Id3`, `Ini`, `Iso`, `Jwk`, `Jwt`, `Md5`, `Mp3`, `Mp4`, `Ncx`, `Odf`, `Ogg`, `Otp`, `Pdf`, `Pdo`, `Pem`, `Php`, `Rsa`, `Sha`, `Sql`, `Svg`, `Tar`, `Tld`, `Tls`, `Toc`, `Uri`, `Url`, `Wav`, `Www`, `Xml`, `Yml`, `Zip`, `Nyu`, `Html`, `Http`, `Json`, `Smtp`, `Yaml`, `Mime`, `Hmac`, `Totp`, `Uuid`, `Utf8`, `Ipv4`, `Exif`, `Crlf`, `Etag`, `Unix`, `Flac`, `Riff`, `Ical`, `Docx`, `Jwks`, `Ehlo`, `Apr1`. The audit reads this list, as it reads the exceptions below: the terms in backticks of the first sentence.

A short form is refused when it is, against any other short form or any word of the names:
- **one look-alike letter** away: `g`/`q`, `c`/`e`, `l`/`i`, `n`/`h`, `m`/`n`, `a`/`o`, `b`/`d`, `t`/`f`, `u`/`v` (`Rqst`/`Rgst`, `Cnt`/`Ent`);
- **two letters swapped** (`Acnt`/`Actn`, `Rel`/`Rle`, `Lgn`/`Lng`, `Smtp`/`Stmp`);
- against another short form only, **one letter away, whatever the letter** (`Frmt`/`Frnt`); against a whole word, the two rules above alone (decided 2026-11-12).

These rules refuse; they never choose. A form is chosen by hand for how it reads, not by dropping vowels mechanically (`Pset` for preset reads better than `Prst`), and **an exception is decided by the project manager, word by word, and listed here**, never made by another rule.

The inventory of 2026-11-12 found 86 look-alike pairs and 49 swapped pairs among the pieces of today's names, most of them 3-letter forms made by dropping vowels.

**Exceptions decided by the project manager** (2026-11-12): `Obj` (object), `Pos` (position), `Reg` (registry) and `Col` (column) keep 3 letters. Because of them: calendar is never `Cal` (one look-alike letter from `Col`), request is never `Req` (one look-alike letter from `Reg`), and a position held as a value still ends in the kind `Indx` (section 4), `Pos` being the word's form inside a name.

**kind is written `type` in the names of the code** (decided 2026-11-12): `$mimeTextKind` becomes `$mimeTextType`. The configuration, the codes and their documentation keep *kind* (the client kinds, the route kinds, `routKind`, `KIND_LIST`).

The list, reviewed word by word on 2026-11-12 (the Glossary tab of the review artifact, from the 233 words written short in 10 names or more):

| Word | Form | Word | Form | Word | Form |
|---|---|---|---|---|---|
| account | `Accnt` | factor | `Factr` | project | `Proj` |
| action | `Actn` | folder | `Fldr` | property | `Prop` |
| address | `Addr` | fragment | `Frag` | public | `Publ` |
| algorithm | `Algo` | handler | `Hndlr` | records | `Recds` |
| applications | `Apps` | header | `Headr` | registry | `Reg` |
| archive | `Archv` | identity | `Idnt` | relation | `Rltn` |
| arguments | `Args` | indentation | `Indent` | render | `Rndr` |
| attachments | `Atch` | index | `Indx` | response | `Resp` |
| attribute | `Attr` | instance | `Inst` | return | `Retrn` |
| audience | `Audnc` | issuer | `Issur` | seconds | `Secs` |
| binary | `Bnry` | language | `Lang` | section | `Sectn` |
| buffer | `Buff` | limiter | `Lmtr` | select | `Selct` |
| certificate | `Cert` | manager | `Mngr` | server | `Srvr` |
| channel | `Chnnl` | merged | `Mergd` | simple | `Smpl` |
| chapter | `Chapt` | message | `Mssg` | statement | `Statmt` |
| character(s) | `Char`, `Chars` | method | `Mthd` | status | `Stts` |
| client | `Clnt` | months | `Mnths` | target | `Trgt` |
| column(s) | `Col`, `Cols` | number | `Numb` | template | `Tmplt` |
| comment | `Cmnt` | object | `Obj` | transaction | `Trnsct` |
| config | `Conf` | options | `Opts` | update | `Updt` |
| connection | `Conn` | paragraph | `Prgrph` | validated | `Valid` |
| declared | `Decl` | parameter | `Prmtr` | values | `Vals` |
| default | `Dflt` | parent | `Prnt` | variable | `Varbl` |
| device | `Dvce` | parser | `Prsr` | version | `Vers` |
| dictionary | `Dict` | password | `Pass` | writer | `Wrtr` |
| document | `Docu` | picture | `Pict` | | |
| doorways | `Doors` | position | `Pos` | | |
| escape | `Escp` | preset | `Pset` | | |
| extension | `Extns` | previous | `Prev` | | |

**Written whole**, never shortened (the review's choice for these words, whatever their length): access, application, array, block, cache, cell, child, class, code, cookie, count, directory, email, entry, error, field, file, format, given, handle, height, increment, input, item, length, level, line, list, login, model, name, node, path, private, query, recipient, request, role, route, secret, session, sheet, shutdown, socket, span, stream, style, table, text, token, user, value, and every word not listed above.

Kept out of the list: *allows* (no longer a question verb, so it needs no form); the rows whose forms were real words mis-read by the inventory (`Seal`, `Cited`, `Recode`, `Retain`, `Pipe`, `Tries`, `Muted`, `Ahead`, `Atomic`).

A word written short in fewer than 10 names today gets its form, if it needs one, when its names are renamed, by the same rules, and joins this list.

## 6. Open questions of step 0

1. ~~When is the kind slot left out?~~ Decided 2026-11-12: only when the verb or the object already says it (section 4).
2. ~~A method reporting the success of an action?~~ Decided 2026-11-12: it keeps its action name, without `Flag` (section 2, Methods).
3. ~~Ladders or one verb per meaning?~~ Decided 2026-11-12: one verb per meaning (section 3).
4. ~~Bool parameters and properties?~~ Decided 2026-11-12: a question, everywhere; no `Flag` (section 1).

## 7. How the rules are applied

- **The audit reads its lists from this file** (decided 2026-11-12): the tables of sections 3, 4 and 5 are the only source of the verbs, the kind terms and the short forms; a list kept elsewhere would drift. Their shape (a table with the word or form in backticks in its first or second column) is part of the contract: change it and the audit changes with it.
- **The rename scripts live in a repository of their own** (decided 2026-11-12), outside nyuRuns: the map builder, the apply step and the vocabulary builder. They read nyuRuns' tree and this file; nyuRuns keeps only the audit.
- **Every step is built by a developer from prompts** (decided 2026-11-12), this file included, and validated on its commits.
- **The whole renaming lands in 1.0** (decided 2026-11-12): the rules, the audit, the map, its review and the rename area by area, before the public release.

Step 0 is closed, and so are the locals, the verbs (section 3), the kind terms (section 4) and the glossary (section 5), all on 2026-11-12. Next: the mechanics of the renaming (the audit first, then the map, then the rename area by area).
