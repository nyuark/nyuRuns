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

**Question verbs** (a Bool): `is`, `has`. The others found (`holds`, `allows`) are rephrased.

**Domain verbs**, kept as they are: `sign`, `seal`, `delete` (SQL), `mark`.
`cook` and `fake` are not verbs.

## 4. Kind terms

| Term | Holds | Replaces |
|---|---|---|
| `Text` | any text | Txt |
| `Secs`, `Mins` | a length of time | Sec |
| the class name | an object named by its class | Reg |

## 5. Glossary

**Standard abbreviations** (the fixed list): `Url`, `Html`. A sentence after it, with `Nope`.

**Exceptions decided by the project manager** (2026-11-12): `Obj` (object) and `Col` (column) keep 3 letters. Because of them: calendar is never `Cal`.

| Word | Form | Word | Form | Word | Form |
|---|---|---|---|---|---|
| account | `Accnt` | factor | `Factr` | project | `Proj` |
| character(s) | `Char`, `Chars` | method | `Mthd` | | |

## 6. After

| Word | Form |
|---|---|
| never | `Read` |
