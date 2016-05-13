<?php
/**
 * Plugin Name: Amazon API Call
 * Plugin URI: https://www.webmarken.com
 * Description: Plugin updates prices of woocommerce variations with amazon prices based on ASIN in variation postmeta every 24h 
 * Version: 1.0.0
 * Author: Sebastian Kinzlinger
 * Author URI: https://www.webmarken.com
 * License: GPL2
 */

// create a scheduled event (if it does not exist already)
function cronstarter_activation() {
    if( !wp_next_scheduled( 'amazon-cron' ) ) {  
       wp_schedule_event( time(), 'daily', 'amazon-cron' );  
        // wp_schedule_event( time(), 'everyminute', 'amazon-cron' ); 
    }
}
// and make sure it's called whenever WordPress loads
add_action('wp', 'cronstarter_activation');



// unschedule event upon plugin deactivation
function cronstarter_deactivate() { 
    // find out when the last event was scheduled
    $timestamp = wp_next_scheduled ('amazon-cron');
    // unschedule previous event if any
    wp_unschedule_event ($timestamp, 'amazon-cron');
} 
register_deactivation_hook (__FILE__, 'cronstarter_deactivate');


// add custom interval
/*function cron_add_minute( $schedules ) {
    // Adds once every minute to the existing schedules.
    $schedules['everyminute'] = array(
        'interval' => 50,
        'display' => __( 'Once Every Minute' )
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'cron_add_minute' );*/


// Amazon Api Call
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



// here's the function we'd like to call with our cron job
function amazon_api_function() {
    
    global $wpdb;
    global $emailResult;

    $emailResult = "<h1>This is the result from the Amazon price updates:</h1><br/><br/><br/>";

    // Set up API Call
    function amazon_api_call($asin) {
        global $emailResult;

        $api_key = 'YOUR API KEY';
        $secret_key = 'YOUR SECRET KEY';
        $affiliateId = 'YOUR TRACKING ID';
        $counter = 1;
        // generate signed URL
        $request = aws_signed_request('de', array(
            'Operation' => 'ItemLookup',
            'ItemId' => $asin,
            'ResponseGroup' => 'Large'), $api_key, $secret_key, $affiliateId);

        // do request (you could also use curl etc.)
        $response = @file_get_contents($request);

        if ($response === FALSE) {
            $emailResult .= ": Request failed\n<br />";    
            return;    
        } else {
            // parse XML
            $pxml = simplexml_load_string($response);
            if ($pxml === FALSE) {
                $emailResult .= "Response could not be parsed.\n";
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

    // Call Amazon API up to 20 times
    function call_Amazon($asin) {
        global $emailResult;
        for ($x = 1; $x <= 21; $x++) {
            $emailResult .= 'Call #' . $x;
            $response = amazon_api_call($asin);
            
            if (!empty($response)) {
                $emailResult .= ": Success\n<br />";
                return $response;
            }
        }
    };

    // Query DB
    $result = $wpdb->get_results( "SELECT m.meta_id, m.meta_key, m.meta_value, m.post_id, p.post_title, p.post_parent FROM wp_postmeta m
            INNER JOIN wp_posts as p on( p.id = m.post_id and p.post_type = 'product_variation' and m.meta_key = '_amazon_asin')" );

    foreach($result as $row) {

        $emailResult .= "<div style=\"font-family:'Courier New', Arial;\">";

        if ($row->meta_value) {
            
            // API call results
            $emailResult .= '----------- QUERY ------------<br />';
            $emailResult .= $row->post_title . '<br />';
            $emailResult .= 'ASIN: ' . $row->meta_value . '<br />';
            $emailResult .= '------------------------------<br />';

            // Do API call
            $call = call_Amazon($row->meta_value);

            if (empty($call)) {
                $emailResult .= '<span style="color:red;">---API call failed! For: '. $row->meta_value . '----</span>';
                $emailResult .= '<br />';
            } else {
                // Print result
                $emailResult .= '------------------------------<br />';
                $emailResult .= 'Regular Price von AMZ: ' . $call['regularPrice'] . '<br />';
                $emailResult .= 'Lowest Price von AMZ: &nbsp;' . $call['lowestPrice'] . '<br />';

                 // Get lowest Price as fallback
                $price = $call['lowestPrice'];
                if($price === '0.00')   {
                    $price = $call['regularPrice'];
                }

                $emailResult .= 'Use this price: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $price . '<br />';
                $emailResult .= '----------- RESULT -----------<br />';

                if ($price != '0.00') {

                    // SQL Update query
                    $result2 = $wpdb->query($wpdb->prepare ("UPDATE wp_postmeta SET meta_value = ". $price . " 
                                WHERE post_id=" . $row->post_id . " AND meta_key='_regular_price'
                                OR post_id=" . $row->post_id . " AND meta_key='_price'" ));

                    // Print Result
                    if (FALSE === $result2) {
                        // Sync Product Variables - not sure if it has any effect though...
                        $emailResult .= '<span style="color:red;">-> Regular price NOT UPDATED! </span>';
                    } else {
                        add_action( 'woocommerce_variable_product_sync', $row->post_parent );
                        $emailResult .= '<span style="color:green;">-> Regular price UPDATED! </span>';
                    }
                } else {
                    $emailResult .= '<span style="color:red;">-> Product NOT for sale! <br />';
                    $emailResult .= '-> Price NOT UPDATED! </span>';
                }
            }
            $emailResult .= '</div>';
            $emailResult .= '<br/>';
            $emailResult .= '<br/>';
            $emailResult .= '<br/>';
        }
    }
    
    // components for our email
    $recepients = 'YOUR EMAIL';
    $subject = 'Amazon Prices updated';
    $message = $emailResult;
    $headers = 'Content-Type: text/html; charset=UTF-8';
    
    // let's send it 
    mail($recepients, $subject, $message, $headers);
}

// hook that function onto our scheduled event:
add_action ('amazon-cron', 'amazon_api_function'); 


