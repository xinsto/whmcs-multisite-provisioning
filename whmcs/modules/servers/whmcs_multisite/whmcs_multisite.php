<?php
/*
WHMCS Multisite Provisioning Server Module
Plugin Name: WHMCS Multisite Provisioning
Plugin URI: http://premium.wpmudev.org/project/whmcs-multisite-provisioning/
Description: This plugin allows remote control of Multisite provisioning from WHMCS. Includes provisioning for Subdomain, Subdirectory or Domain Mapping Wordpress Multisite installs.
Author: WPMU DEV
Author Uri: http://premium.wpmudev.org/
Text Domain: mrp
Domain Path: languages
Version: 1.2
Network: true
WDP ID: 264
*/
/*  Copyright 2012  Incsub  (http://incsub.com)

Author - Arnold Bailey

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


if ( ! defined( 'WPMU_WHMCS_SERVER_VERSION ' ) ) {
	define( 'WPMU_WHMCS_SERVER_VERSION', '1.2' );
}
function whmcs_multisite_ConfigOptions() {


	# Should return an array of the module options for each product - maximum of 24

	$configarray = array(
		"Default Blog Title"    => array(
			"Type"        => "text",
			"Size"        => "25",
			"Description" => "<br />Title given to the blog if one is not entered in the Title custom field.",
		),
		"Default Blog Domain"   => array(
			"Type"        => "text",
			"Size"        => "25",
			"Description" => "<br />Domain name given to the blog if one is not entered in the Domain custom field.",
		),
		"Use Title field"       => array(
			"Type"        => "yesno",
			"Description" => "Tick if you created a custom Title field."
		),
		"Use Domain field"      => array(
			"Type"        => "yesno",
			"Description" => "Tick if you created a custom Domain field."
		),
		"Default Role"          => array(
			"Type"        => "text",
			"Size"        => "25",
			"Description" => "<br />This is the role that will be assigned to a user created by this product."
		),
		"Web Space Quota"       => array(
			"Type"        => "text",
			"Size"        => "5",
			"Description" => "MB <br />Allowed upload space or leave blank to use WordPress defaults."
		),
		"Product Administrator" => array(
			"Type"        => "text",
			"Size"        => "25",
			"Description" => "<br />Username of the WHMCS Administrator authorizing this product.<br /><b>REQUIRED for the API to function</b>"
		),
	);

	return $configarray;
}

/**
 * Get url content and response headers (given a url, follows all redirections on it
 * and returnes content and response headers of final url)
 *
 * @return array[0]    content
 *                array[1]    array of response headers
 *                array[2]    curl Error message
 */
function get_url( $url, $post_fields, $javascript_loop = 0, $timeout = 30 ) {
	//$url = str_replace( "&amp;", "&", urldecode(trim($url)) );

	if ( $javascript_loop == 0 ) {
		$curl_error = '';
	}

	$cookie = tempnam( "/tmp", "CURLCOOKIE" );
	$ch     = curl_init();
	curl_setopt( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1" );
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookie );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false ); //Follow redirect explicitly to avoid open_basedir and safemode problem
	curl_setopt( $ch, CURLOPT_ENCODING, "" );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_AUTOREFERER, true );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );    // required for https urls
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );    // required for https urls
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_MAXREDIRS, 10 );
	$content  = curl_exec( $ch );
	$response = curl_getinfo( $ch );

	//Save the error
	$curl_error = 0;
	if ( curl_error( $ch ) ) {
		$curl_error = "Connection Error: " . curl_errno( $ch ) . ' - ' . curl_error( $ch );
	}

	curl_close( $ch );

	// If any rediects get the new location
	if ( in_array( $response['http_code'], array( 300, 301, 302, 303, 307 ) ) ) {
		ini_set( "user_agent", "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1" );

		if ( $headers = get_headers( $response['url'] ) ) {
			foreach ( $headers as $value ) {
				if ( preg_match( '#Location: (.+)#i', $value, $match ) ) {
					return get_url( trim( $match[1] ), $post_fields );
				}
			}
		}
	}

	//Check for javascript redirects
	if ( ( preg_match( "/>[[:space:]]+window\.location\.replace\('(.*)'\)/i", $content, $value )
	       || preg_match( "/>[[:space:]]+window\.location\=\"(.*)\"/i", $content, $value ) )
	     && $javascript_loop < 5
	) {
		return get_url( $value[1], $post_fields, $javascript_loop + 1 );
	} else {
		return array( $content, $response, $curl_error );
	}
}

