<?php

require_once "Math/Vector/Vector.php";
require_once "Math/Vector/Vector2.php";
require_once "Math/Vector/Vector3.php";

$a = range(2,4);
$t = new Math_Tuple(array(2,6,8));
echo date("Y-m-d H:i:s")."\n";
echo "==\nVector from array\n";
$v = new Math_Vector($a);
echo $v->toString()."\n";
echo "Original length: ".$v->length()."\n";
echo "Converting to a unit vector\n";
$v->normalize();
echo $v->toString()."\n";
echo "Length after normalizing: ".$v->length()."\n";

echo "Reversing vector\n";
$v->reverse();
echo $v->toString()."\n";

echo "==\nVector from tuple\n";
$w = new Math_Vector($t);
echo "Cartesian distance(v,w) = ".$v->distance($w)."\n";
echo "Manhattan distance(v,w) = ".$v->distance($w, 'manhattan')."\n";
echo "Chessboard distance(v,w) = ".$v->distance($w, 'chessboard')."\n";
echo "Vector v: ".$v->toString()."\n";
echo "Vector w: ".$w->toString()."\n";

echo "==\nVector from another vector\n";
$z = new Math_Vector(new Math_Vector(range(2,5)));
echo $z->toString()."\n";

echo "==\nVector3 vector\n";
$x = new Math_Vector3(new Math_Tuple(array(1,0,1)));
echo $x->toString()."\n";
echo "==\nVector2 vector\n";
$y = new Math_Vector2(array(1,3));
echo $y->toString()."\n";

echo "==\nInvalid vector\n";
$bar = new Math_Vector("foo");
if ($bar->isValid())
	echo "bar is good\n";
else
	echo "bar is bad\n";
print_r($bar);

?>
