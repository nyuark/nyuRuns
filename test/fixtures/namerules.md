# Naming rules (fixture)

A small file shaped as NAMERULES.md, read by test/unit/NameRulesTest.php.

## 2. Grammar by kind

| Kind | Length |
|---|---|
| Method | 14 |

## 3. Verbs

Some text before the table, with a `word` in backticks that is no verb.

| Verb | Meaning | Replaces |
|---|---|---|
| `get` | give a value | retrieve, `fetch` |
| `read` | read from a source | |
| `make` | make something new | build |

**Question verbs** (a Bool): `is`, `has`, `was` (a state before the action). The others found (`holds`, `allows`) are rephrased.

**Domain verbs**, kept as they are: `sign`, `seal`, `delete` (SQL), `mark`.
`cook` and `fake` are not verbs.

## 4. Kind terms

| Term | Holds | Replaces |
|---|---|---|
| `Text`, `Txt`, `Strng` | any text | String |
| `Vals`, `Val` | the value or values of one thing | Values |
| `Secs`, `Mins` | a length of time | Sec |
| the class name | an object named by its class | Reg |

## 5. Glossary

**Standard abbreviations** (the fixed list): `Bom`, `Db`, `Id`, `Id3`, `Id3v1`, `Mp3`, `Url`, `Html`. A sentence after it, with `Nope`.

**Exceptions decided by the project manager** (2026-11-12, kept): `Obj`, `Col`, as ordinary writings now. Calendar is never `Cal`.

| Word | Writings (3 / 4 / 5) | Word | Writings (3 / 4 / 5) | Word | Writings (3 / 4 / 5) |
|---|---|---|---|---|---|
| account | — / `Acct` / `Accnt` | column | `Col` / `Clmn` / `Colmn` | form | `Frm` / `Form` / — |
| level | `Lvl` / `Levl` / `Level` | method | `Mth` / `Mthd` / `Methd` | regexp | `Rgx` / `Rgxp` / — |
| row | `Row` / `Ntry` / `Entry` | tag | `Tag` / — / — | | |

## 6. After

| Word | Form |
|---|---|
| never | `Read` |