function whmcs_multisite_CreateAccount( $params ) {

	# ** The variables listed below are passed into all module functions **

	$serviceid      = $params["serviceid"]; # Unique ID of the product/service in the WHMCS Database
	$pid            = $params["pid"]; # Product/Service ID
	$producttype    = $params["producttype"]; # Product Type: hostingaccount, reselleraccount, server or other
	$domain         = $params["domain"];
	$username       = $params["username"];
	$password       = $params["password"];
	$clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...
	$customfields   = $params["customfields"]; # Array of custom field values for the product
	$configoptions  = $params["configoptions"]; # Array of configurable option values for the product

	# Product module option settings from ConfigOptions array above
	$configoption1 = $params["configoption1"];
	$configoption2 = $params["configoption2"];
	$configoption3 = $params["configoption3"];
	$configoption4 = $params["configoption4"];
	$configoption5 = $params["configoption5"];
	$configoption6 = $params["configoption6"];

	# Additional variables if the product/service is linked to a server
	$server           = $params["server"]; # True if linked to a server
	$serverid         = $params["serverid"];
	$serverhostname   = $params["serverhostname"];
	$serverip         = $params["serverip"];
	$serverusername   = $params["serverusername"];
	$serverpassword   = $params["serverpassword"];
	$serveraccesshash = $params["serveraccesshash"];
	$serversecure     = $params["serversecure"]; # If set, SSL Mode is enabled in the server config

	# Code to perform action goes here...

	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_client_id'  => $clientsdetails['userid'],
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid']
	);

	// Default Wordpress user name everything before the @ in their whmcs email
	$wp_user_name = explode( '@', $clientsdetails['email'] );
	$wp_user_name = $wp_user_name[0];

	$api_admin = $params["configoption7"];
	if ( empty( $api_admin ) ) {
		return 'Product does not have an authorizing administrator defined. Please update the product module setting.';
	}

	$request = array();

	$request['action'] = 'create';

	// figure out the subdomain/subdirectory or domain mapping
	if ( empty( $params['domain'] ) ) {
		//Has to be either subdomain or subdirectory
		$request['domain'] = ( $params['configoption4'] == 'on' ) ? $customfields['Domain'] : $params['configoption2'];
	} else {
		//Could be domain mapping
		$request['mapped_domain'] = $params['domain'];
		//Or it could be sub
		$sub = explode( '.', $params['domain'] );
		if ( count( $sub > 2 ) ) {
			$request['domain'] = $sub[0];
		} else {
			$request['domain'] = $params['configoption2'];
		}
	}

	if ( $request['domain'] . $request['mapped_domain'] == '' ) {
		return 'Domain field is Empty!';
	}

	$request['title'] = ( $params['configoption3'] == 'on' and ! empty( $customfields['Title'] ) ) ? $customfields['Title'] : $params['configoption1'];

	$request['user_name'] = ( empty( $username ) ) ? $wp_user_name : $username;

	$request['password'] = $password;

	$request['email']        = $clientsdetails['email'];
	$request['last_name']    = $clientsdetails['lastname'];
	$request['first_name']   = $clientsdetails['firstname'];
	$request['default_role'] = $params['configoption5'];
	$request['upload_space'] = $params['configoption6'];
	$request['level']        = $params['configoption8'];
	$request['credentials']  = $credentials;

	$whmcs = array( 'whmcs' => $request );

	$post_fields = http_build_query( $whmcs );

	$url = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url .= $params['serverhostname'];

	$response = get_url( $url, $post_fields );

	$ret = json_decode( $response[0], true );

	logModuleCall( 'WHMCS_Multisite Server ' . WPMU_WHMCS_SERVER_VERSION, 'CreateAccount', [
		'json'       => json_encode( $whmcs, JSON_PRETTY_PRINT ),
		'http'       => $post_fields,
		'var_export' => var_export( $whmcs, true )
	], $response, $ret, array( $credentials['user_password'] ) );

	//return print_r($response,true);

	if ( empty( $ret ) ) {
		if ( ! empty( $response[2] ) ) {
			return 'cURL Error: ' . $response[2];
		}

		return "Invalid data or no response: The receiving plugin may not be activated at: $url";
	}

	if ( is_array( $ret ) && isset( $ret['error'] ) ) {
		$result = $ret['error'] . ":" . $ret['message'];
	} else {
		//Good data so update whmcs


		//Save to database for updates
		insert_query( 'mod_whmcs_multisite',
			array(
				'blog_id'    => intval( $ret['blog_id'] ),
				'service_id' => intval( $params["serviceid"] ),
				'domain'     => $ret['domain'],
				'path'       => $ret['path'],
				'level'      => empty( $ret['level'] ) ? 0 : intval( $ret['level'] ),
			) );

		//Save to service record
		$update = array(
			'serviceid'       => $params["serviceid"],
			'serviceusername' => $ret['user_name'],
		);

		if ( empty( $ret['mapped_domain'] ) ) { //Didn't domain map
			$update['domain'] = $ret['domain'] . $ret['path'];
		} else {
			$update['domain'] = $ret['mapped_domain'];
		}

		if ( ! empty( $ret['password'] ) ) {
			$update['servicepassword'] = $ret['password'];
		}

		$result = localAPI( 'updateclientproduct', $update, $api_admin );

		$result = ( $result['result'] == 'success' ) ? $result['result'] : $result['message'];
	}

	return $result;
}

