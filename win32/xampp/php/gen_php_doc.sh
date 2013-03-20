#!/bin/bash

phpdoc \
    --filename ../Var_Dump.php,../Var_Dump/Renderer.php \
    --directory ../Var_Dump/Renderer/ \
    --sourcecode on \
    --defaultpackagename Var_Dump \
    --defaultcategoryname PHP \
    --target /tmp/vardump-doc
