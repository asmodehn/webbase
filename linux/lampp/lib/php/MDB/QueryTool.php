<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Contains the MDB_QueryTool class
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Database
 * @package    MDB_QueryTool
 * @author     Lorenzo Alberton <l dot alberton at quipo dot it>
 * @copyright  2003-2005 Lorenzo Alberton
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    CVS: $Id: QueryTool.php,v 1.7 2005/02/25 16:37:56 quipo Exp $
 * @link       http://pear.php.net/package/MDB_QueryTool
 */

/**
 * require the MDB_QueryTool_EasyJoin class
 */
require_once 'MDB/QueryTool/EasyJoin.php';

/**
 * MDB_QueryTool class
 *
 * This is a port of DB_QueryTool, originally written by Wolfram Kriesing
 * and Paolo Panto, vision:produktion <wk@visionp.de>
 * It uses the PEAR::MDB database abstraction layer
 *
 * @category   Database
 * @package    MDB_QueryTool
 * @author     Lorenzo Alberton <l dot alberton at quipo dot it>
 * @copyright  2003-2005 Lorenzo Alberton
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @link       http://pear.php.net/package/MDB_QueryTool
 */
class MDB_QueryTool extends MDB_QueryTool_EasyJoin
{
    // {{{ MDB_QueryTool()

    /**
     * call parent constructor
     * @param mixed $dsn DSN string, DSN array or MDB object
     * @param array $options
     */
    function MDB_QueryTool($dsn=false, $options=array())
    {
        parent::__construct($dsn, $options);
    }

    // }}}
}
?>