function get_blog_data( $service_id ) {
	$result = select_query( 'mod_whmcs_multisite', '', array( 'service_id' => $service_id ) );
	$data   = mysql_fetch_array( $result );

	return $data;
}

function whmcs_multisite_TerminateAccount( $params ) {

	# Code to perform action goes here...
	$customfields   = $params["customfields"]; # Array of custom field values for the product
	$clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...

	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_client_id'  => $clientsdetails['userid'],
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid'],
	);

	$data = get_blog_data( $params['serviceid'] );

	$request = array();

	$request['action']      = 'terminate';
	$request['blog_id']     = $data['blog_id'];
	$request['domain']      = $data['domain'];
	$request['credentials'] = $credentials;

	$whmcs = array( 'whmcs' => $request );

	$post_fields = http_build_query( $whmcs );

	$url = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url .= $params['serverhostname'];

	$response = get_url( $url, $post_fields );

	$ret = json_decode( $response[0], true );
	//TODO delete the row from `mod_whmcs_multisite` WHERE service_id = $params['serviceid']
	logModuleCall( 'WHMCS_Multisite Server ' . WPMU_WHMCS_SERVER_VERSION, 'TerminateAccount', $post_fields, $response, $ret, array() );

	if ( empty( $ret ) ) {
		if ( ! empty( $response[2] ) ) {
			return $response[2];
		}

		return "Invalid data: The receiving plugin may not be activated at: $url";
	}

	if ( is_array( $ret ) && isset( $ret['error'] ) ) {
		$result = $ret['error'] . ":" . $ret['message'];
	} else {
		$result = 'success';
	}

	return $result;
}

function whmcs_multisite_SuspendAccount( $params ) {

	# Code to perform action goes here...
	$customfields   = $params["customfields"]; # Array of custom field values for the product
	$clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...

	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_client_id'  => $clientsdetails['userid'],
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid'],
	);

	$data = get_blog_data( $params['serviceid'] );

	$request = array();

	$request['action']      = 'suspend';
	$request['blog_id']     = $data['blog_id'];
	$request['domain']      = $data['domain'];
	$request['credentials'] = $credentials;

	$whmcs = array( 'whmcs' => $request );

	$post_fields = http_build_query( $whmcs );

	$url = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url .= $params['serverhostname'];

	$response = get_url( $url, $post_fields );

	$ret = json_decode( $response[0], true );

	logModuleCall( 'WHMCS_Multisite Server ' . WPMU_WHMCS_SERVER_VERSION, 'SuspendAccount', $post_fields, $response, $ret, array() );

	if ( empty( $ret ) ) {
		if ( ! empty( $response[2] ) ) {
			return $response[2];
		}

		return "Invalid data: The receiving plugin may not be activated at: $url";
	}

	if ( is_array( $ret ) && isset( $ret['error'] ) ) {
		$result = $ret['error'] . ":" . $ret['message'];
	} else {
		$result = 'success';
	}

	return $result;
}

