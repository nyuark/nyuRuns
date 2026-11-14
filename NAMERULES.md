# Naming rules

How every name of nyuRuns is built. `CONVENTIONS.md` gives the lengths; this file gives the grammar that fills them. The `audit` group of the tests checks both, reading the lists of this file.

Status: **step 2, the locals** (0-C-06): the rules, the verbs, the kinds and the glossary are settled; the locals of `src/` and `runs/` are named (1,830, 2026-11-14). Parameters, properties, methods and loops come next, by the same rules.

## 1. Principles

- A name is built from **slots**. Each kind of name has its own slots, always in the same order.
- A slot holds **one word**, written whole or in one of its **writings** (section 5): each word has at most one writing of 3 letters, one of 4 and one of 5. A slot takes the writing of its length. **The whole word wins** whenever it fits: a word of 3, 4 or 5 letters is its own writing at that length, and a whole word of 8 letters fills a local alone (`boundary`, `envelope`).
- A piece is read as **the word the code means**, never by its letters alone (`rot` is root, `red` is read, `sel` is seal, `fond` is found).
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
- **One word** of 8 letters, or **two words**: 4+4, 5+3 or 3+5, each in one of its writings (decided 2026-11-12; the shapes and their order decided 2026-11-13).
- **The meaningful word stays whole.** The order tried: a plural object for a list (`rowsList`), the object kept whole (`charIndx`, `afterPos`), then 4+4, 5+3, 3+5.
- Two locals of one method never take the same name.
- A Bool local is a question verb + its subject, 8 letters like any local; after the verb, one or two pieces, a whole word of 1 or 2 letters allowed (decided 2026-11-14).
- Never three pieces elsewhere, never a piece shorter than 3 letters outside a Bool, except a standard abbreviation of 2 letters (`Id`, `Ps`, `Db`, section 5).
- The digits closing a piece belong to it (`lvl1Vals` is `Lvl1` + `Vals`; `id3v1Tag` is `Id3v1` + `Tag`).
- A local is named for **what it holds**, plainly: the reader of the method should not need the docblock. A name the rules cannot build is named by hand, and its reason written in the review (281 locals, 2026-11-14).

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

**Question verbs** (a Bool, section 1): `is`, `has`, `can`, `needs`, `was` (a state before the action, added 2026-11-14). The others found (`holds`, `allows`, `exists`, `starts`, `shows`, `takes`, `sends`, `used`, `reaches`, `lies`, `valid`, `secure`, `local`, `online`) are rephrased with these four.

**Domain verbs**, kept as they are, each with one meaning no verb above gives: `sign`, `seal`, `issue`, `enrol`, `register`, `activate`, `approve`, `recover`, `rehash`, `claim`, `mount`, `redirect`, `serve`, `cache`, `route`, `rotate`, `singularize`, `compact`, `fold`, `indent`, `escape`, `unescape`, `quote`, `mask`, `insert`, `update`, `delete` (SQL), `freeze`, `protect`, `fill`, `invoke` (a sub-command), `formats` (a format of Variable), `install`, `resize`, `crop`, `turn`, `follow`, `copy`, `share`, `remark`, `comment`, `shift`, `unwrap`, `mark`.
`cook`, `fake`, `owned` and `goes` are not verbs: their methods (`cookUserSidTxt`, `fakeHashString`, `ownedReplyType`, `goesIntoBundle`) take a verb of the list when they are renamed (decided 2026-11-12).

Every action verb is in its base form (`log`, `wipe`); a third-person form reads as a question.

The 341 methods whose first word is not a verb (187 words: `final`, `table`, `subs`, `from`...) take a verb of this list when they are renamed.

## 4. Kind terms

A closed list, the same for every kind of name. Each term has one meaning and is never used to pad a name.

The list, reviewed on 2026-11-12 (the Kind terms tab of the review artifact, from the endings of every method, parameter, property and local of `src/` and `runs/`). The terms keep their meaning; each has its writings now (2026-11-14), so the column reads them all.

