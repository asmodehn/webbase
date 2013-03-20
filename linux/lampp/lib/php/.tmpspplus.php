<?
if(!extension_loaded('spplus')) {
	dl('modules/spplus.so');
}
echo "spplus loaded.\n";
$clent = '58 6d fc 9c 34 91 9b 86 3f fd 64 63 c9 13 4a 26 ba 29 74 1e c7 e9 80 79';
$codesiret = '00000000000001-01';
$montant = '10.00';
$reference = 'ref123456';
$validite = '31/12/2099';
$taxe = '0.0';
$devise = '978';
$langue = 'FR';

$hmac = calcul_hmac($clent,$codesiret,$montant,$reference,$validite,$taxe,$devise,$langue);
echo "Calcul_hmac: ".$hmac."\n";

$data = 
"siret=$codesiret&reference=$reference&langue=$langue&devise=$devise&montant=$montant&taxe=$taxe&validite=$validite";
$hmac = calculhmac($clent,$data);
echo "Calculhmac: ".$hmac."\n";

$data = "$codesiret$reference$langue$devise$montant$taxe$validite";
$hmac = nthmac($clent,$data);
echo "nthmac: ".$hmac."\n";

$data = 
"https://www.spplus.net/cgis-bin/spdecrypt.exe?siret=$codesiret&reference=$reference&langue=$langue&devise=$devise&montant=$montant&taxe=$taxe&validite=$validite";
$url = signeurlpaiement($clent,$data);
echo "signeurlpaiement: ".$url."\n";
?>