function whmcs_multisite_ChangePackage( $params ) {

	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_client_id'  => $clientsdetails['userid'],
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid'],
	);

	$service_id             = $params['serviceid'];
	$data                   = get_blog_data( $params['serviceid'] );
	$request                = array();
	$request['action']      = 'changepackage';
	$request['blog_id']     = $data['blog_id'];
	$request['domain']      = $data['domain'];
	$request['credentials'] = $credentials;
	$request['level']       = $params['configoption8'];
	$whmcs                  = array( 'whmcs' => $request );
	$post_fields            = http_build_query( $whmcs );
	$url                    = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url                    .= $params['serverhostname'];
	$response               = get_url( $url, $post_fields );
	$ret                    = json_decode( $response[0], true );

	logModuleCall( 'WHMCS_Multisite Server ' . WPMU_WHMCS_SERVER_VERSION, 'ChangePackage', $post_fields, $response, $ret, array() );

	if ( is_array( $ret ) && isset( $ret['error'] ) ) {
		$result = $ret['error'] . ":" . $ret['message'];
	} else {
		$level = empty( $ret['level'] ) ? 0 : intval( $ret['level'] );
		update_query( 'mod_whmcs_multisite', array( 'level' => $level ), array( 'service_id' => $service_id ) );
		$result = 'success';
	}

	return $result;
}

function whmcs_multisite_UnsuspendAccount( $params ) {

	# Code to perform action goes here...
	$customfields   = $params["customfields"]; # Array of custom field values for the product
	$clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...

	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_client_id'  => $clientsdetails['userid'],
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid'],
	);

	$data = get_blog_data( $params['serviceid'] );

	$request = array();

	$request['action']      = 'unsuspend';
	$request['blog_id']     = $data['blog_id'];
	$request['domain']      = $data['domain'];
	$request['credentials'] = $credentials;

	$whmcs = array( 'whmcs' => $request );

	$post_fields = http_build_query( $whmcs );

	$url = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url .= $params['serverhostname'];

	$response = get_url( $url, $post_fields );

	$ret = json_decode( $response[0], true );

	logModuleCall( 'WHMCS_Multisite Server ' . WPMU_WHMCS_SERVER_VERSION, 'UnsuspendAccount', $post_fields, $response, $ret, array() );

	if ( empty( $ret ) ) {
		if ( ! empty( $response[2] ) ) {
			return $response[2];
		}

		return "Invalid data: The receiving plugin may not be activated at: $url";
	}

	if ( is_array( $ret ) && isset( $ret['error'] ) ) {
		$result = $ret['error'] . ":" . $ret['message'];
	} else {
		$result = 'success';
	}

	return $result;
}

function whmcs_multisite_ChangePassword( $params ) {

	# Code to perform action goes here...
	$customfields   = $params["customfields"]; # Array of custom field values for the product
	$clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...
	$configoptions  = $params["configoptions"]; # Array of configurable option values for the product
	$domain         = $params["domain"];
	$username       = $params["username"];
	$password       = $params["password"];

	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_client_id'  => $clientsdetails['userid'],
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid'],
	);

	$data = get_blog_data( $params['serviceid'] );

	$request = array();

	$request['action']      = 'password';
	$request['blog_id']     = $data['blog_id'];
	$request['domain']      = $data['domain'];
	$request['user_name']   = $username;
	$request['password']    = $password;
	$request['email']       = $clientsdetails['email'];
	$request['credentials'] = $credentials;

	$whmcs = array( 'whmcs' => $request );

	$post_fields = http_build_query( $whmcs );

	$url = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url .= $params['serverhostname'];

	$response = get_url( $url, $post_fields );

	$ret = json_decode( $response[0], true );

	logModuleCall( 'WHMCS_Multisite Server ' . WPMU_WHMCS_SERVER_VERSION, 'ChangePassword', $post_fields, $response, $ret, array() );

	if ( empty( $ret ) ) {
		if ( ! empty( $response[2] ) ) {
			return $response[2];
		}

		return "Invalid data: The receiving plugin may not be activated at: $url";
	}

	if ( is_array( $ret ) && isset( $ret['error'] ) ) {
		$result = $ret['error'] . ":" . $ret['message'];
	} else {
		$result = 'success';
	}

	return $result;

}