| Term | Holds | Replaces |
|---|---|---|
| `Text`, `Txt`, `Strng` | any text that is not a name, a line, a code, an address, a path or a date | String, Tx, Message, Msg, Reason |
| `Name`, `Nme`, `Title` | a name or an identifier, as text | Label |
| `Line`, `Lne`, `Ligne` | one line of a text | Lns |
| `Code`, `Cod`, `Codig` | a code from a closed list: a status, a kind, a mode | Knd, Kind, Type, Typ |
| `Addr`, `Adr`, `Addrs` | an address: a mail address, an IP address, a URL | Address |
| `Path`, `Pth`, `Trail` | a path to a file or a folder | Dir, Folder |
| `Date`, `Dte`, `Stamp` | a date or a time, in any form (its form in the docblock) | Time, Dates |
| `Unix` | a moment as a Unix time | Unx |
| `Count`, `Cnt` | how many things there are | Total |
| `Numb`, `Num`, `Numbr` | a number: a count, a step, a code that is a number | Nb, Number |
| `Size`, `Sze`, `Bytes` | a measure: bytes, characters, pixels, a length | Len, Length, Ceiling, Max |
| `Indx`, `Idx`, `Index` | a position in a list (a loop takes `nth` + item, section 2) | Pstn, At |
| `Pos` | a position in a text or in bytes, after a 5-letter object (`afterPos`, `crsorPos`) | Posi, Posit |
| `Limt`, `Lmt` | where something stops | End, Ends (as a limit) |
| `Secs`, `Mins`, `Days`, `Mnths`, `Years` | a length of time, named by its unit, always plural | Sec |
| `List`, `Lst`, `Lstng` | an array of values, in order | Arr, Array, Arry, Entries, Pieces, Fields |
| `Vals`, `Val`, `Valrs` | the value or values of one thing | Vls, Values, Valu, Value |
| `Dict`, `Dct`, `Array` | an array of values by key | Map, Set, Options, Settings, Details, Info, Data |
| `Conf`, `Cfg`, `Confg` | a block of configuration | Config, Cnf, Setup |
| `Key`, `Clef`, `Clave` | a key of a dictionary, or the key of an entry | Keys |
| `Raw`, `Brut`, `Crude` | content as it was read, not yet parsed: text or bytes | Content, Body, Xml, Html |
| `Recs`, `Rec`, `Rcrd`, `Recrd` | records read from a table | Records |
| `Obj`, `Inst`, `Objct` | an object, when its class says nothing more | Object, Hndl |
| `Func` | a closure | Fn, Callback |
| `Hndl` | a handle of a file, a stream or a process | Handle, Pointer |
| `Matx` | the matches of a regular expression | Match, Matches, Mtch |
| the class name | an object named by its class: `Request`, `Registry`, `Response`, `Router`… | Reg, Rgst, Hndlr |

- There is no term for a Bool: a yes or no is a question (section 1).
- `Raw` is a kind only as the last word; as the first word, `raw` is a qualifier (`$rawValue`).
- Any other last word is a noun naming what the value is (`Role`, `User`, `Token`, `Hash`), allowed when it names what is returned (below).

**The kind slot is left out only when the name already says the kind** (decided 2026-11-12):
- the verb says it: `count...` gives a number, `is...` and `has...` a yes or no;
- the object is the kind: `readRoute()` gives a route, `makeAccessCode()` a code.

Everywhere else the kind is written (`readRouteList()`, not `readRoutes()`). The audit checks it: the last word is a kind term, or the verb is one that says its kind, or the object names what is returned.


## 5. Glossary

The short forms of the words that cannot always be written whole. A short form that is not listed here is refused by the audit.

**One ladder per word** (decided 2026-11-13): a word has at most three writings, one of 3, one of 4 and one of 5 letters, chosen word by word by the project manager on the Words tab of the review. They replace the single short form of 2026-11-12.

**Writings of 3 letters** are allowed when the word's ladder holds one (2026-11-13), and only then. They were taken out where they read as another word (2026-11-14): `Hdr`, `Hdl`, `Sht`, `Mnt`, `Alg`, `Trn`, `Idt`, `Rea`, `Roo`, `Chp`, `Hst`, `Mad`, `Met`, `3rd`; and a writing never starts with a digit.

**Standard abbreviations** (the fixed list): `Api`, `Bom`, `Css`, `Db`, `Dom`, `Dsn`, `Dtd`, `Ics`, `Id`, `Id3`, `Id3v1`, `Ini`, `Iso`, `Jwk`, `Jwt`, `Llm`, `Md5`, `Mp3`, `Mp4`, `Ncx`, `Odf`, `Ogg`, `Otp`, `Pdf`, `Pdo`, `Pem`, `Php`, `Ps`, `Rsa`, `Sha`, `Sql`, `Stty`, `Svg`, `Tar`, `Tld`, `Tls`, `Toc`, `Uri`, `Url`, `Wav`, `Www`, `Xml`, `Yml`, `Zip`, `Nyu`, `Html`, `Http`, `Json`, `Smtp`, `Yaml`, `Mime`, `Hmac`, `Totp`, `Uuid`, `Utf8`, `Ipv4`, `Exif`, `Crlf`, `Etag`, `Unix`, `Flac`, `Riff`, `Ical`, `Docx`, `Jwks`, `Ehlo`, `Apr1`. The audit reads this list, as it reads the exceptions below: the terms in backticks of the first sentence.

**Exceptions decided by the project manager** (2026-11-12, kept): `Obj`, `Pos`, `Reg`, `Col`, as ordinary writings now. Calendar is never `Cal`, request is never `Req`.

