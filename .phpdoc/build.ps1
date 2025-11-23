# Builds the code reference of nyuRuns into temp/docs/reference.
# Run from the project root: pwsh .phpdoc/build.ps1
# phpDocumentor only renders the Twig files of .phpdoc/template: the banner and the fonts are copied here.

$phpExt = Join-Path (Split-Path (Get-Command php).Source) 'ext'
$outDir = 'temp/docs/reference'

php -d extension_dir=$phpExt -d extension=mbstring -d memory_limit=1G temp/tools/phpDocumentor.phar run
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

New-Item -ItemType Directory -Force "$outDir/fonts" | Out-Null
Copy-Item .phpdoc/template/images/banner.png "$outDir/images/" -Force
Copy-Item .phpdoc/template/fonts/* "$outDir/fonts/" -Force