function whmcs_multisite_AdminLink( $params ) {

	$code = '<form action="http://' . $params["serverhostname"] . '/wp-login.php" method="post" target="wpadmin">
	<input type="hidden" name="log" value="' . $params["serverusername"] . '" />
	<input type="hidden" name="pwd" value="' . $params["serverpassword"] . '" />
	<input type="hidden" name="redirect_to" value="http://' . $params["serverhostname"] . '/wp-admin/" />
	<input type="submit" value="Login to Wordpress" />
	</form>';

	return $code;

}


function whmcs_multisite_LoginLink( $params ) {

	// Lock the username and custom fields for Wordpress Sites since it shouldn't be changed
	?>
	<script type="text/javascript">
		jQuery(document).ready(function () {
			jQuery(
				'input[name="username"],input[name="domain"],input[name^="customfield"],select[name^="packageid"]').attr(
				'readonly',
				'readonly');
		});
	</script>
	<a href="http://<?php echo $params["serverhostname"]; ?>/wp-login.php?log=<?php echo $params["serverusername"] . '&pwd=' . $params['serverpassword']; ?>"
	   class="btn btn-primary" target="wpadmin">Login to Wordpress</a>

	<?php
}

/*
function whmcs_multisite_reboot($params) {

# Code to perform reboot action goes here...

if ($successful) {
$result = "success";
} else {
$result = "Error Message Goes Here...";
}
return $result;

}

function whmcs_multisite_shutdown($params) {

# Code to perform shutdown action goes here...

if ($successful) {
$result = "success";
} else {
$result = "Error Message Goes Here...";
}
return $result;

}


function whmcs_multisite_ClientAreaCustomButtonArray() {
$buttonarray = array(
"Reboot Server" => "reboot",
);
return $buttonarray;
}

function whmcs_multisite_AdminCustomButtonArray() {
$buttonarray = array(
"Reboot Server" => "reboot",
"Shutdown Server" => "shutdown",
);
return $buttonarray;
}

function whmcs_multisite_extrapage($params) {
$pagearray = array(
'templatefile' => 'example',
'breadcrumb' => ' > <a href="#">Example Page</a>',
'vars' => array(
'var1' => 'demo1',
'var2' => 'demo2',
),
);
return $pagearray;
}
*/

//use/** @noinspection PhpUndefinedClassInspection */
/** @noinspection PhpUndefinedNamespaceInspection */
//	WHMCS\Database\Capsule;
use Illuminate\Database\Capsule\Manager as Capsule;

function whmcs_multisite_UsageUpdate( $params ) {

	$serverid    = $params['serverid'];
	$credentials = array(
		'user_login'       => $params['serverusername'],
		'user_password'    => $params['serverpassword'],
		'remember'         => 0,
		'whmcs_service_id' => $params['serviceid'],
		'whmcs_product_id' => $params['pid'],
	);

	$request                = array();
	$request['action']      = 'update_stats';
	$request['credentials'] = $credentials;
	$whmcs                  = array( 'whmcs' => $request );

	$post_fields = http_build_query( $whmcs );

	$url = ( empty( $params['serversecure'] ) ) ? 'http://' : 'https://';
	$url .= $params['serverhostname'];

	$response = get_url( $url, $post_fields );
	if ( 0 !== $response[2] ) {
		//cURL error
		echo $response[2] . PHP_EOL;
	} else {
		//OK
		$list_of_service_ids_and_usage_from_wordpress =
			json_decode( $response[0], JSON_OBJECT_AS_ARRAY )['success'];
		$updatedUserCount                             = 0;
		foreach ( $list_of_service_ids_and_usage_from_wordpress as $service_id => $usage_stats ) {
			try {
				$dt               = new DateTime;
				$count            = Capsule::table( 'tblhosting' )
				                           ->where( 'server', $serverid )
				                           ->where( 'id', $service_id )
				                           ->update(
					                           [
						                           'diskusage'  => $usage_stats['diskusage'],
						                           'disklimit'  => $usage_stats['disklimit'],
						                           'lastupdate' => $dt->format( 'Y-m-d H:i:s' )
					                           ]
				                           );
				$updatedUserCount += $count;
			} catch ( \Exception $e ) {
				echo "I couldn't update client names. {$e->getMessage()}";
			}
		}
		echo "Updated {$updatedUserCount} WordPress Sites Usage Stats" . PHP_EOL;
	}
}