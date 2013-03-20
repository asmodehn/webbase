<?php
//
// +----------------------------------------------------------------------+
// | PEAR :: DB_NestedSet                                                 |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2003 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/2_02.txt.                                 |
// | If you did not receive a copy of the PHP license and are unable to   |f
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Daniel Khan <dk@webcluster.at>                              |
// |          Jason Rust  <jason@rustyparts.com>                          |
// +----------------------------------------------------------------------+
// $Id: NestedSet.php,v 1.56 2003/10/07 00:11:26 datenpunk Exp $
//

// CREDITS:
// --------
// - Thanks to Kristian Koehntopp for publishing an explanation of the Nested Set
//   technique and for the great work he did and does for the php community
// - Thanks to Daniel T. Gorski for his great tutorial on www.develnet.org
//
// - Thanks to my parents for ... just kidding :]

require_once 'PEAR.php';

// {{{ constants

// Error and message codes
define('NESE_ERROR_RECURSION',    'E100');
define('NESE_ERROR_NODRIVER',   'E200');
define('NESE_ERROR_NOHANDLER',    'E300');
define('NESE_ERROR_TBLOCKED',     'E010');
define('NESE_MESSAGE_UNKNOWN',    'E0');
define('NESE_ERROR_NOTSUPPORTED', 'E1');
define('NESE_ERROR_PARAM_MISSING','E400');
define('NESE_ERROR_NOT_FOUND',    'E500');
define('NESE_ERROR_WRONG_MPARAM', 'E2');

// for moving a node before another
define('NESE_MOVE_BEFORE', 'BE');
// for moving a node after another
define('NESE_MOVE_AFTER', 'AF');
// for moving a node below another
define('NESE_MOVE_BELOW', 'SUB');


// Sortorders
define('NESE_SORT_LEVEL', 'SLV');
define('NESE_SORT_PREORDER', 'SPO');

// }}}
// {{{ DB_NestedSet:: class
/**
* DB_NestedSet is a class for handling nested sets
*
* @author       Daniel Khan <dk@webcluster.at>
* @package      DB_NestedSet
* @version      $Revision: 1.56 $
* @access       public
*/

// }}}
class DB_NestedSet {
    // {{{ properties

    /**
    * @var array The field parameters of the table with the nested set. Format: 'realFieldName' => 'fieldId'
    * @access public
    */
    var $params = array(
    'STRID' => 'id',
    'ROOTID'=> 'rootid',
    'l'     => 'l',
    'r'     => 'r',
    'STREH' => 'norder',
    'LEVEL' => 'level',
    // 'parent'=>'parent', // Optional but very useful
    'STRNA' => 'name'
    );

    // To be used with 2.0 - would be an api break atm
    // var $quotedParams = array('name');

    /**
    * @var string The table with the actual tree data
    * @access public
    */
    var $node_table = 'tb_nodes';

    /**
    * @var string The table to handle locking
    * @access public
    */
    var $lock_table = 'tb_locks';

    /**
    * @var string The table used for sequences
    * @access public
    */
    var $sequence_table;

    /**
    * Secondary order field.  Normally this is the order field, but can be changed to
    * something else (i.e. the name field so that the tree can be shown alphabetically)
    *
    * @var string
    * @access public
    */
    var $secondarySort;

    /**
    * Used to store the secondary sort method set by the user while doing manipulative queries
    *
    * @var string
    * @access private
    */
    var $_userSecondarySort = false;

    /**
    * The default sorting field - will be set to the table column inside the constructor
    *
    * @var string
    * @access private
    */
    var $_defaultSecondarySort = 'norder';

    /**
    * @var int The time to live of the lock
    * @access public
    */
    var $lockTTL = 1;

    /**
    * @var bool Enable debugging statements?
    * @access public
    */
    var $debug = 0;

    /**
    * @var bool Lock the structure of the table?
    * @access private
    */
    var $_structureTableLock = false;


    /**
    * @var bool Don't allow unlocking (used inside of moves)
    * @access private
    */
    var $_lockExclusive     = false;

    /**
    * @var object cache Optional PEAR::Cache object
    * @access public
    */
    var $cache = false;

    /**
    * Specify the sortMode of the query methods
    * NESE_SORT_LEVEL is the 'old' sorting method and sorts a tree by level
    * all nodes of level 1, all nodes of level 2,...
    * NESE_SORT_PREORDER will sort doing a preorder walk.
    * So all children of node x will come right after it
    * Note that moving a node within it's siblings will obviously not change the output
    * in this mode
    *
    * @var constant Order method (NESE_SORT_LEVEL|NESE_SORT_PREORDER)
    * @access private
    */
    var $_sortMode   = NESE_SORT_LEVEL;

    /**
    * @var array Available sortModes
    * @access private
    */
    var $_sortModes = array(NESE_SORT_LEVEL, NESE_SORT_PREORDER);

    /**
    * @var array An array of field ids that must exist in the table
    * @access private
    */
    var $_requiredParams = array('id', 'rootid', 'l', 'r', 'norder', 'level');

    /**
    * @var bool Skip the callback events?
    * @access private
    */
    var $_skipCallbacks = false;

    /**
    * @var bool Do we want to use caching
    * @access private
    */
    var $_caching = false;

    /**
    * @var array The above parameters flipped for easy access
    * @access private
    */
    var $_flparams = array();

    /**
    *
    * @var bool Temporary switch for cache
    * @access private
    */
    var $_restcache = false;

    /**
    * Used to determine the presence of listeners for an event in triggerEvent()
    *
    * If any event listeners are registered for an event, the event name will
    * have a key set in this array, otherwise, it will not be set.
    * @see triggerEvent()
    * @var arrayg
    * @access private
    */
    var $_hasListeners = array();


    /**
    * @var string packagename
    * @access private
    */
    var $_packagename   = 'DB_NestedSet';

    /**
    * @var int Majorversion
    * @access private
    */
    var $_majorversion   = 1;

    /**
    * @var string Minorversion
    * @access private
    */
    var $_minorversion   = '3';

    /**
    * @var array Used for mapping a cloned tree to the real tree for move_* operations
    * @access private
    */
    var $_relations      = array();

    /**
    * Used for _internal_ tree conversion
    * @var bool Turn off user param verification and id generation
    * @access private
    */
    var $_dumbmode      = false;

    /**
    * @var array Map of error messages to their descriptions
    */
    var $messages = array(
    NESE_ERROR_RECURSION    => '%s: This operation would lead to a recursion',
    NESE_ERROR_TBLOCKED     => 'The structure Table is locked for another database operation, please retry.',
    NESE_ERROR_NODRIVER   => 'The selected database driver %s wasn\'t found',
    NESE_ERROR_NOTSUPPORTED => 'Method not supported yet',
    NESE_ERROR_NOHANDLER    => 'Event handler not found',
    NESE_ERROR_PARAM_MISSING=> 'Parameter missing',
    NESE_MESSAGE_UNKNOWN    => 'Unknown error or message',
    NESE_ERROR_NOT_FOUND    => '%s: Node %s not found',
    NESE_ERROR_WRONG_MPARAM        => '%s: %s'
    );

    /**
    * @var array The array of event listeners
    * @access private
    */
    var $eventListeners = array();


    // }}}
    // +---------------------------------------+
    // | Base methods                          |
    // +---------------------------------------+
    // {{{ constructor

    /**
    * Constructor
    *
    * @param array $params Database column fields which should be returned
    *
    * @access private
    * @return void
    */
    function DB_NestedSet($params) {

        if ($this->debug) {
            $this->_debugMessage('DB_NestedSet()');
        }
        if (is_array($params) && count($params) > 0) {
            $this->params = $params;
        }

        $this->_flparams = array_flip($this->params);
        $this->sequence_table = $this->node_table . '_' . $this->_flparams['id'];
        $this->secondarySort = $this->_flparams[$this->_defaultSecondarySort];
        register_shutdown_function(array(&$this,'_DB_NestedSet'));
    }

    // }}}
    // {{{ destructor

    /**
    * PEAR Destructor
    * Releases all locks
    * Closes open database connections
    *
    * @access private
    * @return void
    */
    function _DB_NestedSet() {
        if ($this->debug) {
            $this->_debugMessage('_DB_NestedSet()');
        }
        $this->_releaseLock(true);
    }

    // }}}
    // {{{ factory

    /**
    * Handles the returning of a concrete instance of DB_NestedSet based on the driver.
    * If the class given by $driver allready exists it will be used.
    * If not the driver will be searched inside the default path ./NestedSet/
    *
    * @param string $driver The driver, such as DB or MDB
    * @param string $dsn The dsn for connecting to the database
    * @param array $params The field name params for the node table
    *
    * @static
    * @access public
    * @return object The DB_NestedSet object
    */
    function & factory($driver, $dsn, $params = array()) {

        $classname = 'DB_NestedSet_' . $driver;
        if (!class_exists($classname)) {
            $driverpath = dirname(__FILE__).'/NestedSet/'.$driver.'.php';
            if(!file_exists($driverpath) || !$driver) {
                return PEAR::raiseError("factory(): The database driver '$driver' wasn't found", NESE_ERROR_NODRIVER, PEAR_ERROR_TRIGGER, E_USER_ERROR);
            }
            include_once($driverpath);
        }
        return new $classname($dsn, $params);
    }

