# Builds the documentation site of nyuRuns into docs/site (not tracked): the code reference in
# docs/site/reference, made by phpDocumentor, then the pages of the documents (.phpdoc/site/build.php).
# Run from the project root: pwsh .phpdoc/build.ps1
# phpDocumentor only renders the Twig files of .phpdoc/template: the logo and the fonts are copied here.

$outDir = 'docs/site/reference'

# The php of this terminal; mbstring is loaded from its own ext folder when its php.ini does not load it
$phpArg = @('-d', 'memory_limit=1G')
if (-not (php -m | Select-String -Quiet '^mbstring$')) {
    $phpExt = Join-Path (Split-Path (Get-Command php).Source) 'ext'
    $phpArg += @('-d', "extension_dir=$phpExt", '-d', 'extension=mbstring')
}
php @phpArg temp/tools/phpDocumentor.phar run
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

New-Item -ItemType Directory -Force "$outDir/fonts" | Out-Null
Copy-Item .phpdoc/template/images/logo.png "$outDir/images/" -Force
Copy-Item .phpdoc/template/fonts/* "$outDir/fonts/" -Force

# The pages of the documents, around the reference
php @phpArg .phpdoc/site/build.php
exit $LASTEXITCODE
