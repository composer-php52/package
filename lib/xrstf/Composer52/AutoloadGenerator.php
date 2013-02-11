<?php
/*
 * Copyright (c) 2013, Christoph Mewes, http://www.xrstf.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 *
 * --------------------------------------------------------------------------
 *
 * 99% of this is copied as-is from the original Composer source code and is
 * released under MIT license as well. Copyright goes to:
 *
 * - Igor Wiedler <igor@wiedler.ch>
 * - Jordi Boggiano <j.boggiano@seld.be>
 */

namespace xrstf\Composer52;

use Composer\Autoload\AutoloadGenerator as BaseGenerator;
use Composer\Autoload\ClassMapGenerator;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;

class AutoloadGenerator extends BaseGenerator {
	public function dump(Config $config, RepositoryInterface $localRepo, PackageInterface $mainPackage, InstallationManager $installationManager, $targetDir, $scanPsr0Packages = false, $suffix = '') {
		$filesystem = new Filesystem();
		$filesystem->ensureDirectoryExists($config->get('vendor-dir'));

		$vendorPath = strtr(realpath($config->get('vendor-dir')), '\\', '/');
		$targetDir  = $vendorPath.'/'.$targetDir;
		$filesystem->ensureDirectoryExists($targetDir);

		$relVendorPath             = $filesystem->findShortestPath(getcwd(), $vendorPath, true);
		$vendorPathCode            = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
		$vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

		$appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, getcwd(), true);
		$appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

		// add 5.2 compat
		$vendorPathCode            = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);
		$vendorPathToTargetDirCode = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathToTargetDirCode);
		$namespacesFile            = <<<EOF
<?php