**How a writing is chosen** (2026-11-13/14):
- **A family shares its writings**: words of one root may hold the same writing (find/found, trim/trimmed, cut/cuts, encoding/encoded/encoder, repeat/repeats, cred/credential, trigger/trig, frame/frames).
- **A synonym merged is one word**: name and title, folder and directory, size and bytes, mail and email, row and entry, delete and remove.
- **`x` writes `ch`** (`Matx`, `Conx`, `Patx`).
- **A writing may come from another language** when it reads better on a monospace line (`Fichr`, `Clef`, `Clave`, `Ligne`, `Codig`, `Nouv`, `Brut`): decided word by word, never by a rule.
- **Cut the verb, not the noun**: when two words must share 8 letters, the object stays the readable one (`readLimt`, `foundBox`).

A short form is refused when it is, against any other short form or any word of the names:
- **one look-alike letter** away: `g`/`q`, `c`/`e`, `l`/`i`, `n`/`h`, `m`/`n`, `a`/`o`, `b`/`d`, `t`/`f`, `u`/`v` (`Rqst`/`Rgst`, `Cnt`/`Ent`);
- **two letters swapped** (`Acnt`/`Actn`, `Rel`/`Rle`, `Lgn`/`Lng`, `Smtp`/`Stmp`);
- against another short form only, **one letter away, whatever the letter** (`Frmt`/`Frnt`); against a whole word, the two rules above alone (decided 2026-11-12).

These rules refuse; they never choose. A form is chosen by hand for how it reads, not by dropping vowels mechanically (`Pset` for preset reads better than `Prst`), and **an exception is decided by the project manager, word by word, and listed here**, never made by another rule.

The inventory of 2026-11-12 found 86 look-alike pairs and 49 swapped pairs among the pieces of today's names, most of them 3-letter forms made by dropping vowels.

**kind is written `type` in the names of the code** (decided 2026-11-12): `$mimeTextKind` becomes `$mimeTextType`. The configuration, the codes and their documentation keep *kind* (the client kinds, the route kinds, `routKind`, `KIND_LIST`).

The list, from the Words and Ladder tabs of the review (2026-11-14):

