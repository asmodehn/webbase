<?php

require_once "Math/Vector/Vector.php";
require_once "Math/Vector/Vector2.php";
require_once "Math/Vector/Vector3.php";
require_once "Math/Vector/VectorOp.php";

$v1 = new Math_Vector2(array(1,2));
$v2 = new Math_Vector2(array(2,4));

$w1 = new Math_Vector3(array(2,3,1));
$w2 = new Math_Vector3(array(1,-1,0));
$w3 = new Math_Vector3(array(7,3,2));

echo date("Y-m-d H:i:s")."\n";
echo "==\nVector v1: ".$v1->toString()."\n";
echo "Vector v2: ".$v2->toString()."\n";
$r = Math_VectorOp::add($v1, $v2);
echo "v1 + v2: ".$r->toString()."\n";
$r = Math_VectorOp::substract($v1, $v2);
echo "v1 - v2: ".$r->toString()."\n";
$r = Math_VectorOp::multiply($v1, $v2);
echo "v1 * v2: ".$r->toString()."\n";
$r = Math_VectorOp::divide($v1, $v2);
echo "v1 / v2: ".$r->toString()."\n";
echo "==\nVector w1: ".$w1->toString()."\n";
echo "Vector w2: ".$w2->toString()."\n";
echo "Vector w3: ".$w3->toString()."\n";
$r = Math_VectorOp::scale(2.0, $w1);
echo " 2.0 * w1 = ".$r->toString()."\n";
$r = Math_VectorOp::dotProduct($w1, $w2);
echo "w1 . w2 = $r\n";
$r = Math_VectorOp::crossProduct($w2, $w3);
echo "w2 x w3 = ".$r->toString()."\n";
echo "The triple scalar product of 3 vectors is the volume
of the parallelepiped defined by the vectors. Three coplanar
vectors must give a volume of zero, w1, w2 and w3 are coplanar\n";
$r = Math_VectorOp::tripleScalarProduct($w1, $w2, $w3);
echo "w1 . (w2 x w3) = $r\n";
$z = Math_VectorOp::createOne(3);
echo "Now we introduce z : ".$z->toString()."\n";
echo "and z not being coplanar to w1, w2, or w3:\n";
$r = Math_VectorOp::tripleScalarProduct($z, $w2, $w3);
echo "z * (w2 x w3) = $r\n";
$r = Math_VectorOp::angleBetween($z, $w1);
echo "and the angle between z and w1 is $r radians\n";
echo "which is ".($r * 180.0/M_PI)." degrees\n";

?>
