#!/usr/bin/php
<?php

chdir(__DIR__);
require('simple_html_dom.php');
require('config.php');

$url = 'https://banking.dkb.de';
define('CSV_HEADER_LINES', 7);
define('CSV_EC_COLUMN_DATE', 0);
define('CSV_EC_COLUMN_DATE2', 1);
define('CSV_EC_COLUMN_SUBJECT1', 3);
define('CSV_EC_COLUMN_SUBJECT2', 4);
define('CSV_EC_COLUMN_VALUE', 7);
define('CSV_CC_COLUMN_DATE', 2);
define('CSV_CC_COLUMN_SUBJECT', 3);
define('CSV_CC_COLUMN_VALUE', 4);

function doCurlPost($action, $data) {
	global $url, $ch;
	
	$lastUri = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if ($lastUri) { curl_setopt($ch, CURLOPT_REFERER, $lastUri); }

	curl_setopt($ch, CURLOPT_URL, $url . $action);
	curl_setopt($ch, CURLOPT_POST, count($data));
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	
	return curl_exec($ch);
}

function doCurlGet($path) {
	global $url, $ch;
	
	$lastUri = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if ($lastUri) { curl_setopt($ch, CURLOPT_REFERER, $lastUri); }

	curl_setopt($ch, CURLOPT_URL, $path);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
	
	return curl_exec($ch);
}

function findLineInCSV($line, $csv) {
	foreach ($csv as $k => $v) {
		if ($k < CSV_HEADER_LINES) continue;
		if ($v == $line) {
			return $k;
		}
	}

	return false;
}

//
// CURL init
//
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIESESSION, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, 'data/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEJAR, 'data/cookie.txt');
//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_CAINFO, 'cacert.pem');

//
// LOGIN
//
echo 'Logging in...';
$result = doCurlGet($url . '/portal/portal/');

$dom = str_get_html($result);
$form = $dom->find('form', 0);

$post_data = array();
foreach ($form->find('input') as $elem) {	
	if ($elem->class == 'il' && $elem->type == 'text') $elem->value = $kto;
	if ($elem->class == 'il' && $elem->type == 'password') $elem->value = $pin;
	
	$post_data[$elem->name] = $elem->value;	
}
$html_ = doCurlPost($form->action, $post_data);

if (strpos($html_, 'Letzte Anmeldung:') !== false) {
	echo "OK!\n";
} else {
	echo 'Error. Login failed!';
	die();
}

//
// get Konten
//
echo "get Konten...\n";
$accounts = array();
$matches = array();

$dom_ = str_get_html($html_);
foreach ($dom_->find('tr[class^=tablerow]') as $k => $row) {
	// switch back to finanzstatus
	if ($k) {
		$href = $dom_->find('a[href*=%2Ffinanzstatus%2F]', 0)->href;
		doCurlGet($url . $href);
	}
	
	// loop
	$post_data = array();
	
	$nr = trim(strip_tags($row->find('td', 0)->find('strong', 0)->plaintext));
	$desc = trim($row->find('td', 1)->find('span', 0)->plaintext);
	echo "  found '$desc' ($nr)";
	$button = $row->find('td', 4)->find('input[value=Umsatzabfrage]', 0);
	$ec = false;
	if ($button) {
		// EC Card (POST)
		$ec = true;
		echo " - is EC";		
		$form = $dom_->find('form', 2);
		
		$e = $form->find('input[type=hidden]', 0);
		$post_data[$e->name] = $e->value;
		$post_data[$button->name] = $button->value;
		$html = doCurlPost($form->action, $post_data);

		// download CSV
		$post_data = array();
		echo " - download CSV";		
		$dom = str_get_html($html);
		$form = $dom->find('form', 1);
		
		$e = $form->find('input[type=hidden]', 0);
		$post_data[$e->name] = $e->value;
		$button = $form->find('input[type=image]', 0);
		$post_data[$button->name] = $button->value;
		
		$dom->clear(); 
		unset($dom);

		$csv = doCurlPost($form->action, $post_data);				
	} else {
		// Credit Card (GET)
		echo " - is CC";
		$href = $row->find('td', 4)->find('a', 0)->href;
		$html = doCurlGet($url . $href);
		$dom = str_get_html($html);
		
		$href = $dom->find('a', 0)->href;
		if ($href) {
			$html = doCurlGet($href);
			$dom = str_get_html($html);
		}
				
		// download CSV
		echo " - download CSV";
		$href = $dom->find('a[href*=event=csvExport]', 0)->href;
		$csv = doCurlGet($url . $href);
	}
	
	$row->clear(); 
	unset($row);
	
	echo "\n";
	$accounts[$nr] = ['desc' => $desc, 'csv' => $csv, 'nr' => $nr, 'type' => $ec?'ec':'cc'];
}