| Word | Writings (3 / 4 / 5) | Word | Writings (3 / 4 / 5) | Word | Writings (3 / 4 / 5) |
|---|---|---|---|---|---|
| access | `Acc` / `Accs` / `Acces` | account | — / `Acct` / `Accnt` | act | `Act` / `Actn` / `Actio` |
| action | `Act` / `Actn` / `Actio` | add | `Add` / — / — | address | `Adr` / `Addr` / `Addrs` |
| agent | `Agt` / `Agnt` / `Agent` | algorithm | — / `Algo` / `Algor` | alias | `Aka` / `Alia` / `Alias` |
| align | `Aln` / `Algn` / `Align` | all | `All` / — / — | allow | — / `Allw` / `Allow` |
| alt | `Alt` / — / — | and | `And` / — / — | anna | — / `Anna` / — |
| any | `Any` / — / — | application | `App` / `Appl` / `Appli` | arch | `Arc` / `Arch` / — |
| archive | — / `Arch` / `Archv` | area | — / `Area` / — | arguments | `Arg` / `Args` / `Argmn` |
| ask | `Ask` / `Asks` / `Plead` | asked | `Ask` / `Askd` / `Asked` | attribute | `Atr` / `Attr` / `Attrb` |
| audience | `Aud` / `Audn` / `Audnc` | auth | `Aut` / `Auth` / `Authn` | auto | — / `Auto` / — |
| back | `Bck` / `Back` / — | bad | `Bad` / — / — | bare | `Bar` / `Bare` / — |
| base | `Bas` / `Base` / — | ben | `Ben` / — / — | best | `Bst` / `Best` / `Bettr` |
| binary | `Bnr` / `Bnry` / `Binry` | bind | `Bnd` / `Bind` / — | bit | `Bit` / — / — |
| bits | `Bts` / `Bits` / — | blank | — / `Blnk` / `Blank` | blip | `Blp` / `Blip` / — |
| blob | `Blb` / `Blob` / — | block | `Blc` / `Bloc` / `Block` | body | `Bdy` / `Body` / — |
| bold | `Bld` / `Bold` / — | book | `Boo` / `Book` / — | bookmark | `Bmk` / `Bkmr` / `Bkmrk` |
| border | — / `Brdr` / — | box | `Box` / — / — | bracket | — / — / `Brckt` |
| break | `Brk` / `Brek` / `Break` | browser | — / — / `Brwsr` | buffer | `Bff` / `Buff` / `Buffr` |
| bulk | `Blk` / `Bulk` / — | byte | `Byt` / `Byte` / — | bytes | `Byt` / `Byts` / `Bytes` |
| cache | `Cch` / `Cach` / `Cache` | call | — / `Call` / `Calls` | cap | `Cap` / — / — |
| capture | `Cpt` / `Cptr` / `Captr` | case | `Cas` / `Case` / — | cell | `Cel` / `Cell` / `Celda` |
| certificate | `Crt` / `Cert` / `Crtif` | chain | — / — / `Chain` | challenge | — / `Chlg` / — |
| chan | — / `Chnl` / `Chnnl` | channel | `Chn` / `Chnl` / `Chnnl` | chap | — / `Chap` / `Chptr` |
| chapter | `Chp` / `Chpt` / `Chapt` | character | `Chr` / `Char` / `Chrct` | characters | — / `Chrs` / `Chars` |
| check | `Chk` / `Chck` / `Check` | checker | `Chk` / `Chkr` / `Chckr` | child | `Chl` / `Chld` / `Child` |
| children | `Chd` / `Chld` / `Chldr` | chomp | — / `Chmp` / `Chomp` | chunk | — / `Chnk` / `Chunk` |
| cite | `Cit` / `Cite` / — | claimed | — / `Clmd` / — | clash | — / `Clsh` / `Clash` |
| class | `Cls` / `Clss` / `Class` | clauses | — / `Clau` / `Claus` | client | `Cln` / `Clnt` / `Clien` |
| close | — / `Clos` / `Close` | code | `Cod` / `Code` / `Codig` | codes | `Cds` / `Cods` / `Codes` |
| colon | — / — / `Colon` | column | `Col` / `Clmn` / `Colmn` | columns | `Col` / `Cols` / `Clmns` |
| command | — / `Cmnd` / — | comment | `Cmt` / `Cmnt` / `Cmmnt` | complete | `Cmp` / `Cmpl` / `Cmplt` |
| config | `Cfg` / `Conf` / `Confg` | connection | `Cnx` / `Conx` / `Cnnct` | container | `Con` / `Ctnr` / `Cntnr` |
| content | — / `Cont` / `Cntnt` | context | — / `Ctxt` / — | control | — / `Ctrl` / `Cntrl` |
| cookie | — / `Ckie` / `Cooky` | copy | `Cpy` / `Copy` / — | core | `Cor` / `Core` / `Heart` |
| count | `Cnt` / — / `Count` | cover | `Cvr` / `Covr` / `Cover` | cred | `Crd` / `Cred` / — |
| credential | `Crd` / `Crdn` / `Crdnt` | crypto | — / `Cryp` / — | css | `Css` / `Styl` / `Style` |
| cut | `Cut` / — / — | cuts | `Cut` / `Cuts` / — | dark | `Drk` / `Dark` / — |
| data | `Dat` / `Data` / — | database | `Dbs` / `Dtbs` / `Dbase` | date | `Dte` / `Date` / `Stamp` |
| dates | `Dte` / `Dtes` / `Dates` | day | `Day` / — / — | days | `Dys` / `Days` / — |
| decimal | `Dcm` / `Dcml` / `Decml` | declared | `Dcl` / `Decl` / `Dclrd` | deep | `Dep` / `Deep` / — |
| default | `Dft` / `Dflt` / `Dfalt` | definition | `Dfn` / `Defn` / `Defin` | defs | `Dfs` / `Defs` / — |
| degree | `Dgr` / `Degr` / `Degre` | degrees | `Deg` / `Degs` / `Degrs` | delete | `Del` / `Remv` / `Delet` |
| deny | `Dny` / `Deny` / — | depth | `Dpt` / `Dpth` / `Depth` | detail | `Det` / `Detl` / `Dtail` |
| detect | — / `Dtct` / — | development | `Dev` / `Dvlp` / — | device | `Dvc` / `Dvce` / `Devic` |
| dice | `Dce` / `Dice` / — | digit | — / `Digt` / `Digit` | directory | `Dir` / `Drct` / `Foldr` |
| dist | `Dst` / `Dist` / — | divider | `Div` / `Dvdr` / `Divdr` | document | `Doc` / `Docu` / `Dcmnt` |
| dom | `Dom` / — / — | done | `Dun` / `Done` / — | door | — / `Door` / — |
| dot | `Dot` / — / — | draw | `Drw` / `Draw` / — | drawn | — / `Drwn` / `Drawn` |
| driver | — / `Drvr` / — | drop | `Drp` / `Drop` / — | dsn | `Dsn` / — / — |
| dtd | `Dtd` / — / — | due | `Due` / — / — | ehlo | `Ehl` / `Ehlo` / — |
| email | `Eml` / `Mail` / `Email` | emu | `Emu` / `Emul` / `Emuls` | encoded | `Enc` / `Encd` / `Encdd` |
| encoder | — / `Encd` / `Encdr` | encoding | `Enc` / `Encd` / `Encod` | end | `End` / — / — |
| ends | `End` / `Ends` / — | entry | `Ntr` / `Entr` / `Entry` | error | `Err` / `Erro` / `Error` |
| escape | `Esc` / `Escp` / `Escap` | etag | `Ntt` / `Ntty` / `Entty` | every | `All` / `Evry` / `Every` |
| except | — / `Xcpt` / — | exception | `Exc` / `Excp` / `Excpt` | exif | — / `Exif` / — |
| existed | `Xst` / `Exst` / `Exstd` | exit | — / `Exit` / — | exponent | `Exp` / `Expn` / `Expnt` |
| extension | `Ext` / `Extn` / `Extns` | extra | `Xtr` / `Extr` / `Extra` | fact | `Fct` / `Fact` / — |
| fail | — / `Fail` / `Faild` | fake | `Fke` / `Fake` / — | far | `Far` / — / — |
| field | `Fld` / `Fild` / `Field` | fields | `Fld` / `Flds` / `Field` | file | `Fle` / `File` / `Fichr` |
| fill | `Fil` / `Fill` / `Fills` | filters | `Flt` / `Fltr` / `Fltrs` | find | `Fnd` / `Find` / — |
| first | `Fst` / `Frst` / `First` | flac | `Flc` / `Flac` / — | flag | `Flg` / `Flag` / — |
| flat | — / `Flat` / — | flaw | — / `Flaw` / — | flip | — / `Flip` / — |
| flow | `Flw` / `Flow` / — | flux | `Flx` / `Flux` / — | folder | `Fdr` / `Fldr` / `Foldr` |
| follows | — / `Fllw` / `Fllws` | font | `Fnt` / `Font` / — | for | `For` / — / — |
| form | `Frm` / `Form` / — | format | `Fmt` / `Frmt` / `Formt` | formats | — / — / `Frmts` |
| forwarded | `Fwd` / `Frwd` / `Forwd` | found | — / — / `Found` | fragment | `Frg` / `Frag` / `Frgmt` |
| frame | — / `Frme` / `Frame` | frames | — / `Frme` / `Frmes` | from | — / `From` / — |
| full | `Tot` / `Full` / — | funcs | `Fnc` / `Func` / `Funcs` | fwd | `Fwd` / — / — |
| gap | `Gap` / — / — | gaps | `Gap` / `Gaps` / — | get | `Get` / — / — |
| given | `Gvn` / `Givn` / `Given` | goal | `Gol` / `Goal` / — | going | `Gon` / `Goes` / `Going` |
| grid | `Grd` / `Grid` / — | group | `Grp` / `Grup` / `Group` | half | `Hlf` / `Half` / — |
| hand | — / `Hand` / — | handle | `Hnd` / `Hndl` / `Handl` | handler | `Hdl` / `Hndr` / `Hndlr` |
| hash | `Hsh` / `Hash` / `Digst` | head | `Hed` / `Head` / — | header | — / `Hedr` / `Headr` |
| headers | — / `Hdrs` / `Hedrs` | height | `Hei` / `Hght` / `Heigt` | held | `Hel` / `Held` / — |
| hertz | — / `Hrtz` / `Hertz` | hexadecimal | `Hex` / `Hxdc` / `Hxdcm` | hide | `Hid` / `Hide` / — |
| high | `Hgh` / `High` / — | hits | `Hts` / `Hits` / — | hmac | — / `Hmac` / — |
| hold | — / `Hold` / `Holds` | host | — / `Host` / — | html | `Htm` / `Html` / — |
| ics | `Ics` / — / — | id3 | `Id3` / — / — | identity | `Idt` / `Idnt` / `Ident` |
| image | `Img` / `Imge` / `Image` | indented | — / `Indt` / `Indnt` | info | `Inf` / `Info` / — |
| information | `Inf` / `Info` / `Infrm` | ini | `Ini` / — / — | inner | `Inr` / `Innr` / `Inner` |
| input | `Inp` / `Inpt` / `Input` | inside | `Isd` / `Insd` / `Insid` | instance | `Ins` / `Inst` / `Instn` |
| int | `Int` / — / — | issuer | `Isu` / `Issr` / `Issur` | item | `Itm` / `Item` / — |
| join | `Jnt` / `Join` / `Joint` | joker | — / `Jokr` / `Joker` | json | — / `Json` / — |
| jwk | `Jwk` / — / — | jwt | `Jwt` / — / — | keep | — / `Keep` / — |
| keeps | `Kps` / `Keep` / `Keeps` | kept | `Kpt` / `Kept` / — | key | `Key` / `Clef` / `Clave` |
| keyed | — / `Keyd` / `Keyed` | keys | `Kys` / `Keys` / — | kid | `Kid` / — / — |
| kids | `Kds` / `Kids` / — | kind | `Knd` / `Kind` / — | label | `Lbl` / `Labl` / `Label` |
| lack | — / `Lack` / `Lacks` | language | `Lng` / `Lang` / `Langg` | last | `Fin` / `Last` / — |
| layer | — / `Layr` / `Layer` | lead | — / `Lead` / — | leading | — / `Lead` / `Leadg` |
| left | `Lft` / `Left` / — | letter | — / `Lttr` / — | level | `Lvl` / `Levl` / `Level` |
| levels | — / `Lvls` / — | limit | `Lmt` / `Limt` / `Limit` | limiter | — / `Lmtr` / `Limtr` |
| line | `Lne` / `Line` / `Ligne` | lines | `Lns` / `Lnes` / `Lines` | link | `Lnk` / `Link` / `Nlace` |
| list | `Lst` / `List` / `Lstng` | lit | `Lit` / — / — | lite | `Lte` / `Lite` / — |
| literal | — / `Ltrl` / `Litrl` | load | `Lod` / `Load` / — | lock | `Lck` / `Lock` / — |
| log | `Log` / `Jrnl` / `Jrnal` | login | `Lgn` / `Logn` / `Login` | logs | `Jrn` / `Logs` / `Journ` |
| lookups | — / `Lkps` / `Lkups` | loss | — / `Loss` / — | lost | — / `Lost` / — |
| lower | `Low` / `Lowr` / `Lower` | made | — / `Made` / — | mail | `Eml` / `Mail` / `Email` |
| main | `Mai` / `Main` / — | make | `Mke` / `Make` / `Build` | manager | `Mgr` / `Mngr` / `Mangr` |
| map | `Map` / — / — | mapping | `Map` / `Maps` / `Mppng` | mark | `Mrk` / `Mark` / — |
| mask | `Msk` / `Mask` / — | masking | `Msk` / `Mask` / `Mskng` | master | `Mst` / `Mstr` / `Mastr` |
| match | — / `Matx` / `Match` | matched | `Mtx` / `Matx` / `Mtchd` | matches | `Mat` / `Matx` / `Matxs` |
| max | `Max` / — / — | mdays | — / `Mday` / `Mdays` | measure | `Msr` / `Mesr` / `Measr` |
| merge | `Mrg` / `Merg` / `Merge` | merged | `Mrg` / `Mrgd` / `Mergd` | meta | `Mta` / `Meta` / — |
| method | `Mth` / `Mthd` / `Methd` | mime | `Mim` / `Mime` / — | miss | `Mis` / `Miss` / — |
| missing | — / `Mssn` / `Mssng` | mixed | `Mix` / `Mixd` / `Mixed` | mode | `Mod` / `Mode` / `Genre` |
| model | `Mdl` / `Modl` / `Model` | mods | `Mds` / `Mods` / — | month | — / `Mnth` / `Month` |
| more | `Mor` / `More` / — | mount | — / `Mont` / `Mount` | move | `Mve` / `Move` / — |
| name | `Nme` / `Name` / `Title` | named | `Nmd` / `Namd` / `Named` | names | `Nms` / `Nmes` / `Names` |
| namespace | — / `Nmsp` / — | nat | `Nat` / — / — | near | — / `Near` / — |
| need | `Ned` / `Need` / — | negated | `Neg` / `Negt` / `Negtd` | nest | `Nst` / `Nest` / — |
| net | `Net` / `Netw` / `Ntwrk` | new | `New` / `Nouv` / `Nuevo` | next | `Nxt` / `Next` / — |
| node | `Nde` / `Node` / — | nonce | `Nnc` / `Nonc` / `Nonce` | none | `Non` / `None` / — |
| note | `Nte` / `Note` / — | now | `Now` / — / — | null | `Nul` / `Null` / — |
| number | `Num` / `Numb` / `Numbr` | numbers | `Nmb` / `Nums` / `Nmbrs` | nyu | `Nyu` / — / — |
| off | `Off` / — / — | ogg | `Ogg` / — / — | okay | `Oky` / `Okay` / `Allrt` |
| old | `Old` / — / — | once | `One` / `Once` / — | one | `One` / — / — |
| open | `Opn` / `Open` / — | opt | `Opt` / `Optn` / — | options | `Opt` / `Opts` / `Optns` |
| order | `Ord` / `Ordr` / `Order` | ordered | — / `Ordr` / `Ordrd` | otp | `Otp` / — / — |
| out | `Out` / — / — | outcome | `Out` / `Outc` / `Outcm` | over | `Ovr` / `Over` / — |
| own | `Own` / — / — | owner | `Own` / `Ownr` / `Owner` | pack | `Pck` / `Pack` / — |
| pad | `Pad` / — / — | page | `Pge` / `Page` / — | pair | — / `Pair` / — |
| pairs | — / `Pair` / `Pairs` | para | `Prg` / `Prgr` / `Pargr` | paragraph | `Prg` / `Prgr` / `Pargr` |
| parameter | `Prm` / `Prmt` / `Prmtr` | parent | `Prn` / `Prnt` / `Parnt` | parser | `Prs` / `Prsr` / `Parsr` |
| part | `Par` / `Part` / `Parte` | parts | `Prt` / `Prts` / `Parts` | password | `Pwd` / `Pass` / `Psswd` |
| patched | — / `Patx` / `Patch` | path | `Pth` / `Path` / `Trail` | pattern | — / `Ptrn` / `Pttrn` |
| payload | `Pld` / `Pyld` / `Payld` | pdf | `Pdf` / — / — | peer | — / `Peer` / — |
| pem | `Pem` / — / — | penalty | — / `Pnlt` / `Pnlty` | pending | — / `Pend` / `Pndng` |
| php | `Php` / — / — | picture | `Pic` / `Pict` / `Pictr` | piece | — / `Piec` / `Piece` |
| pipe | `Pip` / `Pipe` / — | place | `Plc` / `Plce` / `Place` | plain | `Pln` / `Plan` / `Plain` |
| plug | `Plg` / `Plug` / `Plugn` | plurals | `Plr` / `Plrl` / `Plrls` | plus | — / `Plus` / — |
| pointer | `Pnt` / `Pntr` / `Pontr` | points | — / `Pnts` / — | port | — / `Port` / — |
| precision | — / `Prec` / `Preci` | preset | `Rps` / `Pset` / `Prset` | previous | — / `Prev` / `Prevs` |
| prim | — / `Prim` / `Prmry` | printer | — / `Echo` / `Print` | private | `Prv` / `Priv` / `Privt` |
| process | `Prc` / `Proc` / `Prcss` | project | `Prj` / `Proj` / `Prjct` | property | `Prp` / `Prop` / `Prprt` |
| proxy | — / `Prxy` / `Proxy` | pub | `Pub` / — / — | public | `Pub` / `Publ` / `Publi` |
| quality | `Qlt` / `Qlty` / `Qalty` | query | `Qry` / `Quer` / `Query` | quote | `Qte` / `Quot` / `Quote` |
| quoted | `Qtd` / `Qotd` / `Quotd` | random | — / `Rndm` / — | range | `Rng` / `Rnge` / `Range` |
| rank | `Rnk` / `Rank` / — | rate | — / `Rate` / — | raw | `Raw` / `Brut` / `Crude` |
| read | — / `Read` / — | reads | — / `Read` / `Reads` | ready | `Rdy` / `Redy` / `Ready` |
| real | — / `Real` / — | recipient | `Rcp` / `Rcpn` / `Rcpnt` | records | `Rcs` / `Recs` / `Recds` |
| red | `Red` / — / — | reference | `Ref` / `Refr` / `Rfrnc` | refuse | `Ren` / `Rfus` / `Refus` |
| regexp | `Rgx` / `Rgxp` / — | registry | `Reg` / `Regr` / `Rgstr` | relation | `Rel` / `Rltn` / `Relat` |
| remaining | `Rmn` / `Remn` / `Remng` | render | `Rdr` / `Rndr` / `Rendr` | repeat | `Rpt` / `Rpet` / `Repet` |
| repeats | — / — / `Repet` | reply | `Rpl` / `Rply` / `Reply` | request | `Rqt` / `Reqt` / `Reqst` |
| respond | — / `Rspd` / `Rspnd` | response | `Rsp` / `Resp` / `Rspns` | rest | — / `Rest` / — |
| result | `Res` / `Rslt` / `Reslt` | results | `Res` / `Rslt` / `Rslts` | retry | — / `Rtry` / `Retry` |
| return | `Rtn` / `Rtrn` / `Retrn` | review | — / `Rivu` / `Rview` | riff | `Rif` / `Riff` / — |
| rights | — / `Rght` / — | role | `Rle` / `Role` / — | room | — / `Room` / — |
| root | — / `Root` / — | rout | `Rou` / `Rout` / — | route | `Rte` / `Rout` / `Route` |
| row | `Row` / `Ntry` / `Entry` | rows | — / `Rows` / — | rule | `Rul` / `Rule` / — |
| run | `Run` / — / — | runs | `Rns` / `Runs` / — | safe | `Saf` / `Safe` / — |
| salt | `Slt` / `Salt` / — | same | — / `Same` / — | sample | — / `Smpl` / `Sampl` |
| save | `Sve` / `Save` / — | scale | — / `Scal` / `Scale` | scan | `Scn` / `Scan` / — |
| seal | `Sil` / `Seal` / — | seconds | `Sec` / `Secs` / `Scnds` | secret | — / `Secr` / `Secrt` |
| section | `Sct` / `Sctn` / `Sectn` | seen | `See` / `Seen` / — | segment | — / `Sgmt` / `Segmt` |
| select | `Slc` / `Slct` / `Selct` | sender | — / `Sndr` / — | sends | — / `Send` / `Sends` |
| sent | `Snt` / `Sent` / — | separator | `Sep` / `Sepr` / `Seprt` | sequence | — / `Sqnc` / — |
| server | `Srv` / `Srvr` / `Servr` | session | `Ses` / `Sess` / `Sessn` | set | `Set` / — / — |
| setup | `Stp` / `Setp` / `Setup` | sha | `Sha` / — / — | shadow | `Shd` / `Shdw` / `Shadw` |
| shape | `Shp` / `Shap` / `Shape` | shared | — / `Shrd` / — | sheet | — / `Tabs` / `Sheet` |
| short | `Shr` / `Shrt` / `Short` | show | `Shw` / `Show` / `Revil` | shown | `Shw` / `Shwn` / `Shown` |
| shutdown | — / `Shut` / `Shutd` | side | `Sde` / `Side` / — | sign | `Sgn` / `Sign` / — |
| simple | `Eas` / `Simp` / `Simpl` | since | `Snc` / `Sinc` / `Since` | single | — / `Sngl` / — |
| site | `Ste` / `Site` / — | size | `Sze` / `Size` / `Bytes` | skip | `Skp` / `Skip` / `Skips` |
| slash | — / `Slsh` / `Slash` | slug | `Slg` / `Slug` / — | smtp | — / `Smtp` / — |
| socket | `Sck` / `Sckt` / `Sockt` | sort | `Srt` / `Sort` / — | span | `Spn` / `Span` / `Range` |
| spans | `Spn` / `Spns` / `Spans` | spec | `Spc` / `Spec` / — | spin | — / `Spin` / — |
| split | `Spl` / `Splt` / `Split` | spot | `Spt` / `Spot` / — | sql | `Sql` / — / — |
| stamp | — / `Stmp` / `Stamp` | start | — / `Strt` / `Start` | stat | `Sta` / `Stat` / — |
| statement | `Stm` / `Stmt` / `Sttmt` | status | `Stt` / `Stts` / `Stats` | step | — / `Step` / — |
| store | `Sto` / `Stre` / `Store` | stream | `Srm` / `Strm` / `Strem` | string | — / `Strn` / — |
| style | `Stl` / `Styl` / `Style` | sub | `Sub` / — / — | subnet | — / `Snet` / — |
| success | — / `Succ` / `Succs` | sum | `Sum` / `Sums` / `Addit` | summary | — / `Smry` / `Smmry` |
| swap | `Swp` / `Swap` / — | systems | `Sys` / `Syst` / `Systm` | table | `Tbl` / `Tble` / `Table` |
| tables | — / `Tbls` / — | tag | `Tag` / — / — | tags | `Tag` / `Tags` / — |
| tail | — / `Tail` / — | tall | — / `Tall` / — | tallies | — / — / `Talli` |
| tar | `Tar` / — / — | target | `Trg` / `Trgt` / `Targt` | task | `Tsk` / `Task` / — |
| tells | `Tll` / `Tlls` / `Tells` | temp | `Tmp` / `Temp` / — | template | `Tpl` / `Tmpl` / `Tmplt` |
| text | `Txt` / `Text` / `Strng` | third | — / `Thrd` / `Third` | this | — / `This` / — |
| tick | `Tck` / `Tick` / `Tickt` | tidy | `Tdy` / `Tidy` / — | till | `Ntl` / `Till` / `Until` |
| time | `Tme` / `Time` / — | title | `Tit` / `Titl` / `Title` | tld | `Tld` / — / — |
| tls | `Tls` / — / — | toc | `Toc` / — / — | token | `Tkn` / `Tokn` / `Token` |
| told | `Say` / `Told` / — | tool | `Utl` / `Tool` / — | top | `Top` / — / — |
| tops | `Top` / `Tops` / — | track | — / `Trck` / `Track` | transaction | — / `Trns` / `Trnsc` |
| tree | `Fam` / `Tree` / — | trig | — / `Trig` / — | trigger | — / `Trig` / `Trigr` |
| trim | — / `Trim` / — | trimmed | `Trm` / `Trim` / `Trimd` | trusted | — / `Trst` / — |
| turn | — / `Turn` / — | two | `Two` / — / — | type | `Typ` / `Type` / — |
| update | `Upd` / `Updt` / `Updat` | uri | `Uri` / — / — | url | `Url` / — / — |
| use | `Use` / `Uses` / `Using` | used | `Usd` / `Used` / — | user | `Usr` / `User` / `Membr` |
| utf8 | `Utf` / `Utf8` / — | value | `Val` / `Valu` / `Value` | values | `Vls` / `Vals` / `Valus` |
| variable | `Var` / `Vari` / `Varia` | variant | — / `Vrnt` / `Varnt` | vars | — / `Vars` / — |
| version | `Vrs` / `Vers` / `Versn` | view | — / `View` / — | visitor | `Vst` / `Vstr` / `Vistr` |
| void | `Voi` / `Void` / — | wait | `Wai` / `Wait` / — | walk | `Wlk` / `Walk` / — |
| want | — / `Want` / `Wants` | warn | `Wrn` / `Warn` / `Warng` | was | `Was` / — / — |
| wav | `Wav` / — / — | week | `Wek` / `Week` / — | what | — / `What` / — |
| where | `Whr` / `Whre` / `Where` | whole | `Whl` / `Whol` / `Whole` | why | `Why` / — / — |
| wide | `Wid` / `Wide` / — | with | `Wth` / `With` / — | word | `Wrd` / `Word` / — |
| writer | `Wrt` / `Wrtr` / `Writr` | written | — / — / `Wrttn` | www | `Www` / — / — |
| xml | `Xml` / — / — | year | `Yer` / `Year` / — | yes | `Yes` / — / — |
| yml | `Yml` / `Yaml` / — | zip | `Zip` / — / — | zone | `Zne` / `Zone` / — |

**Written whole**: every word not listed above.

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
- **The rename is applied on a branch of its own**, `naming-0C06`, area by area, and merged whole once the full suite is green on it, never halfway. The rules (this file) and the audit in report mode go to `master` first: they break nothing.

The locals were named on 2026-11-14 (the Locals · final tab of the review). Next: their rename, then the parameters.