// autoload_namespaces_52.php generated by xrstf/composer-php52

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(

EOF;

		$packageMap = $this->buildPackageMap($installationManager, $mainPackage, $localRepo->getPackages());
		$autoloads = $this->parseAutoloads($packageMap, $mainPackage);

		foreach ($autoloads['psr-0'] as $namespace => $paths) {
			$exportedPaths = array();

			foreach ($paths as $path) {
				$exportedPaths[] = $this->getPathCode($filesystem, $relVendorPath, $vendorPath, $path);
			}

			$exportedPrefix  = var_export($namespace, true);
			$namespacesFile .= "\t$exportedPrefix => ";

			if (count($exportedPaths) > 1) {
				$namespacesFile .= "array(".implode(', ', $exportedPaths)."),\n";
			}
			else {
				$namespacesFile .= $exportedPaths[0].",\n";
			}
		}

		$namespacesFile .= ");\n";
		$classmapFile    = <<<EOF
<?php

// autoload_classmap_52.php generated by xrstf/composer-php52

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(

EOF;

		// add custom psr-0 autoloading if the root package has a target dir
		$targetDirLoader = null;
		$mainAutoload = $mainPackage->getAutoload();
		if ($mainPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
			$levels = count(explode('/', trim(strtr($mainPackage->getTargetDir(), '\\', '/'), '/')));
			$prefixes = implode(', ', array_map(function ($prefix) {
				return var_export($prefix, true);
			}, array_keys($mainAutoload['psr-0'])));
			$baseDirFromTargetDirCode = $filesystem->findShortestPathCode($targetDir, getcwd(), true);

			$targetDirLoader = <<<EOF

	public static function autoload(\$class) {
		\$dir      = $baseDirFromTargetDirCode.'/';
		\$prefixes = array($prefixes);

		foreach (\$prefixes as \$prefix) {
			if (0 !== strpos(\$class, \$prefix)) {
				continue;
			}

			\$path = explode(DIRECTORY_SEPARATOR, self::getClassPath(\$class));
			\$path = \$dir.implode('/', array_slice(\$path, $levels));

			if (!\$path = self::resolveIncludePath(\$path)) {
				return false;
			}

			require \$path;
			return true;
		}
	}

EOF;
		}

		// flatten array
		$classMap = array();
		if ($scanPsr0Packages) {
			foreach ($autoloads['psr-0'] as $namespace => $paths) {
				foreach ($paths as $dir) {
					$dir = $this->getPath($filesystem, $relVendorPath, $vendorPath, $dir);
					$whitelist = sprintf(
						'{%s/%s.+(?<!(?<!/)Test\.php)$}',
						preg_quote(rtrim($dir, '/')),
						strpos($namespace, '_') === false ? preg_quote(strtr($namespace, '\\', '/')) : ''
					);
					if (!is_dir($dir)) {
						continue;
					}
					foreach (ClassMapGenerator::createMap($dir, $whitelist) as $class => $path) {
						if ('' === $namespace || 0 === strpos($class, $namespace)) {
							$path = '/'.$filesystem->findShortestPath(getcwd(), $path, true);
							if (!isset($classMap[$class])) {
								$classMap[$class] = '$baseDir.'.var_export($path, true).",\n";
							}
						}
					}
				}
			}
		}

		$autoloads['classmap'] = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($autoloads['classmap']));
		foreach ($autoloads['classmap'] as $dir) {
			foreach (ClassMapGenerator::createMap($dir) as $class => $path) {
				$path = '/'.$filesystem->findShortestPath(getcwd(), $path, true);
				$classMap[$class] = '$baseDir.'.var_export($path, true).",\n";
			}
		}

		ksort($classMap);
		foreach ($classMap as $class => $code) {
			$classmapFile .= '	'.var_export($class, true).' => '.$code;
		}
		$classmapFile .= ");\n";

		$filesCode = "";
		$autoloads['files'] = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($autoloads['files']));
		foreach ($autoloads['files'] as $functionFile) {
			$filesCode .= '		require '.$this->getPathCode($filesystem, $relVendorPath, $vendorPath, $functionFile).";\n";
		}

		if (!$suffix) {
			$suffix = md5(uniqid('', true));
		}

		file_put_contents($targetDir.'/autoload_namespaces_52.php', $namespacesFile);
		file_put_contents($targetDir.'/autoload_classmap_52.php', $classmapFile);
		if ($includePathFile = $this->getIncludePathsFile($packageMap, $filesystem, $relVendorPath, $vendorPath, $vendorPathCode, $appBaseDirCode)) {
			file_put_contents($targetDir.'/include_paths_52.php', $includePathFile);
		}
		file_put_contents($vendorPath.'/autoload_52.php', $this->getAutoloadFile($vendorPathToTargetDirCode, $suffix));
		file_put_contents($targetDir.'/autoload_real_52.php', $this->getAutoloadRealFile(true, true, (bool) $includePathFile, $targetDirLoader, $filesCode, $vendorPathCode, $appBaseDirCode, $suffix));
		copy(__DIR__.'/ClassLoader.php', $targetDir.'/ClassLoader52.php');
	}

	protected function getIncludePathsFile(array $packageMap, Filesystem $filesystem, $relVendorPath, $vendorPath, $vendorPathCode, $appBaseDirCode) {
		$includePaths = array();

		foreach ($packageMap as $item) {
			list($package, $installPath) = $item;

			if (null !== $package->getTargetDir() && strlen($package->getTargetDir()) > 0) {
				$installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
			}

			foreach ($package->getIncludePaths() as $includePath) {
				$includePath = trim($includePath, '/');
				$includePaths[] = empty($installPath) ? $includePath : $installPath.'/'.$includePath;
			}
		}

		if (!$includePaths) {
			return;
		}

		$includePathsFile = <<<EOF
<?php

// include_paths_52.php generated by xrstf/composer-php52

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(

EOF;

		foreach ($includePaths as $path) {
			$includePathsFile .= "\t" . $this->getPathCode($filesystem, $relVendorPath, $vendorPath, $path) . ",\n";
		}

		return $includePathsFile . ");\n";
	}

	protected function getAutoloadFile($vendorPathToTargetDirCode, $suffix) {
		return <<<AUTOLOAD
<?php

// autoload_52.php generated by xrstf/composer-php52

require_once $vendorPathToTargetDirCode.'/autoload_real_52.php';

return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
	}

	protected function getAutoloadRealFile($usePSR0, $useClassMap, $useIncludePath, $targetDirLoader, $filesCode, $vendorPathCode, $appBaseDirCode, $suffix) {
		// TODO the class ComposerAutoloaderInit should be revert to a closure
		// when APC has been fixed:
		// - https://github.com/composer/composer/issues/959
		// - https://bugs.php.net/bug.php?id=52144
		// - https://bugs.php.net/bug.php?id=61576
		// - https://bugs.php.net/bug.php?id=59298

		if ($filesCode) {
				$filesCode = "\n\n".rtrim($filesCode);
		}

		$file = <<<HEADER
<?php

// autoload_real_52.php generated by xrstf/composer-php52

class ComposerAutoloaderInit$suffix {
	private static \$loader;

	public static function loadClassLoader(\$class) {
		if ('xrstf_Composer52_ClassLoader' === \$class) {
			require dirname(__FILE__).'/ClassLoader52.php';
		}
	}

	/**
	 * @return xrstf_Composer52_ClassLoader
	 */
	public static function getLoader() {
		if (null !== self::\$loader) {
			return self::\$loader;
		}

		spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));
		self::\$loader = \$loader = new xrstf_Composer52_ClassLoader();
		spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));

		\$vendorDir = $vendorPathCode;
		\$baseDir   = $appBaseDirCode;
		\$dir       = dirname(__FILE__);


HEADER;

		if ($useIncludePath) {
			$file .= <<<'INCLUDE_PATH'
		$includePaths = require $dir.'/include_paths_52.php';
		array_push($includePaths, get_include_path());
		set_include_path(implode(PATH_SEPARATOR, $includePaths));


INCLUDE_PATH;
		}

		if ($usePSR0) {
			$file .= <<<'PSR0'
		$map = require $dir.'/autoload_namespaces_52.php';
		foreach ($map as $namespace => $path) {
			$loader->add($namespace, $path);
		}


PSR0;
		}

		if ($useClassMap) {
			$file .= <<<'CLASSMAP'
		$classMap = require $dir.'/autoload_classmap_52.php';
		if ($classMap) {
			$loader->addClassMap($classMap);
		}


CLASSMAP;
		}

		if ($targetDirLoader) {
			$file .= <<<REGISTER_AUTOLOAD
		spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'));


REGISTER_AUTOLOAD;

		}

		$file .= <<<METHOD_FOOTER
		\$loader->register();{$filesCode}

		return \$loader;
	}

METHOD_FOOTER;

		$file .= $targetDirLoader;

		return $file . <<<FOOTER
}

FOOTER;

	}
}
