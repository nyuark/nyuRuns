# Conventions

The naming and coding conventions of nyuRuns. The `audit` group of the tests checks them (`php test/run.php audit`).

## Naming

All names are `camelCase` with a fixed length, except classes, which are `PascalCase` with no limit, and constants. This file gives the lengths; the grammar of every name is in `NAMERULES.md`: slots, verbs, kind terms, glossary.

| Element                                       | Length | Example              |
|-----------------------------------------------|--------|----------------------|
| Method                                        | 14     | `overSetupLoads()`   |
| Method parameter                              | 12     | `$addedEntries`      |
| Class property                                | 10     | `$accessConf`        |
| Local variable inside a method                | 8      | `$fileName`          |
| Loop variable (`one` + item, or `nth` + item) | 6      | `$oneRow`, `$nthRow` |
| Class constant                                | 9      | `KEYS_LIST`          |
| Action method (runs/*/Actions)                | free   | `login()`            |

- A constant is in upper case, `XXXX_YYYY`, one underscore after the 4th character.
- How a name is built (its slots, the verbs, the kind terms, the short forms of the glossary) is in `NAMERULES.md`, sections 1 to 5.

Exempt from the length rules:
- Action methods in `runs/*/Actions.php` (`index`, `view`, `login`, ...): the method name is the URL segment; the audit leaves their length free.
- PSR-3 level methods of `Tools\Logger` (`error`, `warning`, `notice`, `info`, `debug`), and its level constants (`LEVL_ERROR`...).
- The catch variable `$e`.
- The loop variables of 3 letters listed in test/audit/looplabels.txt, kept for now.
- PHP magic methods, the methods a PHP interface imposes (`jsonSerialize`, `getIterator`, `offsetGet`, `count`...) and those of a stream wrapper.

Variables:
- Format names in Variable are 7 characters (OutText, ShrtDte, EmlAddr), so that each `formats…()` method is 14 (`formatsOutText()`).

## Code
- Type declarations are required on parameters and returns, written capitalised (`String`, `Array`, `Bool`, `Int`, `Mixed`, `Void`).
- Multi-type returns use precise unions (`Array|Bool`, `?String`); `Mixed` only where the value can really be anything.
- Parameters defaulting to null are explicitly nullable (`?String $x=null`).
- Do not add `declare(strict_types=1)`.
- Docblocks are required for all public methods (use `@param`, `@return`).
- Custom exceptions extend `RuntimeException`.
