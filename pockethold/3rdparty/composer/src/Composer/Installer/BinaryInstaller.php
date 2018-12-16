<?php











namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Util\Silencer;








class BinaryInstaller
{
protected $binDir;
protected $binCompat;
protected $io;
protected $filesystem;







public function __construct(IOInterface $io, $binDir, $binCompat, Filesystem $filesystem = null)
{
$this->binDir = $binDir;
$this->binCompat = $binCompat;
$this->io = $io;
$this->filesystem = $filesystem ?: new Filesystem();
}

public function installBinaries(PackageInterface $package, $installPath, $warnOnOverwrite = true)
{
$binaries = $this->getBinaries($package);
if (!$binaries) {
return;
}
foreach ($binaries as $bin) {
$binPath = $installPath.'/'.$bin;
if (!file_exists($binPath)) {
$this->io->writeError('    <warning>Skipped installation of bin '.$bin.' for package '.$package->getName().': file not found in package</warning>');
continue;
}


 
 
 
 $binPath = realpath($binPath);

$this->initializeBinDir();
$link = $this->binDir.'/'.basename($bin);
if (file_exists($link)) {
if (is_link($link)) {

 
 
 Silencer::call('chmod', $link, 0777 & ~umask());
}
if ($warnOnOverwrite) {
$this->io->writeError('    Skipped installation of bin '.$bin.' for package '.$package->getName().': name conflicts with an existing file');
}
continue;
}

if ($this->binCompat === "auto") {
if (Platform::isWindows()) {
$this->installFullBinaries($binPath, $link, $bin, $package);
} else {
$this->installSymlinkBinaries($binPath, $link);
}
} elseif ($this->binCompat === "full") {
$this->installFullBinaries($binPath, $link, $bin, $package);
}
Silencer::call('chmod', $link, 0777 & ~umask());
}
}

public function removeBinaries(PackageInterface $package)
{
$this->initializeBinDir();

$binaries = $this->getBinaries($package);
if (!$binaries) {
return;
}
foreach ($binaries as $bin) {
$link = $this->binDir.'/'.basename($bin);
if (is_link($link) || file_exists($link)) {
$this->filesystem->unlink($link);
}
if (file_exists($link.'.bat')) {
$this->filesystem->unlink($link.'.bat');
}
}


 if (is_dir($this->binDir) && $this->filesystem->isDirEmpty($this->binDir)) {
Silencer::call('rmdir', $this->binDir);
}
}

public static function determineBinaryCaller($bin)
{
if ('.bat' === substr($bin, -4) || '.exe' === substr($bin, -4)) {
return 'call';
}

$handle = fopen($bin, 'r');
$line = fgets($handle);
fclose($handle);
if (preg_match('{^#!/(?:usr/bin/env )?(?:[^/]+/)*(.+)$}m', $line, $match)) {
return trim($match[1]);
}

return 'php';
}

protected function getBinaries(PackageInterface $package)
{
return $package->getBinaries();
}

protected function installFullBinaries($binPath, $link, $bin, PackageInterface $package)
{

 if ('.bat' !== substr($binPath, -4)) {
$this->installUnixyProxyBinaries($binPath, $link);
@chmod($link, 0777 & ~umask());
$link .= '.bat';
if (file_exists($link)) {
$this->io->writeError('    Skipped installation of bin '.$bin.'.bat proxy for package '.$package->getName().': a .bat proxy was already installed');
}
}
if (!file_exists($link)) {
file_put_contents($link, $this->generateWindowsProxyCode($binPath, $link));
}
}

protected function installSymlinkBinaries($binPath, $link)
{
if (!$this->filesystem->relativeSymlink($binPath, $link)) {
$this->installUnixyProxyBinaries($binPath, $link);
}
}

protected function installUnixyProxyBinaries($binPath, $link)
{
file_put_contents($link, $this->generateUnixyProxyCode($binPath, $link));
}

protected function initializeBinDir()
{
$this->filesystem->ensureDirectoryExists($this->binDir);
$this->binDir = realpath($this->binDir);
}

protected function generateWindowsProxyCode($bin, $link)
{
$binPath = $this->filesystem->findShortestPath($link, $bin);
$caller = self::determineBinaryCaller($bin);

return "@ECHO OFF\r\n".
"setlocal DISABLEDELAYEDEXPANSION\r\n".
"SET BIN_TARGET=%~dp0/".trim(ProcessExecutor::escape($binPath), '"\'')."\r\n".
"{$caller} \"%BIN_TARGET%\" %*\r\n";
}

protected function generateUnixyProxyCode($bin, $link)
{
$binPath = $this->filesystem->findShortestPath($link, $bin);

$binDir = ProcessExecutor::escape(dirname($binPath));
$binFile = basename($binPath);

$proxyCode = <<<PROXY
#!/usr/bin/env sh

dir=\$(cd "\${0%[/\\\\]*}" > /dev/null; cd $binDir && pwd)

if [ -d /proc/cygdrive ] && [[ \$(which php) == \$(readlink -n /proc/cygdrive)/* ]]; then
   # We are in Cgywin using Windows php, so the path must be translated
   dir=\$(cygpath -m "\$dir");
fi

"\${dir}/$binFile" "\$@"

PROXY;

return $proxyCode;
}
}