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

checkIdentical('stripTags plain',          StrKit::trimTagsString('plain text'), 'plain text');
checkIdentical('stripTags simple tags',    StrKit::trimTagsString('<b>bold</b> and <i>italic</i>'), 'bold and italic');
checkIdentical('stripTags attributes',     StrKit::trimTagsString('<a href="x>y">link</a>'), 'y">link');
checkIdentical('stripTags nested brackets', StrKit::trimTagsString('<<double>>'), '');
checkIdentical('stripTags deeper nesting', StrKit::trimTagsString('<<<a>>>b'), 'b');
checkIdentical('stripTags unclosed tag',   StrKit::trimTagsString('unclosed <tag'), 'unclosed ');
checkIdentical('stripTags lone >',         StrKit::trimTagsString('5 > 3'), '5 > 3');
checkIdentical('stripTags comparison',     StrKit::trimTagsString('a < b and c > d'), 'a  d');
checkIdentical('stripTags comment',        StrKit::trimTagsString('a<!-- c > d -->b'), 'ab');
checkIdentical('stripTags empty comment',  StrKit::trimTagsString('a<!-->b'), 'ab');
checkIdentical('stripTags php block',      StrKit::trimTagsString('<?php echo 1 > 0; ?>after'), 'after');
checkIdentical('stripTags doctype',        StrKit::trimTagsString('<!DOCTYPE x [ <!ENTITY y z> ]>t'), 't');
checkIdentical('stripTags NUL byte',       StrKit::trimTagsString("nul\0byte"), 'nulbyte');
checkIdentical('stripTags empty',          StrKit::trimTagsString(''), '');
checkIdentical('stripTags accents kept',   StrKit::trimTagsString('<p>é à ü</p>'), 'é à ü');

finishChecking();