    // }}}


    // }}}
    // +----------------------------------------------+
    // | NestedSet manipulation and query methods     |
    // |----------------------------------------------+
    // | Querying the tree                            |
    // +----------------------------------------------+

    // {{{ getAllNodes()

    /**
    * Fetch the whole NestedSet
    *
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function getAllNodes($keepAsArray = false, $aliasFields = true, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getAllNodes()');
        }

        if($this->_sortMode == NESE_SORT_LEVEL) {
            $sql = sprintf('SELECT %s %s FROM %s %s %s ORDER BY %s.%s, %s.%s ASC',
            $this->_getSelectFields($aliasFields),
            $this->_addSQL($addSQL, 'cols'),
            $this->node_table,
            $this->_addSQL($addSQL, 'join'),
            $this->_addSQL($addSQL, 'append'),
            $this->node_table,
            $this->_flparams['level'],
            $this->node_table,
            $this->secondarySort);
        } elseif ($this->_sortMode == NESE_SORT_PREORDER) {
            $nodeSet = array();
            $rootnodes = $this->getRootNodes(true);
            foreach($rootnodes AS $rid=>$rootnode) {
                $nodeSet = $nodeSet+$this->getBranch($rootnode, true);
            }
            return $nodeSet;
        }

        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
            }
        }
        return $nodeSet;
    }

    // }}}
    // {{{ getRootNodes()

    /**
    * Fetches the first level (the rootnodes) of the NestedSet
    *
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function getRootNodes($keepAsArray = false, $aliasFields = true, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getRootNodes()');
        }
        $sql = sprintf('SELECT %s %s FROM %s %s WHERE %s.%s=%s.%s %s ORDER BY %s.%s ASC',
        $this->_getSelectFields($aliasFields),
        $this->_addSQL($addSQL, 'cols'),
        $this->node_table,
        $this->_addSQL($addSQL, 'join'),
        $this->node_table,
        $this->_flparams['id'],
        $this->node_table,
        $this->_flparams['rootid'],
        $this->_addSQL($addSQL, 'append'),
        $this->node_table,
        $this->secondarySort);

        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
            }
        }
        return $nodeSet;
    }

    // }}}

    // {{{ getBranch()

    /**
    * Fetch the whole branch where a given node id is in
    *
    * @param int  $id The node ID
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function getBranch($id, $keepAsArray = false, $aliasFields = true, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getBranch($id)');
        }
        if (!($thisnode = $this->pickNode($id, true))) {
            $epr = array('getBranch()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_NOTICE, $epr);
        }
        if($this->_sortMode == NESE_SORT_LEVEL) {
            $firstsort = $this->_flparams['level'];
            $sql = sprintf('SELECT %s %s FROM %s %s WHERE %s.%s=%s %s ORDER BY %s.%s, %s.%s ASC',
            $this->_getSelectFields($aliasFields),
            $this->_addSQL($addSQL, 'cols'),
            $this->node_table,
            $this->_addSQL($addSQL, 'join'),
            $this->node_table,
            $this->_flparams['rootid'],
            $thisnode['rootid'],
            $this->_addSQL($addSQL, 'append'),
            $this->node_table,
            $firstsort,
            $this->node_table,
            $this->secondarySort);
        } elseif($this->_sortMode == NESE_SORT_PREORDER) {
            $firstsort = $this->_flparams['l'];
            $sql = sprintf('SELECT %s %s FROM %s %s WHERE %s.%s=%s %s ORDER BY %s.%s ASC',
            $this->_getSelectFields($aliasFields),
            $this->_addSQL($addSQL, 'cols'),
            $this->node_table,
            $this->_addSQL($addSQL, 'join'),
            $this->node_table,
            $this->_flparams['rootid'],
            $thisnode['rootid'],
            $this->_addSQL($addSQL, 'append'),
            $this->node_table,
            $firstsort);
        }


        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
            }
        }
        if($this->_sortMode == NESE_SORT_PREORDER && ($this->params[$this->secondarySort] != $this->_defaultSecondarySort)) {
            uasort($nodeSet, array($this, '_secSort'));
        }
        return $nodeSet;
    }

    // }}}
    // {{{ getParents()

    /**
    * Fetch the parents of a node given by id
    *
    * @param int  $id The node ID
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function getParents($id, $keepAsArray = false, $aliasFields = true, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getParents($id)');
        }
        if (!($child = $this->pickNode($id, true))) {
            $epr = array('getParents()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_NOTICE, $epr);
        }

        $sql = sprintf('SELECT %s %s FROM %s %s
                        WHERE %s.%s=%s AND %s.%s<%s AND %s.%s<%s AND %s.%s>%s %s
                        ORDER BY %s.%s ASC',
        $this->_getSelectFields($aliasFields),
        $this->_addSQL($addSQL, 'cols'),
        $this->node_table,
        $this->_addSQL($addSQL, 'join'),
        $this->node_table,
        $this->_flparams['rootid'],
        $child['rootid'],
        $this->node_table,
        $this->_flparams['level'],
        $child['level'],
        $this->node_table,
        $this->_flparams['l'],
        $child['l'],
        $this->node_table,
        $this->_flparams['r'],
        $child['r'],
        $this->_addSQL($addSQL, 'append'),
        $this->node_table,
        $this->_flparams['level']);

        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
            }
        }
        return $nodeSet;
    }

    // }}}
    // {{{ getParent()

    /**
    * Fetch the immediate parent of a node given by id
    *
    * @param int  $id The node ID
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or the parent node
    */
    function getParent($id, $keepAsArray = false, $aliasFields = true, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getParent($id)');
        }
        if (!($child = $this->pickNode($id, true))) {
            $epr = array('getParent()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_NOTICE, $epr);
        }

        if($child['id'] == $child['rootid']) {
            return false;
        }

        // If parent node is set inside the db simply return it
        if(isset($child['parent']) && !empty($child['parent'])) {
            return $this->pickNode($child['parent'], $keepAsArray, $aliasFields, 'id', $addSQL);
        }

        $addSQL['append'] = sprintf('AND %s.%s = %s',
        $this->node_table,
        $this->_flparams['level'],
        $child['level']-1);

        $nodeSet =  $this->getParents($id, $keepAsArray, $aliasFields, $addSQL);

        if(!empty($nodeSet)) {
            $keys = array_keys($nodeSet);
            return $nodeSet[$keys[0]];
        } else {
            return false;
        }
    }

    // }}}
    // {{{ getSiblings)

    /**
    * Fetch all siblings of the node given by id
    * Important: The node given by ID will also be returned
    * Do a unset($array[$id]) on the result if you don't want that
    *
    * @param int  $id The node ID
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or the parent node
    */
    function getSiblings($id, $keepAsArray = false, $aliasFields = true, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getParents($id)');
        }

        if (!($sibling = $this->pickNode($id, true))) {
            $epr = array('getSibling()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_NOTICE, $epr);
        }

        $parent = $this->getParent($sibling, true);

        return $this->getChildren($parent, $keepAsArray, $aliasFields, $addSQL);
    }

    // }}}
    // {{{ getChildren()

