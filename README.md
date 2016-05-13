# Bulk update Woo Variations with Amazon API
A script to update prices of woocommerce variations from amazon api with ASIN as identifier. 
* Amazon ASIN must be stored as `_amazon_asin` in wp_postmeta of variation.
* Script is fired daily with a WP-cron
* Can be fired with custom interval for development
Thanks and credits to [Ulrich Mierendorff](http://www.ulrichmierendorff.com/software/aws_hmac_signer.html) for the actual API call function.
