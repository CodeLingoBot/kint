<?php

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2013 Jonathan Vollebregt (jnvsor@gmail.com), Rokas Šleinius (raveren@gmail.com)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 * FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 * IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Kint\Parser;

use DomainException;
use Exception;
use Kint\Object\BasicObject;
use Kint\Object\BlobObject;
use Kint\Object\InstanceObject;
use Kint\Object\Representation\Representation;
use Kint\Object\ResourceObject;
use ReflectionObject;
use stdClass;

class Parser
{
    /**
     * Plugin triggers.
     *
     * These are constants indicating trigger points for plugins
     *
     * BEGIN: Before normal parsing
     * SUCCESS: After successful parsing
     * RECURSION: After parsing cancelled by recursion
     * DEPTH_LIMIT: After parsing cancelled by depth limit
     * COMPLETE: SUCCESS | RECURSION | DEPTH_LIMIT
     *
     * While a plugin's getTriggers may return any of these
     */
    const TRIGGER_NONE = 0;
    const TRIGGER_BEGIN = 1;
    const TRIGGER_SUCCESS = 2;
    const TRIGGER_RECURSION = 4;
    const TRIGGER_DEPTH_LIMIT = 8;
    const TRIGGER_COMPLETE = 14;

    protected $caller_class;
    protected $depth_limit = false;
    protected $marker;
    protected $object_hashes = array();
    protected $parse_break = false;
    protected $plugins = array();

    /**
     * @param false|int   $depth_limit Maximum depth to parse data
     * @param null|string $caller      Caller class name
     */
    public function __construct($depth_limit = false, $caller = null)
    {
        $this->marker = \uniqid("kint\0", true);

        $this->caller_class = $caller;

        if ($depth_limit) {
            $this->depth_limit = $depth_limit;
        }
    }

    /**
     * Set the caller class.
     *
     * @param null|string $caller Caller class name
     */
    public function setCallerClass($caller = null)
    {
        $this->noRecurseCall();

        $this->caller_class = $caller;
    }

    public function getCallerClass()
    {
        return $this->caller_class;
    }

    /**
     * Set the depth limit.
     *
     * @param false|int $depth_limit Maximum depth to parse data
     */
    public function setDepthLimit($depth_limit = false)
    {
        $this->noRecurseCall();

        $this->depth_limit = $depth_limit;
    }

    public function getDepthLimit()
    {
        return $this->depth_limit;
    }

    /**
     * Disables the depth limit and parses a variable.
     *
     * This should not be used unless you know what you're doing!
     *
     * @param mixed       $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    public function parseDeep(&$var, BasicObject $o)
    {
        $depth_limit = $this->depth_limit;
        $this->depth_limit = false;

        $out = $this->parse($var, $o);

        $this->depth_limit = $depth_limit;

        return $out;
    }

    /**
     * Parses a variable into a Kint object structure.
     *
     * @param mixed       $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    public function parse(&$var, BasicObject $o)
    {
        $o->type = \strtolower(\gettype($var));

        if (!$this->applyPlugins($var, $o, self::TRIGGER_BEGIN)) {
            return $o;
        }

        switch ($o->type) {
            case 'array':
                return $this->parseArray($var, $o);
            case 'boolean':
            case 'double':
            case 'integer':
            case 'null':
                return $this->parseGeneric($var, $o);
            case 'object':
                return $this->parseObject($var, $o);
            case 'resource':
                return $this->parseResource($var, $o);
            case 'string':
                return $this->parseString($var, $o);
            default:
                return $this->parseUnknown($var, $o);
        }
    }

    public function addPlugin(Plugin $p)
    {
        if (!$types = $p->getTypes()) {
            return false;
        }

        if (!$triggers = $p->getTriggers()) {
            return false;
        }

        $p->setParser($this);

        foreach ($types as $type) {
            if (!isset($this->plugins[$type])) {
                $this->plugins[$type] = array(
                    self::TRIGGER_BEGIN => array(),
                    self::TRIGGER_SUCCESS => array(),
                    self::TRIGGER_RECURSION => array(),
                    self::TRIGGER_DEPTH_LIMIT => array(),
                );
            }

            foreach ($this->plugins[$type] as $trigger => &$pool) {
                if ($triggers & $trigger) {
                    $pool[] = $p;
                }
            }
        }

        return true;
    }

    public function clearPlugins()
    {
        $this->plugins = array();
    }

    public function haltParse()
    {
        $this->parse_break = true;
    }

    public function childHasPath(InstanceObject $parent, BasicObject $child)
    {
        if ('object' === $parent->type && (null !== $parent->access_path || $child->static || $child->const)) {
            if (BasicObject::ACCESS_PUBLIC === $child->access) {
                return true;
            }

            if (BasicObject::ACCESS_PRIVATE === $child->access && $this->caller_class) {
                if ($this->caller_class === $child->owner_class) {
                    return true;
                }
            } elseif (BasicObject::ACCESS_PROTECTED === $child->access && $this->caller_class) {
                if ($this->caller_class === $child->owner_class) {
                    return true;
                }

                if (\is_subclass_of($this->caller_class, $child->owner_class)) {
                    return true;
                }

                if (\is_subclass_of($child->owner_class, $this->caller_class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns an array without the recursion marker in it.
     *
     * DO NOT pass an array that has had it's marker removed back
     * into the parser, it will result in an extra recursion
     *
     * @param array $array Array potentially containing a recursion marker
     *
     * @return array Array with recursion marker removed
     */
    public function getCleanArray(array $array)
    {
        unset($array[$this->marker]);

        return $array;
    }

    protected function noRecurseCall()
    {
        $bt = \debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS);

        $caller_frame = array(
            'function' => __FUNCTION__,
        );

        while (isset($bt[0]['object']) && $bt[0]['object'] === $this) {
            $caller_frame = \array_shift($bt);
        }

        foreach ($bt as $frame) {
            if (isset($frame['object']) && $frame['object'] === $this) {
                throw new DomainException(__CLASS__.'::'.$caller_frame['function'].' cannot be called from inside a parse');
            }
        }
    }

    

    /**
     * Parses a string into a Kint BlobObject structure.
     *
     * @param string      $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    

    /**
     * Parses an array into a Kint object structure.
     *
     * @param array       $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    

    /**
     * Parses an object into a Kint InstanceObject structure.
     *
     * @param object      $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    

    /**
     * Parses a resource into a Kint ResourceObject structure.
     *
     * @param resource    $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    

    /**
     * Parses an unknown into a Kint object structure.
     *
     * @param mixed       $var The input variable
     * @param BasicObject $o   The base object
     *
     * @return BasicObject
     */
    

    /**
     * Applies plugins for an object type.
     *
     * @param mixed       $var     variable
     * @param BasicObject $o       Kint object parsed so far
     * @param int         $trigger The trigger to check for the plugins
     *
     * @return bool Continue parsing
     */
    
}
