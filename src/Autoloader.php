<?php

namespace Infira\Autoloader;

use Infira\Utils\Regex;

class Autoloader
{
	private static $locations          = [];
	private static $settedIncludePaths = [];
	private static $isInited           = false;
	private static $collectedFiles     = [];
	private static $allCollectedFiles  = [];
	public static  $updateFromConsole  = false;
	private static $maxLen             = 0;
	
	
	public static function init(string $classLocationFilePath = null): bool
	{
		if (self::$isInited)
		{
			return true;
		}
		self::$isInited = true;
		if ($classLocationFilePath !== null)
		{
			if (!file_exists($classLocationFilePath))
			{
				self::error($classLocationFilePath . " does not exists");
			}
			require_once $classLocationFilePath;
		}
		spl_autoload_register(function ($class)
		{
			if (in_array($class, ["Memcached", "Memcache"]))
			{
				return true;
			}
			$requireFile = null;
			if (self::exists($class))
			{
				$requireFile = self::$locations[$class];
				if (!file_exists($requireFile))
				{
					self::error("Autoloader: class '$class file '$requireFile' not found");
				}
				else
				{
					require_once($requireFile);
				}
			}
			
			return true;
		}, true);
		
		return true;
	}
	
	/**
	 * Does autolaoder know $class location
	 *
	 * @param $class
	 * @return bool
	 */
	public static function exists(string $class): bool
	{
		return array_key_exists($class, self::$locations);
	}
	
	/**
	 * @param string $class
	 * @param string $classFileLocation
	 */
	public static function setPath(string $class, string $classFileLocation)
	{
		if (!file_exists($classFileLocation))
		{
			self::error("class \"$class\" file \"$classFileLocation\" not found");
		}
		self::$locations[$class] = $classFileLocation;
	}
	
	public static function generateCache(array $conifgFiles, string $installLocation): array
	{
		$cw = getcwd() . '/';
		
		foreach ($conifgFiles as $jsonFile)
		{
			if (is_array($jsonFile))
			{
				self::loadFromJson($jsonFile[0], $jsonFile[1]);
			}
			else
			{
				self::loadFromJson($jsonFile);
			}
		}
		$lines = ['<?php'];
		
		foreach (self::$collectedFiles as $row)
		{
			$line    = str_replace('[SPACES]', str_repeat(' ', self::$maxLen - $row->len), $row->str);
			$lines[] = $line;
			if (self::$updateFromConsole)
			{
				echo $line . "\n";
			}
		}
		$lines[] = '?>';
		
		file_put_contents($installLocation, trim(join("\n", $lines)));
		
		return array_flip(self::$allCollectedFiles);
	}
	
	private static function loadFromJson(string $jsonFile, string $addPathPrefix = '')
	{
		if (!file_exists($jsonFile))
		{
			self::error("Config file \"$jsonFile\" not found");
		}
		
		$config             = (array)json_decode(file_get_contents($jsonFile));
		$config['classMap'] = (array)$config['classMap'];
		if (isset($config['scan']))
		{
			foreach ($config['scan'] as $item)
			{
				if (!is_dir($item->path))
				{
					self::error($item->path . ' is not a dir');
				}
				self::collectPath($item->path, $item->recursive);
			}
		}
		if (isset($config['classMap']))
		{
			foreach ($config['classMap'] as $class => $path)
			{
				self::collect($class, $addPathPrefix . $path);
			}
		}
	}
	
	private static function collect(string $name, $file)
	{
		if (!file_exists($file))
		{
			self::error('Autoloader  "' . $name . ':" file not found:' . $file);
		}
		if (!isset(self::$allCollectedFiles[$file]))
		{
			$name                           = explodeAt('.', basename($name), 0);
			$lineStart                      = 'self::$locations[\'' . $name . '\']';
			$len                            = strlen($lineStart);
			self::$maxLen                   = max(self::$maxLen, $len);
			$line                           = new \stdClass();
			$line->file                     = $file;
			$line->str                      = $lineStart . '[SPACES] = \'' . $file . '\';';
			$line->len                      = $len;
			self::$collectedFiles[$name]    = $line;
			self::$allCollectedFiles[$file] = $name;
		}
	}
	
	private static function collectPath(string $dir, bool $recursive = false)
	{
		$dir = trim($dir);
		$dir = self::fixPath($dir);
		if (is_dir($dir))
		{
			if (isset(self::$settedIncludePaths[$dir]))
			{
				self::error("Path($dir) is already added");
			}
			self::$settedIncludePaths[$dir] = $dir;
			foreach (glob($dir . "*.php") as $file)
			{
				$basename = basename($file);
				$src      = file_get_contents($file);
				if (Regex::isMatch('/namespace (.+)?;/m', $src))
				{
					$matches = [];
					preg_match_all('/namespace (.+)?;/m', $src, $matches);
					self::collect($matches[1][0] . '\\' . $basename, $file);
				}
				else
				{
					if (Regex::isMatch('/^class ([[A-Za-z0-9_]+)/m', $src))
					{
						$matches = [];
						preg_match_all('/^class ([[A-Za-z0-9_]+)/m', $src, $matches);
						self::collect($matches[1][0], $file);
					}
					elseif (Regex::isMatch('/^abstract class ([[A-Za-z0-9_]+)/m', $src))
					{
						$matches = [];
						preg_match_all('/^abstract class ([[A-Za-z0-9_]+)/m', $src, $matches);
						self::collect($matches[1][0], $file);
					}
					elseif (Regex::isMatch('/^trait ([[A-Za-z0-9_]+)/m', $src))
					{
						$matches = [];
						preg_match_all('/^trait ([[A-Za-z0-9_]+)/m', $src, $matches);
						self::collect($matches[1][0], $file);
					}
				}
			}
			if ($recursive)
			{
				$handler = scandir($dir);
				if (is_array($handler) and count($handler) > 0)
				{
					unset($handler[0]);
					unset($handler[1]);
					if (is_array($handler) and count($handler) > 0)
					{
						foreach ($handler as $nDir)
						{
							if ($nDir != "..")
							{
								if (!in_array($nDir, [".git", ".svn"]))
								{
									$sDir = self::fixPath($dir . $nDir);
									if (is_dir($sDir))
									{
										self::collectPath($sDir, true);
									}
								}
							}
						}
					}
				}
			}
		}
		else
		{
			self::error("collectPath: $dir is not dir");
		}
	}
	
	private static function error($msg)
	{
		if (self::$updateFromConsole)
		{
			echo('CONSOLE_ERROR:' . $msg . "\n");
		}
		else
		{
			trigger_error($msg);
		}
	}
	
	private static function fixPath($dir)
	{
		if ($dir) //if empty reutrn empty
		{
			if (is_file($dir))
			{
				return $dir;
			}
			$dir = str_replace("/", DIRECTORY_SEPARATOR, $dir);
			$len = strlen($dir) - 1;
			if ($dir{$len} != DIRECTORY_SEPARATOR and !is_file($dir))
			{
				$dir .= DIRECTORY_SEPARATOR;
			}
		}
		
		return $dir;
	}
}

?>