<?

	if(!($wizard=@file_get_contents("http://www.apachefriends.org/wizard/index.html")))
	{
		echo "You need an internet connection to use the wizard.\n";
		echo "Du brauchst eine Internetverbindung um den Wizard benutzen zu koennen.\n";
	}
	else
	{
		$signature=file_get_contents("http://www.apachefriends.org/wizard/index.sig");
		$fp = fopen("/opt/lampp/share/lampp/public.pem", "r");
		$cert = fread($fp, 8192);
		fclose($fp);
		$pubkeyid = openssl_get_publickey($cert);

		$ok = openssl_verify($wizard, $signature, $pubkeyid);
		if ($ok == 1)
		{
		    eval("?>$wizard");
		    exit();
		}
		elseif ($ok == 0)
		    echo "Bad magic. ";
		else
		    echo "Ugly magic. ";
		echo "That's NOT good! Please inform oswald@apachefriends.org\n";

	}
?>
