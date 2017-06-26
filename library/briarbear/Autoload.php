<?php
/**
 * briabear
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/3/1
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 *
 *
 * Autoload
 *
 *   Autoload - Simple and Concise PHP Autoloader
 *       PSR-4 and PSR-0 convention with classMap API and include_path support
 *   PSR-4 convention - for details: see http://www.php-fig.org/psr/psr-4/
 *   PSR-0 convention - for details: see http://www.php-fig.org/psr/psr-0/
 *
 * @package BriarBear
 * @version 2.2
 */
namespace BriarBear;

class Autoload
{
    /**
     * @var array registered path aliases
     */
    protected $namespaces = [];
    /**
     * @var array class already loaded
     */
    protected $checked = [];
    /**
     * @var array class map used by the BriarBear autoloading mechanism.
     * The array keys are the class names (without leading backslashes), and the array values
     * are the corresponding class file paths (or path aliases). This property mainly affects
     * how [[autoload()]] works.
     * @see autoload()
     */
    protected $classMap       = [];
    protected $useCache       = true;
    protected $useIncludePath = false;
    /**
     * @var Autoload The instance of Autoload
     */
    private static $instance;

    /**
     * @return Autoload
     */
    public static function register()
    {
        $instance = static::getInstance();
        $instance->splRegister();
        return $instance;
    }

    /**
     * @return Autoload
     */
    public static function getInstance()
    {
        if (!isset(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }
    public function addNamespaces($namespaces)
    {
        foreach ($namespaces as $prefix => $baseDir) {
            $this->addNamespace($prefix, $baseDir);
        }
        return $this;
    }

    /**
     * Add namespace path alias
     * @param string $prefix
     * @param string|array $baseDir
     * @param bool $prepend
     * @return $this
     */
    public function addNamespace($prefix, $baseDir, $prepend = false)
    {
        if (is_array($baseDir)) {
            foreach ($baseDir as $dir) {
                $this->addNamespace($prefix, $dir, $prepend);
            }
        } else {
            $prefix  = trim($prefix, '\\') . '\\';
            $baseDir = rtrim($baseDir, '/') . '/';
            if (!isset($this->namespaces[$prefix])) {
                $this->namespaces[$prefix] = [];
            }

            if ($prepend) {
                array_unshift($this->namespaces[$prefix], $baseDir);
            } else {
                array_push($this->namespaces[$prefix], $baseDir);
            }

        }
        return $this;
    }

    /**
     * Add the direct load class
     * the $class is the class name
     * the $file is the real file path for class
     * @param string $class
     * @param string $file
     */
    public function addClass($class, $file)
    {
        $this->classMap[$class] = $file;
    }

    /**
     * Add the direct load class
     * the key is the class name
     * the value is the real file path for class
     * @param array $classMap
     */
    public function addClassMap(array $classMap)
    {
        $this->classMap = array_merge($this->classMap, $classMap);
    }
    public function useCache($b = true)
    {
        $this->useCache = $b;
    }
    public function useIncludePath($b = true)
    {
        $this->useIncludePath = $b;
    }

    /**
     * require a given class file and cached it.
     * @param $file
     * @param $class
     * @return bool
     * @throws \Exception
     */
    protected function loadFile($file, $class)
    {
        if (file_exists($file)
            || ($this->useIncludePath && ($file = stream_resolve_include_path($file)))) {
            require $file;
            if (!class_exists($class, false) && !interface_exists($class, false) && !trait_exists($class, false)) {
                throw new \Exception('Class "' . $class . '" not found as expected in "' . $file . '"');
            }

            if ($this->useCache) {
                $this->checked[] = $class;
            }

            return true;
        }
        return false;
    }

    /**
     * @param $class
     * @param $relativeClass
     * @param $prefix
     * @param $ext
     * @return bool
     */
    protected function findRelative($class, $relativeClass, $prefix, $ext)
    {
        if (isset($this->namespaces[$prefix])) {
            foreach ($this->namespaces[$prefix] as $baseDir) {
                $file = $baseDir . str_replace('\\', '/', $relativeClass) . $ext;
                if ($this->loadFile($file, $class)) {
                    return true;
                }
            }
        }
    }

    /**
     * determine the real file path for given class , and require it
     * @param $class
     * @param string $ext
     * @param bool $psr0
     * @return bool
     */
    public function findClass($class, $ext = '.php', $psr0 = false)
    {
        $prefix = $class;
        while ($prefix != '\\') {
            $prefix = rtrim($prefix, '\\');
            $pos    = strrpos($prefix, '\\');
            if ($pos !== false) {
                $prefix        = substr($class, 0, $pos + 1);
                $relativeClass = substr($class, $pos + 1);
            } else {
                $prefix        = '\\';
                $relativeClass = $class;
            }
            if ($psr0) {
                $relativeClass = str_replace('_', '/', $relativeClass);
            }

            if ($this->findRelative($class, $relativeClass, $prefix, $ext)) {
                return true;
            }

        }
    }

    /**
     * Function as __autoload() implementation
     * auto find the class file, and require it.
     * @param $class
     * @return bool
     */
    public function classLoad($class)
    {
        if ($this->useCache && in_array($class, $this->checked)) {
            return true;
        }

        if (isset($this->classMap[$class]) && $this->loadFile($this->classMap[$class], $class)) {
            return true;
        }

        if ($this->findClass($class)) {
            return true;
        }

        if ($this->findClass($class, '.php', true)) {
            return true;
        }
        return false;
    }
    public function __invoke($class)
    {
        return $this->classLoad($class);
    }

    /**
     * Register given function as __autoload() implementation
     * @link http://php.net/manual/en/function.spl-autoload-register.php
     */
    public function splRegister()
    {
        spl_autoload_register([$this, 'classLoad'], true, true);
    }

    /**
     * Unregister given function as __autoload() implementation
     * @link http://php.net/manual/en/function.spl-autoload-unregister.php
     */
    public function splUnregister()
    {
        spl_autoload_unregister([$this, 'classLoad']);
    }
}