    /**
    * Fetch the children _one level_ after of a node given by id
    *
    * @param int  $id The node ID
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param bool $forceNorder (optional) Force the result to be ordered by the norder
    *             param (as opposed to the value of secondary sort).  Used by the move and
    *             add methods.
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function getChildren($id, $keepAsArray = false, $aliasFields = true, $forceNorder = false, $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('getChildren($id)');
        }

        if (!($parent = $this->pickNode($id, true))) {
            $epr = array('getChildren()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_NOTICE, $epr);
        }
        if (!$parent || $parent['l'] == ($parent['r'] - 1)) {
            return false;
        }

        $sql = sprintf('SELECT %s %s FROM %s %s
                        WHERE %s.%s=%s AND %s.%s=%s+1 AND %s.%s BETWEEN %s AND %s %s
                        ORDER BY %s.%s ASC',
        $this->_getSelectFields($aliasFields),
        $this->_addSQL($addSQL, 'cols'),
        $this->node_table,
        $this->_addSQL($addSQL, 'join'),
        $this->node_table,
        $this->_flparams['rootid'],
        $parent['rootid'],
        $this->node_table,
        $this->_flparams['level'],
        $parent['level'],
        $this->node_table,
        $this->_flparams['l'],
        $parent['l'],
        $parent['r'],
        $this->_addSQL($addSQL, 'append'),
        $this->node_table,
        $this->secondarySort);

        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
            }
        }
        return $nodeSet;
    }

    // }}}
    // {{{ getSubBranch()

    /**
    * Fetch all the children of a node given by id
    *
    * getChildren only queries the immediate children
    * getSubBranch returns all nodes below the given node
    *
    * @param string  $id The node ID
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function getSubBranch($id, $keepAsArray = false, $aliasFields = true, $addSQL = array()) {

        if ($this->debug) {
            $this->_debugMessage('getSubBranch($id)');
        }
        if (!($parent = $this->pickNode($id, true))) {
            $epr = array('getSubBranch()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, E_USER_NOTICE, $epr);
        }
        if($this->_sortMode == NESE_SORT_LEVEL) {
            $firstsort = $this->_flparams['level'];
            $sql = sprintf('SELECT %s %s FROM %s %s WHERE %s.%s BETWEEN %s AND %s AND %s.%s=%s AND %s.%s!=%s %s ORDER BY %s.%s, %s.%s ASC',
            $this->_getSelectFields($aliasFields),
            $this->_addSQL($addSQL, 'cols'),
            $this->node_table,
            $this->_addSQL($addSQL, 'join'),
            $this->node_table,
            $this->_flparams['l'],
            $parent['l'],
            $parent['r'],
            $this->node_table,
            $this->_flparams['rootid'],
            $parent['rootid'],
            $this->node_table,
            $this->_flparams['id'],
            $this->_addSQL($addSQL, 'append'),
            $id,
            $this->node_table,
            $firstsort,
            $this->node_table,
            $this->secondarySort
            );
        } elseif($this->_sortMode == NESE_SORT_PREORDER) {
            $firstsort = $this->_flparams['l'];
            $firstsort = $this->_flparams['level'];
            $sql = sprintf('SELECT %s %s FROM %s %s WHERE %s.%s BETWEEN %s AND %s AND %s.%s=%s AND %s.%s!=%s %s ORDER BY %s.%s  ASC',
            $this->_getSelectFields($aliasFields),
            $this->_addSQL($addSQL, 'cols'),
            $this->node_table,
            $this->_addSQL($addSQL, 'join'),
            $this->node_table,
            $this->_flparams['l'],
            $parent['l'],
            $parent['r'],
            $this->node_table,
            $this->_flparams['rootid'],
            $parent['rootid'],
            $this->node_table,
            $this->_flparams['id'],
            $this->_addSQL($addSQL, 'append'),
            $id,
            $this->node_table,
            $firstsort
            );
        }

        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
            }
        }
        if($this->params[$this->secondarySort] != $this->_defaultSecondarySort) {
            uasort($nodeSet, array($this, '_secSort'));
        }
        return $nodeSet;
    }

    // }}}
    // {{{ pickNode()

    /**
    * Fetch the data of a node with the given id
    *
    * @param int  $id The node id of the node to fetch
    * @param bool $keepAsArray (optional) Keep the result as an array or transform it into
    *             a set of DB_NestedSet_Node objects?
    * @param bool $aliasFields (optional) Should we alias the fields so they are the names
    *             of the parameter keys, or leave them as is?
    * @param string $idfield (optional) Which field has to be compared with $id?
    *              This is can be used to pick a node by other values (e.g. it's name).
    * @param array $addSQL (optional) Array of additional params to pass to the query.
    *
    * @see _addSQL()
    * @access public
    * @return mixed False on error, or an array of nodes
    */
    function pickNode($id, $keepAsArray = false, $aliasFields = true, $idfield = 'id', $addSQL = array()) {
        if ($this->debug) {
            $this->_debugMessage('pickNode($id)');
        }

        if (is_object($id) && $id->id) {
            return $id;
        } elseif (is_array($id) && isset($id['id'])) {
            return $id;
        }

        if(!$id) {
            return false;
        }

        $sql = sprintf('SELECT %s %s FROM %s %s WHERE %s.%s=%s %s',
        $this->_getSelectFields($aliasFields),
        $this->_addSQL($addSQL, 'cols'),
        $this->node_table,
        $this->_addSQL($addSQL, 'join'),
        $this->node_table,
        $this->_flparams[$idfield],
        $id,
        $this->_addSQL($addSQL, 'append'));

        if (!$this->_caching) {
            $nodeSet = $this->_processResultSet($sql, $keepAsArray, $aliasFields);
        } else {
            $nodeSet = $this->cache->call('DB_NestedSet->_processResultSet', $sql, $keepAsArray, $aliasFields);
        }

        $nsKey = false;

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeLoad'])) {
            // EVENT (nodeLoad)
            foreach (array_keys($nodeSet) as $key) {
                $this->triggerEvent('nodeLoad', $nodeSet[$key]);
                $nsKey = $key;
            }
        } else {
            foreach (array_keys($nodeSet) as $key) {
                $nsKey = $key;
            }
        }

        if (is_array($nodeSet) && $idfield != 'id') {
            $id = $nsKey;
        }

