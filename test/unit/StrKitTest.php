<?php

// Tools\StrKit : pluralize, singlrize, stripTags

require __DIR__.'/../lib/harness.php';

use nyu\Tools\StrKit;

checkSame('pluralize y -> ies',       StrKit::pluralize('city'), 'cities');
checkSame('pluralize s -> ses',       StrKit::pluralize('bus'), 'buses');
checkSame('pluralize default',        StrKit::pluralize('show'), 'shows');

checkSame('singlrize ies -> y',       StrKit::singlrize('cities'), 'city');
checkSame('singlrize s dropped',      StrKit::singlrize('shows'), 'show');
checkSame('singlrize unchanged',      StrKit::singlrize('sheep'), 'sheep');

checkSame('stripTags plain',          StrKit::stripTags('plain text'), 'plain text');
checkSame('stripTags simple tags',    StrKit::stripTags('<b>bold</b> and <i>italic</i>'), 'bold and italic');
checkSame('stripTags attributes',     StrKit::stripTags('<a href="x>y">link</a>'), 'y">link');
checkSame('stripTags nested brackets', StrKit::stripTags('<<double>>'), '');
checkSame('stripTags deeper nesting', StrKit::stripTags('<<<a>>>b'), 'b');
checkSame('stripTags unclosed tag',   StrKit::stripTags('unclosed <tag'), 'unclosed ');
checkSame('stripTags lone >',         StrKit::stripTags('5 > 3'), '5 > 3');
checkSame('stripTags comparison',     StrKit::stripTags('a < b and c > d'), 'a  d');
checkSame('stripTags comment',        StrKit::stripTags('a<!-- c > d -->b'), 'ab');
checkSame('stripTags empty comment',  StrKit::stripTags('a<!-->b'), 'ab');
checkSame('stripTags php block',      StrKit::stripTags('<?php echo 1 > 0; ?>after'), 'after');
checkSame('stripTags doctype',        StrKit::stripTags('<!DOCTYPE x [ <!ENTITY y z> ]>t'), 't');
checkSame('stripTags NUL byte',       StrKit::stripTags("nul\0byte"), 'nulbyte');
checkSame('stripTags empty',          StrKit::stripTags(''), '');
checkSame('stripTags accents kept',   StrKit::stripTags('<p>é à ü</p>'), 'é à ü');

endChecks();
