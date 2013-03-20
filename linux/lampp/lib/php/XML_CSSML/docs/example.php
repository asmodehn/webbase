<?php
require_once '../CSSML.php';
error_reporting(E_ALL);
$cssml = '<cssml:CSSML xmlns:cssml="http://pear.php.net/cssml/1.0">
  <style browserInclude="not(gecko)" filterInclude="admin">
    <selector>p</selector>
    <declaration property="color">text</declaration>
  </style>
</cssml:CSSML>';
$cssml = new XML_CSSML('libxslt', $cssml, 'string', array('browser' => 'ns4', 'filter' => 'admin', 'comment' => 'hello there!', 'output' => 'foo.css'));
//print_r($cssml);
//echo $cssml->process();
$cssml->process();
?>
