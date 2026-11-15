<?php

// Tools\StrKit : pluralize, singlrize, stripTags

require __DIR__.'/../lib/harness.php';

use nyu\Tools\StrKit;

checkIdentical('pluralize y -> ies',       StrKit::makePluralWord('city'), 'cities');
checkIdentical('pluralize s -> ses',       StrKit::makePluralWord('bus'), 'buses');
checkIdentical('pluralize default',        StrKit::makePluralWord('show'), 'shows');

checkIdentical('singlrize ies -> y',       StrKit::singularizeTxt('cities'), 'city');
checkIdentical('singlrize s dropped',      StrKit::singularizeTxt('shows'), 'show');
checkIdentical('singlrize unchanged',      StrKit::singularizeTxt('sheep'), 'sheep');

checkIdentical('stripTags plain',          StrKit::wipeTagsString('plain text'), 'plain text');
checkIdentical('stripTags simple tags',    StrKit::wipeTagsString('<b>bold</b> and <i>italic</i>'), 'bold and italic');
checkIdentical('stripTags attributes',     StrKit::wipeTagsString('<a href="x>y">link</a>'), 'y">link');
checkIdentical('stripTags nested brackets', StrKit::wipeTagsString('<<double>>'), '');
checkIdentical('stripTags deeper nesting', StrKit::wipeTagsString('<<<a>>>b'), 'b');
checkIdentical('stripTags unclosed tag',   StrKit::wipeTagsString('unclosed <tag'), 'unclosed ');
checkIdentical('stripTags lone >',         StrKit::wipeTagsString('5 > 3'), '5 > 3');
checkIdentical('stripTags comparison',     StrKit::wipeTagsString('a < b and c > d'), 'a  d');
checkIdentical('stripTags comment',        StrKit::wipeTagsString('a<!-- c > d -->b'), 'ab');
checkIdentical('stripTags empty comment',  StrKit::wipeTagsString('a<!-->b'), 'ab');
checkIdentical('stripTags php block',      StrKit::wipeTagsString('<?php echo 1 > 0; ?>after'), 'after');
checkIdentical('stripTags doctype',        StrKit::wipeTagsString('<!DOCTYPE x [ <!ENTITY y z> ]>t'), 't');
checkIdentical('stripTags NUL byte',       StrKit::wipeTagsString("nul\0byte"), 'nulbyte');
checkIdentical('stripTags empty',          StrKit::wipeTagsString(''), '');
checkIdentical('stripTags accents kept',   StrKit::wipeTagsString('<p>é à ü</p>'), 'é à ü');

finishChecking();
