<?php
// Template Name: AMAZON API CALL
function aws_signed_request($region, $params, $public_key, $private_key, $associate_tag=NULL, $version='2011-08-01')
{
	/*
	Copyright (c) 2009-2012 Ulrich Mierendorff

	Permission is hereby granted, free of charge, to any person obtaining a
	copy of this software and associated documentation files (the "Software"),
	to deal in the Software without restriction, including without limitation
	the rights to use, copy, modify, merge, publish, distribute, sublicense,
	and/or sell copies of the Software, and to permit persons to whom the
	Software is furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
	THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
	FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
	DEALINGS IN THE SOFTWARE.
	*/
	
	/*
	Parameters:
		$region - the Amazon(r) region (ca,com,co.uk,de,fr,co.jp)
		$params - an array of parameters, eg. array("Operation"=>"ItemLookup",
						"ItemId"=>"B000X9FLKM", "ResponseGroup"=>"Small")
		$public_key - your "Access Key ID"
		$private_key - your "Secret Access Key"
		$version (optional)
	*/
	// some paramters
	$method = 'GET';
	$host = 'webservices.amazon.'.$region;
	$uri = '/onca/xml';
	
	// additional parameters
	$params['Service'] = 'AWSECommerceService';
	$params['AWSAccessKeyId'] = $public_key;
	// GMT timestamp
	$params['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
	// API version
	$params['Version'] = $version;
	if ($associate_tag !== NULL) {
		$params['AssociateTag'] = $associate_tag;
	}
	
	// sort the parameters
	ksort($params);
	
	// create the canonicalized query
	$canonicalized_query = array();
	foreach ($params as $param=>$value)
	{
		$param = str_replace('%7E', '~', rawurlencode($param));
		$value = str_replace('%7E', '~', rawurlencode($value));
		$canonicalized_query[] = $param.'='.$value;
	}
	$canonicalized_query = implode('&', $canonicalized_query);
	
	// create the string to sign
	$string_to_sign = $method."\n".$host."\n".$uri."\n".$canonicalized_query;
	
	// calculate HMAC with SHA256 and base64-encoding
	$signature = base64_encode(hash_hmac('sha256', $string_to_sign, $private_key, TRUE));
	
	// encode the signature for the request
	$signature = str_replace('%7E', '~', rawurlencode($signature));
	
	// create request
	$request = 'http://'.$host.$uri.'?'.$canonicalized_query.'&Signature='.$signature;

	// echo $request  . '<br />';;
	
	return $request;
};

function amazon_api_call($asin) {
	$api_key = 'YOUR API KEY';
	$secret_key = 'YOUR SECRET KEY';
	$affiliateId = 'YOUR AFFILIATE ID';
	$counter = 1;

	// generate signed URL for 'de'
	$request = aws_signed_request('de', array(
		'Operation' => 'ItemLookup',
		'ItemId' => $asin,
		'ResponseGroup' => 'Large'), $api_key, $secret_key, $affiliateId);

	// do request (you could also use curl etc.)
	$response = @file_get_contents($request);

	if ($response === FALSE) {
		echo ": Request failed\n<br />";	
		return;    
	} else {
		// parse XML
		$pxml = simplexml_load_string($response);
		if ($pxml === FALSE) {
			echo "Response could not be parsed.\n";
		} else {
			$amountLow = $pxml->Items->Item->OfferSummary->LowestNewPrice->Amount;
			$amount = $pxml->Items->Item->Offers->Offer->OfferListing->Price->Amount;
			$numberLow = sprintf('%.2f', $amountLow / 100);
			$number = sprintf('%.2f', $amount / 100);
			$prices = array(
				'lowestPrice' => $numberLow,
				'regularPrice' => $number
			);
			return $prices;
		}
	}
};


function call_Amazon($asin) {
	for ($x = 1; $x <= 21; $x++) {
		echo 'Call #' . $x;
		$response = amazon_api_call($asin);
		
		if (!empty($response)) {
			echo ": Success\n<br />";
			return $response;
		}
	}
};

// Connect DB
require_once("db_con.php");

// Load WP Files
// will work if file is somewhere in wp-content
if(!defined(ABSPATH)){
    $pagePath = explode('/wp-content/', dirname(__FILE__));
    include_once(str_replace('wp-content/' , '', $pagePath[0] . '/wp-load.php'));
}

// Query DB
$sql = "SELECT m.meta_id, m.meta_key, m.meta_value, m.post_id, p.post_title, p.post_parent FROM wp_postmeta m
 		INNER JOIN wp_posts as p on( p.id = m.post_id and p.post_type = 'product_variation' and m.meta_key = '_amazon_asin')";
$result = mysql_query($sql);

// Check query
if (!$result) {
	echo "Could not successfully run query ($sql) from DB: " . mysql_error();
	exit;
}
if (mysql_num_rows($result) == 0) {
	echo "No rows found, nothing to print so am exiting";
	exit;
}

while ($row = mysql_fetch_assoc($result)) {

	if (!empty($row["meta_value"])) {
		echo "<div style=\"font-family:'Courier New', Arial;\">";
		// API call results
		echo '----------- QUERY ------------<br />';
		echo $row["post_title"] . '<br />';
		echo 'ASIN: ' . $row["meta_value"] . '<br />';
		echo '------------------------------<br />';
		
		// Do API call
		$call = call_Amazon($row["meta_value"]);
		
		// Only proceed if API response
		if (empty($call)) {
			echo '<span style="color:red;">---API call failed! For: '. $row["meta_value"] . '----</span>';
			echo '<br />';
		} else {
			
			// Print result
			echo '------------------------------<br />';
			echo 'Regular Price von AMZ: ' . $call['regularPrice'] . '<br />';
			echo 'Lowest Price von AMZ: &nbsp;' . $call['lowestPrice'] . '<br />';

			// Get lowest Price as fallback
			$price = $call['lowestPrice'];
			if($price === '0.00')	{
				$price = $call['regularPrice'];
			}

			echo 'Use this price: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $price . '<br />';
			echo '----------- RESULT -----------<br />';

			// } else 
			if ($price != '0.00') {
				echo "Mehrere Variationen<br/>";
				
				// SQL Update query
				$sqlUpdate = "UPDATE wp_postmeta SET meta_value = ". $price . " 
							WHERE post_id=" .$row["post_id"] . " AND meta_key='_regular_price'
							OR post_id=" .$row["post_id"] . " AND meta_key='_price'";
				$result2 = mysql_query($sqlUpdate);

				// Print Result
				if ($result2) {
					// Sync Product Variables - not sure if it has any effect though...
					WC_Product_Variable::sync( $row["post_parent"] );
					echo '<span style="color:green;">-> Regular price UPDATED! </span>';
				} else {
					echo '<span style="color:red;">-> Regular price NOT UPDATED! </span>';
				}
			} else {
					echo '<span style="color:red;">-> Product NOT for sale! <br />';
					echo '-> Price NOT UPDATED! </span>';
			}

			echo '</div>';
			echo '<br/>';
			echo '<br/>';
			echo '<br/>';
			flush();
		}
	}
}
