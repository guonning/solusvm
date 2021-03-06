<?php
/*
 * SolusVM 
 *
 * Core of the WHMCS module a place where the magic happens
 *
 * @package		SolusVM
 * @category	WHMCS Provisioning Modules
 * @author		Trajche Petrov
 * @link		https://qbit.solutions/
*/
	// load new WHMCS database manager
	use Illuminate\Database\Capsule\Manager as Capsule;

	class SolusVM
	{
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Define Public/Private variables
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private $debug = false;

		public $input;
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Preset CORE variables and init modular enviroment
 * ---------------------------------------------------------------------------------------------------------------------
*/
		function __construct( $INIT = false )
		{
			// preconfigure debug options
			$this->_debug();
			// share database conection between submodules
			$this->_db = Capsule::connection()->getPdo();

			if( $INIT ) // run this only once
			{
				// scan for additional modules
				foreach(glob(_SOLUSVM_ROOT."/lib/modules/*.php") as $file )
				{
					// determine classname based on it's filename
					$class_name = 'SolusVM_'.str_replace('.php','', @end(explode('/',$file)));

					if(!class_exists($class_name)) // check if class exists & if ! load it
					{
						include_once($file); // To-DO: Failback for invalid class name
						$this->{$class_name} = new $class_name();
					}
				}
			}
		}
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Hanlde / Route all incoming requests
 * ---------------------------------------------------------------------------------------------------------------------
*/
		public function __call( $METHOD, $INPUT )
		{
			// clean up method name
			$method_name = $this->_cleanup( $METHOD );

			// VALIDATE TRY TO CALL THE REQUESTED METHOD
			try {
					return $this->_call( $method_name, $INPUT );
			} catch (Exception $error ) {
					$this->_log($method_name, $INPUT, NULL, $error );
			}
		}
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Validate input request 
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _call( $METHOD , $INPUT )
		{
			if( isset($this->$METHOD) )
			{
				if( is_callable( array($this->$METHOD,'_exec') ))
				{
					// pass input for future refenece in the main scope.
					$this->{$METHOD}->input = $INPUT[0];

					// set VM ID in the global input
					$this->_set_vmid( $METHOD );

					// return the output
					return $this->{$METHOD}->_exec($INPUT[0]);
				}
				else
					throw new Exception("The requested method: $METHOD can't be executed.");
			}
			else
				throw new Exception("The requested method: $METHOD don't exist.");
		}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  FILTER * CLEANUP incoming method name 
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _cleanup( $METHOD )
		{
			return str_replace('solusvm','SolusVM',$METHOD);
		}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Load VPS ID & custom options and store them into the global scope
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _set_vmid( $METHOD )
		{
			if(isset($this->{$METHOD}->input['serviceid']) and $METHOD != 'SolusVM_MetaData')
			{
				if(!isset($this->vm_id))
				{
					// Get VPS information
					$query = $this->_db->query("SELECT * FROM mod_solusvm WHERE `service_id` = '{$this->{$METHOD}->input['serviceid']}' LIMIT 1");
					$vps = $query->fetchObject();

					$this->vm_id 		= @$vps->vm_id;
					$this->vm_options 	= @json_decode($vps->options); // decode VPS options in usable format
					
					// check if the service ID have recodr in the solusvm table
					if(!isset($vps->vm_id))
						$this->_log( __FUNCTION__ , 'Can\'t find VPS ID into database', $this->{$METHOD}->input);
				}

				// pass the info into the global scope
				$this->{$METHOD}->input['vm_id'] = $this->vm_id;
				$this->{$METHOD}->input['vm_options'] = $this->vm_options;
			}
		}
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Send API requests to SolusVM master node
 * ---------------------------------------------------------------------------------------------------------------------
*/
		public function _api( $POST = array() )
		{
			try
			{
				if(!empty($POST))
				{
					$url = $this->_get_api_url();

					// set API authentication fields
					$POST['id'] = (isset($POST['id']))? $POST['id'] : $this->input['serverusername'];
					$POST['key'] = (isset($POST['key']))? $POST['key'] : $this->input['serverpassword'];

					// process API responses in JSON
					$POST['rdtype'] = 'json';

					// fetch API data
						$api = $this->_get_data(array
						(
							'url' 	=> $url,
							'post'	=> $POST
						));

						if($this->_handle_api_response($api))
							return $api['data'];
				}
				else
					throw new Exception("Can't send empty API request");

			} catch (Exception $error ) {
				$this->_log( __FUNCTION__, $POST, $api, $error );
			}

		}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Form API url
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _get_api_url()
		{
			$master = $this->_get_master_url(); // get master location

			return "$master/api/admin/command.php";
		}
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Generate MASTER Node access URL
 * ---------------------------------------------------------------------------------------------------------------------
*/
		public function _get_master_url()
		{
			// define HTTP protocol 
			$protocol = ($this->input['serversecure'] == 'on') ? 'https' : 'http';

			// return api URL @To-Do: consider adding option for client requests only
			return "$protocol://{$this->input['serverhostname']}:{$this->input['serverport']}";
		}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Handle API response handle errors etc.. 
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _handle_api_response( $RESPONSE )
		{
			// hanlde connection timeout and other transport errors
			if( $RESPONSE['error_code'] != 0 )
				throw new Exception("Some connection error occured");

			// handle HTTP errors
			if( $RESPONSE['response']['http_code'] != 200 )
				throw new Exception("Invalid HTTP response was returned");


			if( !is_object($RESPONSE['data']) )
				throw new Exception("Mailformed API response was received");

			return true;
		}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Fetch data from remote location 
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _get_data( $DATA )
		{
			// sanitize input url
			$URL = trim(strtok($DATA['url'],"\n"));

			// initialize curl connection
			$conn = curl_init();

			// define default curl options
			$options = array
			(
				CURLOPT_URL => $URL,

				CURLOPT_HEADER => true,
				CURLINFO_HEADER_OUT => true,
				CURLOPT_FRESH_CONNECT => true,

				CURLOPT_CONNECTTIMEOUT => 60,

				CURLOPT_VERBOSE => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,

				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,

				CURLOPT_HTTPHEADER => array // set default request headers 
				(
					'user_agent'	=> 'User-Agent: SolusVM WHMCS API reader',
					'expect'		=> 'Expect:'
				)
			);

			if(isset($DATA['post']) && !empty($DATA['post'])) // send post data 
			{
				$options[CURLOPT_POST] =  true;
				$options[CURLOPT_POSTFIELDS] = http_build_query($DATA['post']);
			}
			
			// send the options to curl
			curl_setopt_array ($conn,$options);

			// GET OUTPUT DATA
			$output['data']	= curl_exec($conn);


			// RETRY TO FETCH DATA ON TIMEOUT 
			$retries = (isset($DATA['timeout_retries']))? $DATA['timeout_retries'] : 3; // determine the number of retries 

			while( curl_errno($conn) == 28 && $retry = __( $retry,  $retries) )
			{
				sleep(5*$retry); // wait for N seconds 
				$output['data']	= curl_exec($conn);
			}

			// SET OUTPUT DATA 
			$output['error_code']		= curl_errno($conn);
			$output['response']			= curl_getinfo($conn);
			$output['response_code']	= $output['response']['http_code'];
			$output['response_header']	= substr($output['data'] , 0, $output['response']['header_size']);

			// separate response data from headers and decode it 
			$output['data'] = json_decode(substr($output['data'],$output['response']['header_size']));

			//To-Do: Handle empty data responses 

			// close the connection
			curl_close($conn);

			return $output;
		}
/*
 * ---------------------------------------------------------------------------------------------------------------------
 *   Log messages and traces into system debug module log
 * ---------------------------------------------------------------------------------------------------------------------
*/
		public function _log( $ACTION , $REQUEST = NULL, $RESPONSE = NULL, $ERROR = NULL, $MESSAGE = '') 
		{
			// DETERMINE LOG INPUT
			$message = ($ERROR !== NULL && $MESSAGE == '')? $ERROR->getMessage() : $MESSAGE;
			// check resposne 
			$response = ($ERROR !== NULL && $RESPONSE == NULL )? $ERROR->getTraceAsString() : $RESPONSE;

			logModuleCall // Record the error in WHMCS's module log.
			(
				get_class($this),
				$ACTION,
				$REQUEST,
				$message,
				$response
			);

			// return error 
			return $message;
		}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Enable disable PHP error reporting and debug logging
 * ---------------------------------------------------------------------------------------------------------------------
*/
		private function _debug()
		{
			if( $this->debug )
			{
				ini_set('display_errors', '1');
				error_reporting(E_ALL);
			}
		}
	}

/*
 * ---------------------------------------------------------------------------------------------------------------------
 *  Initalize the global solusvm opbject
 * ---------------------------------------------------------------------------------------------------------------------
*/
	global 	$solusvm;
			$solusvm = new SolusVM( true );