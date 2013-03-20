<?php

require_once "Math/Vector/Tuple.php";

echo "Creating tuple\n";
$t = new Math_Tuple(array(2,3,4));
$t->toString()."\n";
echo "Tuple size: ".$t->getSize()."\n";
echo "Adding element value 7\n";
$t->addElement(7);
echo "Tuple size: ".$t->getSize()."\n";
echo "Tuple toString: ".$t->toString()."\n";
echo "Setting element 1 to 11\n";
$t->setElement(1, 11);
echo "Tuple toString: ".$t->toString()."\n";
echo "Maximum element value: ".$t->getMax()."\n";
echo "Minimum element value: ".$t->getMin()."\n";
echo "Index of maximum value: ".$t->getMaxIndex()."\n";
echo "Index of minimum value: ".$t->getMinIndex()."\n";
echo "Remove element 2\n";
$t->delElement(2);
echo "Tuple toString: ".$t->toString()."\n";
echo "Get element 2: ".$t->getElement(2)."\n";
echo "Get index of element value 11: ".$t->getValueIndex(11)."\n";

?>