#print_r($accounts);

//
// Logout
//
echo "Logout!\n";
$href = '/dkb/-?$part=DkbTransactionBanking.infobar.logout-button&$event=logout';
$html = doCurlGet($url . $href);
$href = '/dkb/-?$javascript=disabled&$part=Welcome.logout';
$html = doCurlGet($url . $href);

//
// Parse CSV
//
echo "Parse CSV\n";
$push = array();
foreach ($accounts as $account) {
	$cnt = 0;
	$lines = explode("\n", $account['csv']);

	$exists = file_exists($file = __DIR__ . '/data/' . $account['nr']);
	$csv = $exists? file($file, FILE_IGNORE_NEW_LINES) : false;
	file_put_contents($file, $account['csv']);
	if (!$exists) {
		// no push on first run. just save the csv for later comparison
		continue;
	}

	foreach ($lines as $k => $line) {
		if ($k < CSV_HEADER_LINES || !$line) continue;

		$data = explode(';', $line);
		$data = array_map(function($e){return trim($e, '" ><');}, $data);

		$lineNbr = findLineInCSV($line, $csv);
		if ($lineNbr === false) {
			// push
			if (++$cnt >= 5) break; // no more than 5 push messages per account per run
			echo $str = "    new entry: $line\n";
		
			if ($account['type'] == 'ec') {
				// Strip CC data out of Verwendungszweck
				$data[CSV_EC_COLUMN_SUBJECT2] = preg_replace('#(\d{4}) \d{4} \d{4} (\d{4})#', '$1 XXXX XXXX $2', $data[CSV_EC_COLUMN_SUBJECT2]);

				$push[] = array(
					$account['desc'], 
					$data[CSV_EC_COLUMN_DATE], 
					$data[CSV_EC_COLUMN_SUBJECT1] . ' ' . $data[CSV_EC_COLUMN_SUBJECT2],
					$data[CSV_EC_COLUMN_VALUE]
				);
			} else {
				$push[] = array(
					$account['desc'], 
					$data[CSV_CC_COLUMN_DATE], 
					$data[CSV_CC_COLUMN_SUBJECT], 
					$data[CSV_CC_COLUMN_VALUE]
				);
			}
		} else if ($account['type'] == 'cc' || $data[CSV_EC_COLUMN_DATE2]) {
			// in the CSV file of EC cards, predated payments appear on top. Predated payments have an empty CSV_EC_COLUMN_DATE2 column.
			// so we have to always look below them for possibly new transactions
			break;
		}
	}
}

//
// Push
//
echo "PUSH via Boxcar\n";
foreach ($push as $k => $elem) {	
	if ($k && $k%3 == 0) {
		echo "Sleeping..\n";
		sleep(10);
	}
	list($desc, $date, $subject, $value) = $elem;
	$color = $value[0] == '-' ? 'red' : 'green';
	
	$title = $desc . ' ' . $value . ' Euro';
	$message = '<b>'.$date . '</b><br>' . $subject . '<br><br><b style="color:'.$color.'">' . $value . ' Euro</b>'; 
	
	$cmd = 'curl --silent -d "user_credentials='.$boxcar_token.'&notification[title]='.urlencode($title).'&notification[long_message]='.urlencode($message).'&notification[sound]=cash" https://new.boxcar.io/api/notifications';
	echo $cmd;
	echo exec($cmd);
	echo "\n";
}
?>


