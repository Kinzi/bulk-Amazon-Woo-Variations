# Bulk-Update-Amazon-Woo-Variations
A script to update prices for woocommerce variations from amazon api with ASIN as identifier. 
* Amazon ASIN must be stored as `_amazon_asin` in postmeta of variation.
* you need to include a `db-connection.php``
* `wp-load.php` is included to call `sync()` if call succeeded. This is needed to update rendered prices on webpage. However I'm not sure if it's working... 
* It can be used as a page template (of a private wordpress page) or run on the server with a CronJob.
Thanks and credits to [Ulrich Mierendorff](http://www.ulrichmierendorff.com/software/aws_hmac_signer.html) for the actual API call function.