        return isset($nodeSet[$id]) ? $nodeSet[$id] : false;
    }

    // }}}
    // {{{ isParent()

    /**
    * See if a given node is a parent of another given node
    *
    * A node is considered to be a parent if it resides above the child
    * So it doesn't mean that the node has to be an immediate parent.
    * To get this information simply compare the levels of the two nodes
    * after you know that you have a parent relation.
    *
    * @param mixed  $parent The parent node as array or object
    * @param mixed  $child  The child node as array or object
    *
    * @access public
    * @return bool True if it's a parent
    */
    function isParent($parent, $child) {

        if ($this->debug) {
            $this->_debugMessage('isParent($parent, $child)');
        }

        if (!isset($parent)|| !isset($child)) {
            return false;
        }

        if (is_array($parent)) {
            $p_rootid   = $parent['rootid'];
            $p_l        = $parent['l'];
            $p_r        = $parent['r'];

        } elseif (is_object($parent)) {
            $p_rootid   = $parent->rootid;
            $p_l        = $parent->l;
            $p_r        = $parent->r;
        }

        if (is_array($child)) {
            $c_rootid   = $child['rootid'];
            $c_l        = $child['l'];
            $c_r        = $child['r'];
        } elseif (is_object($child)) {
            $c_rootid   = $child->rootid;
            $c_l        = $child->l;
            $c_r        = $child->r;
        }

        if (($p_rootid == $c_rootid) && ($p_l < $c_l && $p_r > $c_r)) {
            return true;
        }

        return false;
    }


    // }}}
    // +----------------------------------------------+
    // | NestedSet manipulation and query methods     |
    // |----------------------------------------------+
    // | insert / delete / update of nodes            |
    // +----------------------------------------------+
    // | [PUBLIC]                                     |
    // +----------------------------------------------+
    // {{{ createRootNode()

    /**
    * Creates a new root node
    * Optionally it deletes the whole tree and creates one initial rootnode
    *
    * <pre>
    * +-- root1 [target]
    * |
    * +-- root2 [new]
    * |
    * +-- root3
    * </pre>
    *
    * @param array    $values      Hash with param => value pairs of the node (see $this->params)
    * @param integer  $id          ID of target node (the rootnode after which the node should be inserted)
    * @param bool     $first       Danger: Deletes and (re)init's the hole tree - sequences are reset
    *
    * @access public
    * @return mixed The node id or false on error
    */
    function createRootNode($values, $id = false, $first = false, $_pos = 'AF') {

        if ($this->debug) {
            $this->_debugMessage('createRootNode($values, $id = false, $first = false, $_pos = \'AF\')');
        }

        $this->_verifyUserValues('createRootNode()', $values);

        if(!$first && (!$id || !$parent = $this->pickNode($id, true))) {
            $epr = array('createRootNode()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        } elseif($first && $id) {

            // No notice for now.
            // But tehese 2 params don't make sense together
            $epr = array(
            'createRootNode()',
            '[id] AND [first] were passed - that doesn\'t make sense');
            //$this->_raiseError(NESE_ERROR_WRONG_MPARAM, E_USER_WARNING, $epr);
        }

        // Try to aquire a table lock
        if(PEAR::isError($lock=$this->_setLock())) {
            return $lock;
        }

        $sql = array();
        $addval = array();
        $addval[$this->_flparams['level']] = 1;

        // Shall we delete the existing tree (reinit)
        if ($first) {
            $dsql = sprintf('DELETE FROM %s',
            $this->node_table);
            $this->db->query($dsql);
            $this->db->dropSequence($this->sequence_table);
            // New order of the new node will be 1
            $addval[$this->_flparams['norder']] = 1;
        } else {
            // Let's open a gap for the new node
            if($_pos == NESE_MOVE_AFTER) {
                $addval[$this->_flparams['norder']] = $parent['norder'] + 1;
                $sql[] = sprintf('UPDATE %s SET %s=%s+1 WHERE %s=%s AND %s > %s',
                $this->node_table,
                $this->_flparams['norder'],
                $this->_flparams['norder'],
                $this->_flparams['id'],
                $this->_flparams['rootid'],
                $this->_flparams['norder'],
                $parent['norder']);
            } elseif($_pos == NESE_MOVE_BEFORE) {
                $addval[$this->_flparams['norder']] = $parent['norder'];
                $sql[] = sprintf('UPDATE %s SET %s=%s+1 WHERE %s=%s AND %s >= %s',
                $this->node_table,
                $this->_flparams['norder'],
                $this->_flparams['norder'],
                $this->_flparams['id'],
                $this->_flparams['rootid'],
                $this->_flparams['norder'],
                $parent['norder']);
            }
        }

        if(isset($this->_flparams['parent'])) {
           $addval[$this->_flparams['parent']] = 0;
        }
        // Sequence of node id (equals to root id in this case

        if(!$this->_dumbmode || !$node_id=isset($values[$this->_flparams['id']]) || !isset($values[$this->_flparams['rootid']])) {
            $addval[$this->_flparams['rootid']] = $node_id = $addval[$this->_flparams['id']] = $this->db->nextId($this->sequence_table);
        } else {
            $node_id = $values[$this->_flparams['id']];
        }
        // Left/Right values for rootnodes
        $addval[$this->_flparams['l']] = 1;
        $addval[$this->_flparams['r']] = 2;
        // Transform the node data hash to a query
        if (!$qr = $this->_values2Query($values, $addval)) {
            $this->_releaseLock();
            return false;
        }

        // Insert the new node
        $sql[] = sprintf('INSERT INTO %s SET %s',
        $this->node_table,
        $qr);

        for($i=0;$i<count($sql);$i++) {
            $res = $this->db->query($sql[$i]);
            $this->_testFatalAbort($res, __FILE__,  __LINE__);
        }

        // EVENT (nodeCreate)

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeCreate'])) {
            $this->triggerEvent('nodeCreate', $this->pickNode($node_id));
        }
        $this->_releaseLock();
        return $node_id;
    }

    // }}}
    // {{{ createSubNode()

    /**
    * Creates a subnode
    *
    * <pre>
    * +-- root1
    * |
    * +-\ root2 [target]
    * | |
    * | |-- subnode1 [new]
    * |
    * +-- root3
    * </pre>
    *
    * @param integer    $id          Parent node ID
    * @param array      $values      Hash with param => value pairs of the node (see $this->params)
    *
    * @access public
    * @return mixed The node id or false on error
    */
    function createSubNode($id, $values) {
        if ($this->debug) {
            $this->_debugMessage('createSubNode($id, $values)');
        }

        // invalid parent id, bail out
        if (!($thisnode = $this->pickNode($id, true))) {
            $epr = array('createSubNode()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        }

        // Try to aquire a table lock
        if(PEAR::isError($lock = $this->_setLock())) {
            return $lock;
        }

        $this->_verifyUserValues('createRootNode()', $values);

        // Get the children of the target node
        $children = $this->getChildren($id, true);

        // We have children here
        if ($thisnode['r']-1 != $thisnode['l']) {
            // Get the last child
            $last = array_pop($children);
            // What we have to do is virtually an insert of a node after the last child
            // So we don't have to proceed creating a subnode
            $newNode = $this->createRightNode($last['id'], $values);
            $this->_releaseLock();
            return $newNode;
        }

        $sql = array();
        $sql[] = sprintf('
                UPDATE %s SET
                %s=IF(%s>=%s, %s+2, %s),
                %s=IF(%s>=%s, %s+2, %s)
                WHERE %s=%s',
        $this->node_table,
        $this->_flparams['l'],
        $this->_flparams['l'],
        $thisnode['r'],
        $this->_flparams['l'],
        $this->_flparams['l'],
        $this->_flparams['r'],
        $this->_flparams['r'],
        $thisnode['r'],
        $this->_flparams['r'],
        $this->_flparams['r'],
        $this->_flparams['rootid'],
        $thisnode['rootid']
        );

        $addval = array();
        if(isset($this->_flparams['parent'])) {
           $addval[$this->_flparams['parent']] = $thisnode['id'];
        }

        $addval[$this->_flparams['l']] = $thisnode['r'];
        $addval[$this->_flparams['r']] = $thisnode['r'] + 1;
        $addval[$this->_flparams['rootid']] = $thisnode['rootid'];
        $addval[$this->_flparams['norder']] = 1;
        $addval[$this->_flparams['level']] = $thisnode['level'] + 1;

        if(!$this->_dumbmode || !$node_id=isset($values[$this->_flparams['id']])) {
            $node_id = $addval[$this->_flparams['id']] = $this->db->nextId($this->sequence_table);
        } else {
            $node_id = $values[$this->_flparams['id']];
        }
        if (!$qr = $this->_values2Query($values, $addval)) {
            $this->_releaseLock();
            return false;
        }

        $sql[] = sprintf('INSERT INTO %s SET %s',
        $this->node_table,
        $qr);
        for($i=0;$i<count($sql);$i++) {
            $res = $this->db->query($sql[$i]);
            $this->_testFatalAbort($res, __FILE__,  __LINE__);
        }

        // EVENT (NodeCreate)
        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeCreate'])) {
            $thisnode = $this->pickNode($node_id);
            $this->triggerEvent('nodeCreate', $this->pickNode($id));
        }
        $this->_releaseLock();
        return $node_id;
    }

    // }}}
    // {{{ createLeftNode()
    /**
    * Creates a node before a given node
    * <pre>
    * +-- root1
    * |
    * +-\ root2
    * | |
    * | |-- subnode2 [new]
    * | |-- subnode1 [target]
    * | |-- subnode3
    * |
    * +-- root3
    * </pre>
    *
    * @param int   $id        Target node ID
    * @param array      $values      Hash with param => value pairs of the node (see $this->params)
    * @param bool       $returnID    Tell the method to return a node id instead of an object.
    *                                ATTENTION: That the method defaults to return an object instead of the node id
    *                                has been overseen and is basically a bug. We have to keep this to maintain BC.
    *                                You will have to set $returnID to true to make it behave like the other creation methods.
    *                                This flaw will get fixed with the next major version.
    *
    * @access public
    * @return mixed The node id or false on error
    */
    function createLeftNode($id, $values) {

        if ($this->debug) {
            $this->_debugMessage('createLeftNode($target, $values)');
        }

        $this->_verifyUserValues('createLeftode()', $values);

        // invalid target node, bail out
        if (!($thisnode = $this->pickNode($id, true))) {
            $epr = array('createLeftNode()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        }

        if(PEAR::isError($lock=$this->_setLock())) {
            return $lock;
        }


        // If the target node is a rootnode we virtually want to create a new root node
        if ($thisnode['rootid'] == $thisnode['id']) {
            return $this->createRootNode($values, $id, false, NESE_MOVE_BEFORE);
        }


        $addval = array();
        $parent = $this->getParent($id, true);
        if(isset($this->_flparams['parent'])) {
           $addval[$this->_flparams['parent']] = $parent['id'];
        }

        $sql = array();


        $sql[] = sprintf('UPDATE %s SET %s=%s+1
                        WHERE
                        %s=%s AND %s>=%s AND %s=%s AND %s BETWEEN %s AND %s',
        $this->node_table,
        $this->_flparams['norder'],
        $this->_flparams['norder'],
        $this->_flparams['rootid'],
        $thisnode['rootid'],
        $this->_flparams['norder'],
        $thisnode['norder'],
        $this->_flparams['level'],
        $thisnode['level'],
        $this->_flparams['l'],
        $parent['l'],
        $parent['r']);


        // Update all nodes which have dependent left and right values
        $sql[] = sprintf('
                UPDATE %s SET
                %s=IF(%s>=%s, %s+2, %s),
                %s=IF(%s>=%s, %s+2, %s)
                WHERE %s=%s',
                $this->node_table,
                $this->_flparams['l'],
                $this->_flparams['l'],
                $thisnode['l'],
                $this->_flparams['l'],
                $this->_flparams['l'],
                $this->_flparams['r'],
                $this->_flparams['r'],
                $thisnode['r'],
                $this->_flparams['r'],
                $this->_flparams['r'],
                $this->_flparams['rootid'],
                $thisnode['rootid']
        );



        $addval[$this->_flparams['norder']] = $thisnode['norder'];
        $addval[$this->_flparams['l']] = $thisnode['l'];
        $addval[$this->_flparams['r']] = $thisnode['l']+1;
        $addval[$this->_flparams['rootid']] = $thisnode['rootid'];
        $addval[$this->_flparams['level']] = $thisnode['level'];

        if(!$this->_dumbmode || !$node_id=isset($values[$this->_flparams['id']])) {
            $node_id = $addval[$this->_flparams['id']] = $this->db->nextId($this->sequence_table);
        } else {
            $node_id = $values[$this->_flparams['id']];
        }
        if (!$qr = $this->_values2Query($values, $addval)) {
            $this->_releaseLock();
            return false;
        }

        // Insert the new node
        $sql[] = sprintf('INSERT INTO %s SET %s',
        $this->node_table,
        $qr);

        for($i=0;$i<count($sql);$i++) {
            $res = $this->db->query($sql[$i]);
            $this->_testFatalAbort($res, __FILE__,  __LINE__);
        }

        // EVENT (NodeCreate)
        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeCreate'])) {
            $this->triggerEvent('nodeCreate', $this->pickNode($id));
        }
        $this->_releaseLock();
        return $node_id;
    }

    /**
    * Creates a node after a given node
    * <pre>
    * +-- root1
    * |
    * +-\ root2
    * | |
    * | |-- subnode1 [target]
    * | |-- subnode2 [new]
    * | |-- subnode3
    * |
    * +-- root3
    * </pre>
    *
    * @param int   $id        Target node ID
    * @param array      $values      Hash with param => value pairs of the node (see $this->params)
    * @param bool       $returnID    Tell the method to return a node id instead of an object.
    *                                ATTENTION: That the method defaults to return an object instead of the node id
    *                                has been overseen and is basically a bug. We have to keep this to maintain BC.
    *                                You will have to set $returnID to true to make it behave like the other creation methods.
    *                                This flaw will get fixed with the next major version.
    *
    * @access public
    * @return mixed The node id or false on error
    */
    function createRightNode($id, $values) {

        if ($this->debug) {
            $this->_debugMessage('createRightNode($target, $values)');
        }

        $this->_verifyUserValues('createRootNode()', $values);

        // invalid target node, bail out
        if (!($thisnode = $this->pickNode($id, true))) {
            $epr = array('createRightNode()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        }

        if(PEAR::isError($lock=$this->_setLock())) {
            return $lock;
        }


        // If the target node is a rootnode we virtually want to create a new root node
        if ($thisnode['rootid'] == $thisnode['id']) {

            $nid = $this->createRootNode($values, $id);
            $this->_releaseLock();
            return $nid;
        }


        $addval = array();
        $parent = $this->getParent($id, true);
        if(isset($this->_flparams['parent'])) {
           $addval[$this->_flparams['parent']] = $parent['id'];
        }

        $sql = array();

        $sql[] = sprintf('UPDATE %s SET %s=%s+1
                        WHERE
                        %s=%s AND %s>%s AND %s=%s AND %s BETWEEN %s AND %s',
        $this->node_table,
        $this->_flparams['norder'],
        $this->_flparams['norder'],
        $this->_flparams['rootid'],
        $thisnode['rootid'],
        $this->_flparams['norder'],
        $thisnode['norder'],
        $this->_flparams['level'],
        $thisnode['level'],
        $this->_flparams['l'],
        $parent['l'],
        $parent['r']);


        // Update all nodes which have dependent left and right values


        $sql[] = sprintf('
                UPDATE %s SET
                %s=IF(%s>%s, %s+2, %s),
                %s=IF(%s>%s, %s+2, %s)
                WHERE %s=%s',
        $this->node_table,
        $this->_flparams['l'],
        $this->_flparams['l'],
        $thisnode['r'],
        $this->_flparams['l'],
        $this->_flparams['l'],
        $this->_flparams['r'],
        $this->_flparams['r'],
        $thisnode['r'],
        $this->_flparams['r'],
        $this->_flparams['r'],
        $this->_flparams['rootid'],
        $thisnode['rootid']
        );

        $addval[$this->_flparams['norder']] = $thisnode['norder'] + 1;
        $addval[$this->_flparams['l']] = $thisnode['r'] + 1;
        $addval[$this->_flparams['r']] = $thisnode['r'] + 2;
        $addval[$this->_flparams['rootid']] = $thisnode['rootid'];
        $addval[$this->_flparams['level']] = $thisnode['level'];

        if(!$this->_dumbmode || !isset($values[$this->_flparams['id']])) {
            $node_id = $addval[$this->_flparams['id']] = $this->db->nextId($this->sequence_table);
        } else {
            $node_id = $values[$this->_flparams['id']];
        }
        if (!$qr = $this->_values2Query($values, $addval)) {
            $this->_releaseLock();
            return false;
        }

        // Insert the new node
        $sql[] = sprintf('INSERT INTO %s SET %s', $this->node_table, $qr);

        for($i=0;$i<count($sql);$i++) {
            $res = $this->db->query($sql[$i]);
            $this->_testFatalAbort($res, __FILE__,  __LINE__);
        }

        // EVENT (NodeCreate)
        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeCreate'])) {
            $this->triggerEvent('nodeCreate', $this->pickNode($id));
        }
        $this->_releaseLock();
        return $node_id;
    }

    // }}}
    // {{{ deleteNode()

    /**
    * Deletes a node
    *
    * @param int $id ID of the node to be deleted
    *
    * @access public
    * @return bool True if the delete succeeds
    */
    function deleteNode($id) {

        if ($this->debug) {
            $this->_debugMessage("deleteNode($id)");
        }

        // invalid target node, bail out
        if (!($thisnode = $this->pickNode($id, true))) {
            $epr = array('deleteNode()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        }

        if (PEAR::isError($lock = $this->_setLock())) {
            return $lock;
        }

        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeDelete'])) {
            // EVENT (NodeDelete)
            $this->triggerEvent('nodeDelete', $this->pickNode($id));
        }

        $parent = $this->getParent($id, true);
        $len = $thisnode['r'] - $thisnode['l'] + 1;


        $sql = array();

        // Delete the node
        $sql[] = sprintf('DELETE FROM %s WHERE %s BETWEEN %s AND %s AND %s=%s',
        $this->node_table,
        $this->_flparams['l'],
        $thisnode['l'],
        $thisnode['r'],
        $this->_flparams['rootid'],
        $thisnode['rootid']
        );

        if ($thisnode['id'] != $thisnode['rootid']) {

            // The node isn't a rootnode so close the gap
            $sql[] = sprintf('UPDATE %s SET
                            %s=IF(%s>%s, %s-%s, %s),
                            %s=IF(%s>%s, %s-%s, %s)
                            WHERE %s=%s AND
                            (%s>%s OR %s>%s)',
            $this->node_table,
            $this->_flparams['l'],
            $this->_flparams['l'],
            $thisnode['l'],
            $this->_flparams['l'],
            $len,
            $this->_flparams['l'],
            $this->_flparams['r'],
            $this->_flparams['r'],
            $thisnode['l'],
            $this->_flparams['r'],
            $len,
            $this->_flparams['r'],
            $this->_flparams['rootid'],
            $thisnode['rootid'],
            $this->_flparams['l'],
            $thisnode['l'],
            $this->_flparams['r'],
            $thisnode['r']
            );

            // Re-order

            $sql[] = sprintf('UPDATE %s SET %s=%s-1 WHERE %s=%s AND %s=%s AND %s>%s AND %s BETWEEN %s AND %s',
            $this->node_table,
            $this->_flparams['norder'],
            $this->_flparams['norder'],
            $this->_flparams['rootid'],
            $thisnode['rootid'],
            $this->_flparams['level'],
            $thisnode['level'],
            $this->_flparams['norder'],
            $thisnode['norder'],
            $this->_flparams['l'],
            $parent['l'],
            $parent['r']);

        } else {
            // A rootnode was deleted and we only have to close the gap inside the order
            $sql[] = sprintf('UPDATE %s SET %s=%s+1 WHERE %s=%s AND %s > %s',
            $this->node_table,
            $this->_flparams['norder'],
            $this->_flparams['norder'],
            $this->_flparams['rootid'],
            $this->_flparams['id'],
            $this->_flparams['norder'],
            $thisnode['norder']);
        }
        for($i=0;$i<count($sql);$i++) {
            $res = $this->db->query($sql[$i]);
            $this->_testFatalAbort($res, __FILE__,  __LINE__);
        }
        $this->_releaseLock();
        return true;
    }

    // }}}
    // {{{ updateNode()

    /**
    * Changes the payload of a node
    *
    * @param int    $id Node ID
    * @param array  $values Hash with param => value pairs of the node (see $this->params)
    * @param bool  $_intermal Internal use only. Used to skip value validation. Leave this as it is.
    *
    * @access public
    * @return bool True if the update is successful
    */
    function updateNode($id, $values, $_internal=false) {
        if ($this->debug) {
            $this->_debugMessage('updateNode($id, $values)');
        }

        if (PEAR::isError($lock = $this->_setLock())) {
            return $lock;
        }

        if(!$_internal) {
            $this->_verifyUserValues('createRootNode()', $values);
        }

        $eparams = array('values' => $values);
        if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeUpdate'])) {
            // EVENT (NodeUpdate)
            $this->triggerEvent('nodeUpdate', $this->pickNode($id), $eparams);
        }

        $addvalues = array();
        if (!$qr = $this->_values2Query($values, $addvalues)) {
            $this->_releaseLock();
            return false;
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s = %s',
        $this->node_table,
        $qr,
        $this->_flparams['id'],
        $id);
        $res = $this->db->query($sql);
        $this->_testFatalAbort($res, __FILE__,  __LINE__);
        $this->_releaseLock();
        return true;
    }



    // }}}
    // +----------------------------------------------+
    // | Moving and copying                           |
    // |----------------------------------------------+
    // | [PUBLIC]                                     |
    // +----------------------------------------------+
    // {{{ moveTree()

    /**
    * Wrapper for node moving and copying
    *
    * @param int    $id Source ID
    * @param int    $target Target ID
    * @param constant  $pos Position (use one of the NESE_MOVE_* constants)
    * @param bool   $copy Shall we create a copy
    *
    * @see _moveInsideLevel
    * @see _moveAcross
    * @see _moveRoot2Root
    * @access public
    * @return int ID of the moved node or false on error
    */
    function moveTree($id, $targetid, $pos, $copy = false) {

        if ($this->debug) {
            $this->_debugMessage('moveTree($id, $target, $pos, $copy = false)');
        }
        if($id == $targetid && !$copy) {
            // TRIGGER BOGUS MESSAGE
            return false;
        }

        // Get information about source and target
        if (!($source = $this->pickNode($id, true))) {
            $epr = array('moveTree()', $id);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        }

        if (!($target = $this->pickNode($targetid, true))) {
            $epr = array('moveTree()', $targetid);
            return $this->_raiseError(NESE_ERROR_NOT_FOUND, PEAR_ERROR_TRIGGER, E_USER_ERROR, $epr);
        }

        if (PEAR::isError($lock = $this->_setLock(true))) {
            return $lock;
        }

        $this->_relations = array();
        // This operations don't need callbacks except the copy handler
        // which ignores this setting
        $this->_skipCallbacks = true;

        if(!$copy) {
            // We have a recursion - let's stop
            if (($target['rootid'] == $source['rootid']) &&
            (($source['l'] <= $target['l']) &&
            ($source['r'] >= $target['r']))) {
                $this->_releaseLock(true);
                $epr = array('moveTree()');
                return $this->_raiseError(NESE_ERROR_RECURSION, PEAR_ERROR_RETURN, E_USER_NOTICE, $epr);
            }

            // Insert/move before or after

            if (($source['rootid'] == $source['id']) &&
            ($target['rootid'] == $target['id'])) {
                // We have to move a rootnode which is different from moving inside a tree
                $nid = $this->_moveRoot2Root($source, $target, $pos, $copy);
                $this->_releaseLock(true);
                return $nid;
            }
        } elseif(($target['rootid'] == $source['rootid']) &&
        (($source['l'] < $target['l']) &&
        ($source['r'] > $target['r']))) {
            $this->_releaseLock(true);
            $epr = array('moveTree()');
            return $this->_raiseError(NESE_ERROR_RECURSION, PEAR_ERROR_RETURN, E_USER_NOTICE, $epr);
        }


        // We have to move between different levels and maybe subtrees - let's rock ;)
        $this->_moveAcross($source, $target, $pos);
        $this->_moveCleanup($copy);
        $this->_releaseLock(true);
    }

    // }}}
    // {{{ _moveAcross()

    /**
    * Moves nodes and trees to other subtrees or levels
    *
    * <pre>
    * [+] <--------------------------------+
    * +-[\] root1 [target]                 |
    *     <-------------------------+      |p
    * +-\ root2                     |      |
    * | |                           |      |
    * | |-- subnode1 [target]       |      |B
    * | |-- subnode2 [new]          |S     |E
    * | |-- subnode3                |U     |F
    * |                             |B     |O
    * +-\ root3                     |      |R
    *   |-- subnode 3.1             |      |E
    *   |-\ subnode 3.2 [source] >--+------+
    *     |-- subnode 3.2.1
    *</pre>
    *
    * @param     object NodeCT $source   Source node
    * @param     object NodeCT $target   Target node
    * @param     string    $pos          Position [SUBnode/BEfore]
    * @param     bool         $copy                Shall we create a copy
    *
    * @access    private
    * @see        moveTree
    * @see        _r_moveAcross
    * @see        _moveCleanup
    */
    function _moveAcross($source, $target, $pos) {
        if ($this->debug) {
            $this->_debugMessage('_moveAcross($source, $target, $pos, $copy = false)');
        }

        // Get the current data from a node and exclude the id params which will be changed
        // because of the node move
        $values = array();
        foreach($this->params as $key => $val) {
            if ($source[$val] && !in_array($val, $this->_requiredParams)) {
                $values[$key] = trim($source[$val]);
            }
        }

        switch($pos) {

            case NESE_MOVE_BEFORE:
            $clone_id = $this->createLeftNode($target['id'], $values);
            break;

            case NESE_MOVE_AFTER:
            $clone_id = $this->createRightNode($target['id'], $values);
            break;

            case NESE_MOVE_BELOW:
            $clone_id = $this->createSubNode($target['id'], $values);
            break;
        }


        $children = $this->getChildren($source['id'], true, true, true);


        if ($children) {
            $pos = NESE_MOVE_BELOW;
            $sclone_id = $clone_id;
            // Recurse through the child nodes
            foreach($children AS $cid => $child) {
                $sclone = $this->pickNode($sclone_id, true);
                $sclone_id = $this->_moveAcross($child, $sclone, $pos);

                $pos = NESE_MOVE_AFTER;
            }
        }

        $this->_relations[$source['id']] = $clone_id;
        return $clone_id;
    }

    // }}}
    // {{{ _moveCleanup()

    /**
    * Deletes the old subtree (node) and writes the node id's into the cloned tree
    *
    *
    * @param     array    $relations        Hash in der Form $h[alteid]=neueid
    * @param     array    $copy                     Are we in copy mode?
    * @access    private
    */
    function _moveCleanup($copy = false) {

        $relations = $this->_relations;
        if ($this->debug) {
            $this->_debugMessage('_moveCleanup($relations, $copy = false)');
        }

        $deletes = array();
        $updates = array();
        $tb = $this->node_table;
        $fid = $this->_flparams['id'];
        $froot = $this->_flparams['rootid'];
        foreach($relations AS $key => $val) {
            $clone = $this->pickNode($val);
            if ($copy) {
                // EVENT (NodeCopy)

                $eparams = array('clone' => $clone);

                if (!$this->_skipCallbacks && isset($this->_hasListeners['nodeCopy'])) {
                    $this->triggerEvent('nodeCopy', $this->pickNode($key), $eparams);
                }
                continue;
            }

            // No callbacks here because the node itself doesn't get changed
            // Only it's position
            // If one needs a callback here please let me know

            $deletes[] = $key;
            // It's isn't a rootnode
            if ($clone->id != $clone->rootid) {


                $sql = sprintf('UPDATE %s SET %s=%s WHERE %s = %s',
                $this->node_table,
                $fid,
                $key,
                $fid,
                $val);
                $updates[] = $sql;
            } else {
                $sql = sprintf('UPDATE %s SET %s=%s, %s=%s WHERE %s=%s',
                            $tb,
                            $fid,
                            $key,
                            $froot,
                            $val,
                            $fid,
                            $val);

                $updates[] = $sql;
                $orootid = $clone->rootid;

                $sql = sprintf('UPDATE %s SET %s=%s WHERE %s=%s',
                            $tb,
                            $froot,
                            $key,
                            $froot,
                            $orootid);
                $updates[] = $sql;
            }
            $this->_skipCallbacks = false;
        }

        if(!empty($deletes)) {
            for($i=0;$i<count($deletes);$i++) {
                $this->deleteNode($deletes[$i]);
            }
        }

        if(!empty($updates)) {
            for($i=0;$i<count($updates);$i++) {
                $res = $this->db->query($updates[$i]);
                $this->_testFatalAbort($res, __FILE__, __LINE__);
            }
        }

        return true;
    }

    // }}}
    // {{{ _moveRoot2Root()

    /**
    * Moves rootnodes
    *
    * <pre>
    * +-- root1
    * |
    * +-\ root2
    * | |
    * | |-- subnode1 [target]
    * | |-- subnode2 [new]
    * | |-- subnode3
    * |
    * +-\ root3
    *  [|]  <-----------------------+
    *   |-- subnode 3.1 [target]    |
    *   |-\ subnode 3.2 [source] >--+
    *     |-- subnode 3.2.1
    * </pre>
    *
    * @param     object NodeCT $source    Source
    * @param     object NodeCT $target    Target
    * @param     string $pos              BEfore | AFter
    * @access    private
    * @see        moveTree
    */
    function _moveRoot2Root($source, $target, $pos) {

        if ($this->debug) {
            $this->_debugMessage('_moveRoot2Root($source, $target, $pos, $copy)');
        }
        if(PEAR::isError($lock=$this->_setLock())) {
            return $lock;
        }

        $tb = $this->node_table;
        $fid = $this->_flparams['id'];
        $froot = $this->_flparams['rootid'];
        $freh = $this->_flparams['norder'];
        $s_order = $source['norder'];
        $t_order = $target['norder'];
        $s_id = $source['id'];
        $t_id = $target['id'];


        if ($s_order < $t_order) {
            if ($pos == NESE_MOVE_BEFORE) {
                $sql = "UPDATE $tb SET $freh=$freh-1
                        WHERE $freh BETWEEN $s_order AND $t_order AND
                            $fid!=$t_id AND
                            $fid!=$s_id AND
                            $froot=$fid";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);
                $sql = "UPDATE $tb SET $freh=$t_order -1 WHERE $fid=$s_id";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);
            }
            elseif($pos == NESE_MOVE_AFTER) {

                $sql = "UPDATE $tb SET $freh=$freh-1
                        WHERE $freh BETWEEN $s_order AND $t_order AND
                            $fid!=$s_id AND
                            $froot=$fid";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);

                $sql = "UPDATE $tb SET $freh=$t_order WHERE $fid=$s_id";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);
            }
        }

        if ($s_order > $t_order) {
            if ($pos == NESE_MOVE_BEFORE) {
                $sql = "UPDATE $tb SET $freh=$freh+1
                        WHERE $freh BETWEEN $t_order AND $s_order AND
                            $fid != $s_id AND
                            $froot=$fid";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);

                $sql = "UPDATE $tb SET $freh=$t_order WHERE $fid=$s_id";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);
            }
            elseif ($pos == NESE_MOVE_AFTER) {
                $sql = "UPDATE $tb SET $freh=$freh+1
                        WHERE $freh BETWEEN $t_order AND $s_order AND
                        $fid!=$t_id AND
                        $fid!=$s_id AND
                        $froot=$fid";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);

                $sql = "UPDATE $tb SET $freh=$t_order+1 WHERE $fid = $s_id";
                $res = $this->db->query($sql);
                $this->_testFatalAbort($res, __FILE__, __LINE__);
            }
        }
        $this->_releaseLock();
        return $source->id;
    }

    // }}}
    // +-----------------------+
    // | Helper methods        |
    // +-----------------------+

    // }}}
    // {{{ _secSort()

    /**
    * Callback for uasort used to sort siblings
    *
    * @access private
    */
    function _secSort($node1, $node2) {
        // Within the same level?
        if($node1['level'] != $node2['level']) {
            return strnatcmp($node1['l'], $node2['l']);
        }

        // Are they siblings?
        $p1 = $this->getParent($node1);
        $p2 = $this->getParent($node2);
        if($p1['id'] != $p2['id']) {
            return strnatcmp($node1['l'], $node2['l']);
        }

        // Same field value? Use the lft value then
        $field = $this->params[$this->secondarySort];
        if($node1[$field] == $node2[$field]) {
            return strnatcmp($node1['l'], $node2[l]);
        }

        // Compare between siblings with different field value
        return strnatcmp($node1[$field], $node2[$field]);
    }

    // }}}
    // {{{ _addSQL()

    /**
    * Adds a specific type of SQL to a query string
    *
    * @param array $addSQL The array of SQL strings to add.  Example value:
    *               $addSQL = array(
    *               'cols' => 'tb2.col2, tb2.col3',         // Additional tables/columns
    *               'join' => 'LEFT JOIN tb1 USING(STRID)', // Join statement
    *               'append' => 'GROUP by tb1.STRID');      // Group condition
    * @param string $type The type of SQL.  Can be 'cols', 'join', or 'append'.
    *
    * @access private
    * @return string The SQL, properly formatted
    */
    function _addSQL($addSQL, $type) {
        if (!isset($addSQL[$type])) {
            return '';
        }

        switch($type) {
            case 'cols':
            return ', ' . $addSQL[$type];
            default:
            return $addSQL[$type];
        }
    }

    // }}}
    // {{{ _getSelectFields()

    /**
    * Gets the select fields based on the params
    *
    * @param bool $aliasFields Should we alias the fields so they are the names of the
    *             parameter keys, or leave them as is?
    *
    * @access private
    * @return string A string of query fields to select
    */
    function _getSelectFields($aliasFields) {
        $queryFields = array();
        foreach ($this->params as $key => $val) {
            $tmp_field = $this->node_table . '.' . $key;
            if ($aliasFields) {
                $tmp_field .= ' AS ' . $val;
            }
            $queryFields[] = $tmp_field;
        }

        $fields = implode(', ', $queryFields);
        return $fields;
    }

    // }}}
    // {{{ _processResultSet()

    /**
    * Processes a DB result set by checking for a DB error and then transforming the result
    * into a set of DB_NestedSet_Node objects or leaving it as an array.
    *
    * @param string $sql The sql query to be done
    * @param bool $keepAsArray Keep the result as an array or transform it into a set of
    *             DB_NestedSet_Node objects?
    * @param bool $fieldsAreAliased Are the fields aliased?
    *
    * @access    private
    * @return mixed False on error or the transformed node set.
    */
    function _processResultSet($sql, $keepAsArray, $fieldsAreAliased) {
        $result = $this->db->getAll($sql);
        if ($this->_testFatalAbort($result, __FILE__, __LINE__)) {
            return false;
        }

        $nodes = array();
        $idKey = $fieldsAreAliased ? 'id' : $this->_flparams['id'];
        foreach ($result as $row) {
            $node_id = $row[$idKey];
            if ($keepAsArray) {
                $nodes[$node_id] = $row;
            } else {
                // Create an instance of the node container
                $nodes[$node_id] =& new DB_NestedSet_Node($row);
            }

        }
        return $nodes;
    }

    // }}}
    // {{{ _testFatalAbort()

    /**
    * Error Handler
    *
    * Tests if a given ressource is a PEAR error object
    * ans raises a fatal error in case of an error object
    *
    * @param        object  PEAR::Error $errobj     The object to test
    * @param        string  $file   The filename wher the error occured
    * @param        int     $line   The line number of the error
    * @return   void
    * @access private
    */
    function _testFatalAbort($errobj, $file, $line) {
        if (!$this->_isDBError($errobj)) {
            return false;
        }

        if ($this->debug) {
            $this->_debugMessage('_testFatalAbort($errobj, $file, $line)');
        }
        if ($this->debug) {
            $message = $errobj->getUserInfo();
            $code = $errobj->getCode();
            $msg = "$message ($code) in file $file at line $line";
        } else {
            $msg = $errobj->getMessage();
            $code = $errobj->getCode();     }

            PEAR::raiseError($msg, $code, PEAR_ERROR_TRIGGER, E_USER_ERROR);
    }

    // {{{ __raiseError()

    /**
    * @access private
    */
    function _raiseError($code, $mode, $option, $epr=array()) {
        $message = vsprintf($this->_getMessage($code), $epr);
        return PEAR::raiseError($message, $code, $mode, $option);
    }

    // }}}

    // {{{ addListener()

    /**
    * Add an event listener
    *
    * Adds an event listener and returns an ID for it
    *
    * @param        string $event           The ivent name
    * @param        string  $listener       The listener object
    * @return   string
    * @access public
    */
    function addListener($event, &$listener) {
        $listenerID = uniqid('el');
        $this->eventListeners[$event][$listenerID] =& $listener;
        $this->_hasListeners[$event] = true;
        return $listenerID;
    }

    // }}}
    // {{{ removeListener()

    /**
    * Removes an event listener
    *
    * Removes the event listener with the given ID
    *
    * @param        string $event           The ivent name
    * @param        string  $listenerID     The listener's ID
    * @return   bool
    * @access public
    */
    function removeListener($event, $listenerID) {
        unset($this->eventListeners[$event][$listenerID]);
        if (!isset($this->eventListeners[$event]) ||
        !is_array($this->eventListeners[$event]) ||
        count($this->eventListeners[$event]) == 0) {
            unset($this->_hasListeners[$event]);
        }
        return true;
    }

    // }}}
    // {{{ triggerEvent()

    /**
    * Triggers and event an calls the event listeners
    *
    * @param        string $event   The Event that occured
    * @param        object node $node A Reference to the node object which was subject to changes
    * @param        array $eparams  A associative array of params which may be needed by the handler
    * @return   bool
    * @access public
    */
    function triggerEvent($event, &$node, $eparams = false) {
        if ($this->_skipCallbacks || !isset($this->_hasListeners[$event])) {
            return false;
        }

        foreach($this->eventListeners[$event] as $key => $val) {
            if (!method_exists($val, 'callEvent')) {
                return new PEAR_Error($this->_getMessage(NESE_ERROR_NOHANDLER), NESE_ERROR_NOHANDLER);
            }

            $val->callEvent($event, $node, $eparams);
        }

        return true;
    }

    // }}}
    // {{{ apiVersion()

    function apiVersion() {
        return array(
        'package:'=>$this->_packagename,
        'majorversion'=>$this->_majorversion,
        'minorversion'=>$this->_minorversion,
        'version'=>sprintf('%s.%s',$this->_majorversion, $this->_minorversion),
        'revision'=>str_replace('$', '',"$Revision: 1.56 $")
        );
    }

    // }}}
    // {{{ setAttr()

    /**
    * Sets an object attribute
    *
    * @param        array $attr     An associative array with attributes
    *
    * @return   bool
    * @access public
    */
    function setAttr($attr) {
        static $hasSetSequence;
        if (!isset($hasSetSequence)) {
            $hasSetSequence = false;
        }

        if (!is_array($attr) || count($attr) == 0) {
            return false;
        }

        foreach ($attr as $key => $val) {
            $this->$key = $val;
            if ($key == 'sequence_table') {
                $hasSetSequence = true;
            }

            // only update sequence to reflect new table if they haven't set it manually
            if (!$hasSetSequence && $key == 'node_table') {
                $this->sequence_table = $this->node_table . '_' . $this->_flparams['id'];
            }
            if($key == 'cache' && is_object($val)) {
                $this->_caching = true;
                $GLOBALS['DB_NestedSet'] = & $this;
            }
        }

        return true;
    }

    // }}}
    // {{{ setsortMode()
    /**
    * This enables you to set specific options for each output method
    *
    * @param constant $sortMode
    *
    * @access public
    * @return Current sortMode
    */
    function setsortMode($sortMode=false) {
        if($sortMode && in_array($sortMode, $this->_sortModes)) {
            $this->_sortMode = $sortMode;
        } else {
            return $this->_sortMode;
        }
        return $this->_sortMode;
    }
    // }}}
    // {{{ setDbOption()

    /**
    * Sets a db option.  Example, setting the sequence table format
    *
    * @var string $option The option to set
    * @var string $val The value of the option
    *
    * @access public
    * @return void
    */
    function setDbOption($option, $val) {
        $this->db->setOption($option, $val);
    }

    // }}}
    // {{{ testLock()

    /**
    * Tests if a database lock is set
    *
    * @access public
    */
    function testLock() {
        if ($this->debug) {
            $this->_debugMessage('testLock()');
        }

        if($lockID = $this->_structureTableLock) {
            return $lockID;
        }
        $this->_lockGC();
        $sql = sprintf('SELECT lockID FROM %s WHERE lockTable=%s',
                    $this->lock_table,
                    $this->_quote($this->node_table)) ;

        $res = $this->db->query($sql);
        $this->_testFatalAbort($res, __FILE__, __LINE__);

        if ($this->_numRows($res)) {
            return new PEAR_Error($this->_getMessage(NESE_ERROR_TBLOCKED),NESE_ERROR_TBLOCKED);
        }

        return false;
    }

    // }}}
    // {{{ _setLock()

    /**
    * @access private
    */
    function _setLock($exclusive=false) {
        $lock = $this->testLock();
        if(PEAR::isError($lock)) {
            return $lock;
        }

        if ($this->debug) {
            $this->_debugMessage('_setLock()');
        }
        if($this->_caching) {
            @$this->cache->flush('function_cache');
            $this->_caching = false;
            $this->_restcache = true;
        }

        if (!$lockID = $this->_structureTableLock) {
            $lockID = $this->_structureTableLock = uniqid('lck-');

            $sql = sprintf('INSERT INTO %s SET lockID=%s, lockTable=%s, lockStamp=%s',
            $this->lock_table,
            $this->_quote($lockID),
            $this->_quote($this->node_table),
            time());

        } else {
            $sql = sprintf('UPDATE %s set lockStamp=%s WHERE lockID=%s AND lockTable=%s',
                        $this->lock_table,
                        time(),
                        $this->_quote($lockID),
                        $this->_quote($this->node_table));
        }
        if($exclusive) {
            $this->_lockExclusive = true;
        }

        $res = $this->db->query($sql);
        $this->_testFatalAbort($res, __FILE__, __LINE__);
        return $lockID;
    }

    // }}}
    // {{{ _releaseLock()

    /**
    * @access private
    */
    function _releaseLock($exclusive=false) {
        if ($this->debug) {
            $this->_debugMessage('_releaseLock()');
        }

        if($exclusive) {
            $this->_lockExclusive = false;
        }

        if ((!$lockID = $this->_structureTableLock) || $this->_lockExclusive) {
            return false;
        }

        $tb = $this->lock_table;
        $stb = $this->node_table;
        $sql = "DELETE FROM $tb
                WHERE lockTable=" . $this->_quote($stb) . " AND
                    lockID=" . $this->_quote($lockID);

        $res = $this->db->query($sql);
        $this->_testFatalAbort($res, __FILE__, __LINE__);
        $this->_structureTableLock = false;
        if($this->_restcache) {
            $this->_caching = true;
            $this->_restcache = false;
        }
        return true;
    }

    // }}}
    // {{{ _lockGC()

    /**
    * @access private
    */
    function _lockGC() {
        if ($this->debug) {
            $this->_debugMessage('_lockGC()');
        }
        $tb = $this->lock_table;
        $stb = $this->node_table;
        $lockTTL = time() - $this->lockTTL;
        $sql = "DELETE FROM $tb
                WHERE lockTable=" . $this->_quote($stb) . " AND
                    lockStamp < $lockTTL";

        $res = $this->db->query($sql);
        $this->_testFatalAbort($res, __FILE__, __LINE__);
    }

    // }}}
    // {{{ _values2Query()

    /**
    * @access private
    */
    function _values2Query($values, $addval = false) {

        if ($this->debug) {
            $this->_debugMessage('_values2Query($values, $addval = false)');
        }
        if (is_array($addval)) {
            $values = $values + $addval;
        }

        $arq = array();
        foreach($values AS $key => $val) {
            $k = trim($key);
            $v = trim($val);
            if ($k) {
                // To be used with the next mahor version
                // $iv = in_array($this->params[$k], $this->_quotedParams) ? $this->_quote($v) : $v;
                $iv = $this->_quote($v);
                $arq[] = "$k=$iv";
            }
        }

        if (!is_array($arq) || count($arq) == 0) {
            return false;
        }

        $query = implode(', ', $arq);
        return $query;
    }

    // }}}
    // {{{ _verifyUserValues()

    /**
    * Clean values from protected or unknown columns
    *
    * @var string $caller The calling method
    * @var string $values The values array
    *
    * @access private
    * @return void
    */
    function _verifyUserValues($caller, &$values) {

        if($this->_dumbmode) {
            return true;
        }
        foreach($values AS $field=>$value) {
            if(!isset($this->params[$field])) {
                $epr = array(
                $caller,
                sprintf('Unknown column/param \'%s\'', $field));
                $this->_raiseError(NESE_ERROR_WRONG_MPARAM, PEAR_ERROR_RETURN, E_USER_NOTICE, $epr);
                unset($values[$field]);
            } else {
                $flip = $this->params[$field];
                if(in_array($flip, $this->_requiredParams)) {
                    $epr = array(
                    $caller,
                    sprintf('\'%s\' is autogenerated and can\'t be passed - it will be ignored', $field));
                    $this->_raiseError(NESE_ERROR_WRONG_MPARAM, PEAR_ERROR_RETURN, E_USER_NOTICE, $epr);
                    unset($values[$field]);
                }
            }
        }
    }

    // }}}
    // {{{ _debugMessage()

    /**
    * @access private
    */
    function _debugMessage($msg) {
        if ($this->debug) {
            $time = $this->_getmicrotime();
            echo "$time::Debug:: $msg<br />\n";
        }
    }

    // }}}
    // {{{ _getMessage()

    /**
    * @access private
    */
    function _getMessage($code) {
        if ($this->debug) {
            $this->_debugMessage('_getMessage($code)');
        }
        return isset($this->messages[$code]) ? $this->messages[$code] : $this->messages[NESE_MESSAGE_UNKNOWN];

    }

    // }}}
    // {{{ _getmicrotime()

    /**
    * @access private
    */
    function _getmicrotime() {
        list($usec, $sec) = explode(' ', microtime());
        return ((float)$usec + (float)$sec);
    }

    // }}}
    // {{{ convertTreeModel()

    /**
    * Convert a <1.3 tree into a 1.3 tree format
    *
    * This will convert the tree into a format needed for some new features in
    * 1.3. Your <1.3 tree will still work without converting but some new features
    * like preorder sorting won't work as expected.
    *
    * <pre>
    * Usage:
    * - Create a new node table (tb_nodes2) from the current node table (tb_nodes1) (only copy the structure).
    * - Create a nested set instance of the 'old' set (NeSe1) and one of the new set (NeSe2)
    * - Now you have 2 identical objects where only node_table differs
    * - Call DB_NestedSet::convertTreeModel(&$orig, &$copy);
    * - After that you have a cleaned up copy of tb_nodes1 inside tb_nodes2
    * </pre>
    *
    * @param object DB_NestedSet $orig  Nested set we want to copy
    * @param object DB_NestedSet $copy  Object where the new tree is copied to
    * @param integer $_parent           ID of the parent node (private)
    *
    * @static
    * @access public
    * @return bool True uns success
    */
    function convertTreeModel(&$orig, &$copy, $_parent=false) {

        static $firstSet;

        $isRoot = false;
        if(!$_parent) {
            if(!is_object($orig) || !is_object($copy)) {
                return false;
            }
            if($orig->node_table == $copy->node_table) {
             return false;
            }
            $copy->_dumbmode = true;
            $orig->sortMode = NESE_SORT_LEVEL;
            $copy->sortMode = NESE_SORT_LEVEL;
            $sibl = $orig->getRootNodes(true);
            $isRoot = true;
        } else {
            $sibl = $orig->getChildren($_parent, true);
        }

        if(empty($sibl)) {
            return false;
        }

        foreach($sibl AS $sid=>$sibling) {
            unset($sibling['l']);
            unset($sibling['r']);
            unset($sibling['norder']);

            $values = array();
            foreach($sibling AS $key=>$val) {
                if(!isset($copy->_flparams[$key])) {
                    continue;
                }
                $values[$copy->_flparams[$key]] = $val;
            }

            if(!$firstSet) {
                $psid = $copy->createRootNode($values, false, true);
                $firstSet = true;
            } elseif($isRoot) {
                $psid = $copy->createRightNode($psid, $values);
            } else {
                $copy->createSubNode($_parent, $values);
            }

            DB_NestedSet::convertTreeModel($orig, $copy, $sid);
        }
        return true;
    }
    // }}}
    // {{{ _numRows()
    /**
    * Fetches the number of rows the last query returned
    * @access private
    * @abstract
    */
    function _numRows($res) {
    }
    // }}}
    // {{{ _isDBError()
    /**
    * Returns true if a db return value is an error object
    * @access private
    * @abstract
    */
    function _isDBError($err) {
    }
    // }}}
    // {{{ quote()
    /**
    * Quotes a string to use it inside queries
    * @access private
    * @abstract
    */
    function _quote($str) {
    }

}

// {{{ DB_NestedSet_Node:: class

/**
* Generic class for node objects
*
* @autor Daniel Khan <dk@webcluster.at>;
* @version $Revision: 1.56 $
* @package DB_NestedSet
*
* @access private
*/

class DB_NestedSet_Node {
    // {{{ constructor

    /**
    * Constructor
    */
    function DB_NestedSet_Node($data) {
        if (!is_array($data) || count($data) == 0) {
            return new PEAR_ERROR($data, NESE_ERROR_PARAM_MISSING);
        }

        $this->setAttr($data);
        return true;
    }

    // }}}
    // {{{ setAttr()

    function setAttr($data) {
        if(!is_array($data) || count($data) == 0) {
            return false;
        }

        foreach ($data as $key => $val) {
            $this->$key = $val;
        }
    }

    // }}}

}
// }}}


?>