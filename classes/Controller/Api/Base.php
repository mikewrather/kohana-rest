<?php defined('SYSPATH') or die('No direct script access.');

/**
 * User: mike
 * Date: 2/16/13
 * Time: 11:13 PM
 */

class Controller_Api_Base extends AuthController
{

	protected $mainModel;

	protected $myID;
	protected $myID2;

	protected $resultArr;

	protected $payloadDesc;

	protected $response_profile;


	// These arrays are populated with data from the body in the case of put or delete requests
	protected $put = array();
	protected $delete = array();
	protected $post = array();
	protected $get = array();

	protected $fatalErrorThrown = false;

	/**
	 * @var $starttime is used to calculate the total execution time for the controller script
	 */
	protected $starttime;

	protected $execErrors = NULL;

	public function __construct(Request $request, Response $response)
	{
		// start the counter
		$this->starttime = microtime();

		// execute parent controller constructor
		parent::__construct($request,$response);

		// this allows custom settings for what response type to return
		$this->setResponseProfile();

		// set IDs passed in the url string (or set to 0 if not available)
		$this->myID = (int)$this->request->param('id') > 0 ? (int)$this->request->param('id') : 0;
		$this->myID2 = (int)$this->request->param('id2') > 0 ? (int)$this->request->param('id2') : 0;

		if(($this->myId == 0) &&
			(is_string($this->request->param('id')) && strlen($this->request->param('id')) > 60))
				$this->myID = $this->request->param('id');

		if(($this->myId2 == 0) &&
			(is_string($this->request->param('id2')) && strlen($this->request->param('id2')) > 60))
			$this->myID2 = $this->request->param('id2');

		if($this->request->is_initial()==false && $this->request->sr_id > 0)
		{
			// This sets the id based on a non-uri based id in a sub-request
			$this->myID = $this->request->sr_id;
		}

		// calls a method to set the user and logged_in properties
		$this->populateAuthVars();

		// Moves the body data to the correct array
		$this->setBodyParams();

		// Check to see if data is stored as json with the "model" key
		// This will return true if that key exists with valid json
		if($this->check_for_json())
		{
			// Here we set the request query and post arrays
			// to hold the data we've stored in this controller's
			// respective arrays.
			// We don't do this for the put and delete because
			// there are already custom methods used for those
			$this->request->query($this->get);
			$this->request->post($this->post);
		}



		//Check to see if we want to save the request
		$this->checkForSave();



	}


	/**
	 * This method checks for a "model" value in the array that holds data for the http verb
	 * If it exists it checks to make sure it contains valid json.  If so, it decodes it
	 * and replaces the array with the data contained in that json
	 */
	protected function check_for_json()
	{
		//Get HTTP Verb
		$verb = strtolower($this->request->method());

		//Check For Model
		if(!$this->$verb('model')) return false;
		if($this->$verb('model') == "") return false;

		$json = json_decode($this->$verb('model'));
		if (json_last_error() == JSON_ERROR_NONE)
		{
			empty($this->$verb);
			$this->$verb = get_object_vars($json);
			if($verb=='post') $this->request->post($this->$verb);
			return true;
		}
		else
		{
			return false;
		}

	}
	/**
	 * @param string $verb is NULL by default and will use the current request's method.  But it can also be set manually.
	 */
	protected function setBodyParams($verb=NULL)
	{

		if($verb === NULL) $verb = strtolower($this->request->method());

		$parseBody = false;
		switch ($verb){
			case 'get':
				$this->get = $this->request->query();
				break;
			case 'post':
				$this->post = $this->request->post();
				break;
			default:
				$bodyStr = (string)$this->request->body();
				$parseBody = true;
				break;
		}

		if($parseBody)
		{
			$arr = explode('&',$bodyStr);
			foreach($arr as $argPair)
			{
				$argArr = explode('=',$argPair);
				$this->$verb($argArr[0],trim(urldecode($argArr[1])));
			}
			if($this->check_for_json())
			{
				//nothing to do here.  But json model key has been moved to main array
			}
		}

	}

	/**
	 * put() gets / sets a value of the put array
	 * @param $arg is the argument you are getting / setting
	 * @param null $val is the optional value you are setting the argument to.
	 * @return array|bool
	 */
	protected function put($arg,$val=NULL)
	{
		if($val !== NULL)
		{
			return $this->put[$arg] = $val;
		}
		else
		{
			if(isset($this->put[$arg])) return $this->put[$arg];
			else return false;
		}
	}

	/**
	 * delete gets / sets a value of the delete array
	 * @param $arg is the argument you are getting / setting
	 * @param null $val is the optional value you are setting the argument to.
	 * @return array|bool
	 */
	protected function delete($arg,$val=NULL)
	{
		if($val !== NULL)
		{
			return $this->delete[$arg] = $val;
		}
		else
		{
			if(isset($this->delete[$arg])) return $this->delete[$arg];
			else return false;
		}
	}

	/**
	 * post() gets / sets a value of the post array
	 * @param $arg is the argument you are getting / setting
	 * @param null $val is the optional value you are setting the argument to.
	 * @return array|bool
	 */
	protected function post($arg,$val=NULL)
	{
		if($val !== NULL)
		{
			return $this->post[$arg] = $val;
		}
		else
		{
			if(isset($this->post[$arg])) return $this->post[$arg];
			else return false;
		}
	}

	/**
	 * get() gets / sets a value of the get array
	 * @param $arg is the argument you are getting / setting
	 * @param null $val is the optional value you are setting the argument to.
	 * @return array|bool
	 */
	protected function get($arg,$val=NULL)
	{
		if($val !== NULL)
		{
			return $this->get[$arg] = $val;
		}
		else
		{
			if(isset($this->get[$arg])) return $this->get[$arg];
			else return false;
		}
	}
	
	public function execute()
	{
		//print_r(debug_backtrace());
		$this->response->body = array();

		// Execute the "before action" method
		$this->before();

		// Determine the action to use
		$action = 'action_' . strtolower($this->request->method()) . '_' . $this->request->action();

		// If the action doesn't exist, it's a 404
		if (!method_exists($this, $action))
		{

			$action_no_verb = 'action_' . $this->request->action();
			if (!method_exists($this, $action_no_verb))
			{
				//throw HTTP_Exception::factory(404,'The requested URL :uri was not found on this server.',$uriArr)->request($this->request);
				// Create Array for Error Data
				$error_array = array(
					"error" => $this->request->action(). " Method Does Not Exist for a ".$this->request->method()." Request. Please check the API documentation.",
					"field" => false,
				);

				// Call method to throw an error
				$this->addError($error_array,true,404);
			}
			else
			{
				$action = $action_no_verb;
			}
		}

		// Execute the action itself
		if(!$this->fatalErrorThrown) //if at any point this property is set to true it will skip this action
			$retObj = $this->{$action}();

		if(!$this->fatalErrorThrown) //if at any point this property is set to true it will skip this action
			$result = (is_object($retObj) && !is_subclass_of($retObj,'Exception')) || is_array($retObj) ?
				$this->getDataFromView($retObj) : $this->getDataFromView();

        $this->response->headers('Access-Control-Allow-Origin: *');
        $this->response->headers('Content-Type','application/json');

		// Initial Request set-up
		if($this->request->is_initial())
		{
			$this->response->body['payload'] = $result;
			$this->response->body['desc'] = $this->payloadDesc;
		}
		// All Internal Sub Requests Set up
		else
		{
			$this->response->body = $result;
		}

		// Execute the "after action" method
		$this->after();

		if($this->request->is_initial())
		{
			$this->response->body = json_encode($this->response->body);
			echo $this->response->body;
		}

		// Return the response
		return $this->response;

	}



	public function after()
	{
		parent::after();
		//right now I'm keeping this here so that I can see the execution time on all subrequests
		if($this->request->is_initial() or 1)
		{
			$req_error = sizeof($this->execErrors) > 0 ? true : false;
			$this->response->body['exec_data'] = array(
				"exec_time" => microtime() - $this->starttime,
				"exec_error" => $req_error,
				"error_array" => $this->execErrors
			);
		}
	}

	protected function setMainModel($model)
	{
		if(!is_object($model))
		{
			$this->execError = "The model provided is not a valid object.";
			return false;
		}
		$this->mainModel = $model;
	}

	/**
	 * popMainModel sets the ID of the main model and then checks to see if it loaded correctly
	 */
	protected function popMainModel()
	{
		if($this->myID)
		{
		//	$enttypes_id = Ent::getMyEntTypeID($this->mainModel);
		//	$is_deleted = ORM::enttypes_is_deleted($enttypes_id, $this->myID);
			$this->mainModel->where('id','=',$this->myID)
		//		->where(DB::expr('0'), '=', $is_deleted)
				->find();
			if(!$this->mainModel->loaded())	unset($this->mainModel);
		}
	}


	 /**
	 * function getDataFromView uses the controller and action called to generate the name of the class and method to call
	 * and then returns the data that that function returns
	 *
	 * @param null $obj
	 * @return array
	 */
	protected function getDataFromView($obj=NULL)
	{
		if($obj==NULL && !isset($this->mainModel)){ return false; }
		elseif($obj==NULL)
		{
			$obj = $this->mainModel;
		}
		$ext = $this->request->controller();

		$vc = Api_Viewclass::factory($ext,$obj,$this->response_profile);
		$method = strtolower($this->request->method())."_".$this->request->action();

		// Passes an parameters accessible to this request to the view class
		if(sizeof($this->request->query()) > 0) $vc->setQueryParams($this->request->query());
		if(sizeof($this->request->post()) > 0) $vc->setPostParams($this->request->post());
		if(sizeof($this->request->body()) > 0) $vc->setPutParams($this->request->body());

		return $vc->$method();
	}

	protected function addError($error_array,$is_fatal=FALSE,$code=406 )
	{

		//format error array to match what Ma is expecting for client side error parsing
		if(!isset($error_array['field']))
		{
			$error_array['field'] = $error_array['param_name'];
			$error_array['error'] = isset($error_array['error']) ? $error_array['error'] : $error_array['param_desc'];
			unset($error_array['param_name']);
			unset($error_array['param_desc']);
		}

		$this->execErrors[] = $error_array;
		if($is_fatal)
		{
			$this->fatalErrorThrown = true;
			$this->response->status($code);
		}
	}

	protected  function processObjectSave(&$model)
	{
		// Error Checking on save()
		try
		{
			$model->save();
		}
		catch(ErrorException $e)
		{
			// Create Array for Error Data
			$error_array = array(
				"error" => "Unable to save ".get_class($model),
				"desc" => $e->getMessage()
			);

			// Set whether it is a fatal error
			$is_fatal = true;

			// Call method to throw an error
			$this->addError($error_array,$is_fatal);
		}
	}

	protected function modelNotSetError($error_array = array())
	{
		print_r(debug_backtrace());
		// Create Array for Error Data
		if (empty($error_array)){
			$error_array = array(
				"error" => get_class($this->mainModel)." object has no ID",
				"desc" => "In order to perform this action, you must set the ID of this object in the request URI"
			);
		}

		// Set whether it is a fatal error
		$is_fatal = true;

		// Call method to throw an error
		$this->addError($error_array,$is_fatal);
	}

	protected function requireID()
	{
		if(!$this->mainModel->loaded()) $this->modelNotSetError();
	}

	public function collect_error_messages($error_arrays){
		$external_errors = array();
		if (isset($error_arrays['_external'])){
			$external_errors = $error_arrays['_external'];
			$error_arrays = array();
		}
		$error_arrays = array_merge($error_arrays, $external_errors);
		$error_desc = implode("\n", $error_arrays);
		return $error_desc;
	}

	protected function processValidationError($errObj,$path = NULL)
	{
		// Use Default Error Message Path if Not Set
		if($path === NULL) $path = $this->mainModel->error_message_path;

		// Extract Errors from Validation Error Object
		$errors = $errObj->errors($path);

		//Check if have the external validation
		if (isset($errors['_external']))
		{
			$external_errors = $errors['_external'];
			$errors = array_merge($errors, $external_errors);
			unset($errors['_external']);
		}
		foreach($errors as $field => $msg)
		{
			// Create Array for Error Data
			$error_array = array(
				"error" => $msg,
				"field" => $field,
			);

			// Set whether it is a fatal error
			$is_fatal = true;

			// Call method to throw an error
			$this->addError($error_array,$is_fatal);
		}
	}

	protected function checkForSave()
	{

		if(!$this->is_admin) return false;

		switch ($this->request->method())
		{
			case 'POST':
				$container = $this->request;
				$verb = 'post';
				break;
			case 'GET':
				$container = $this->request;
				$verb = 'query';
				break;
			case 'PUT':
				$container = $this;
				$verb = 'put';
				break;
			default:
				break;
		}

		if(!isset($verb)) return false;

		$saveReq = $container->{$verb}('saveRequest')=='on' ? 1 : 0;
		if(!$saveReq) return false;

		$saveTitle = $container->{$verb}('saveTitle');
		$apiaccess_id = $container->{$verb}('apiaccess_id');
		$saveBody = str_replace(array('saveRequest','saveTitle','apiaccess_id'),'xxx',$this->request->body());

		$test = ORM::factory('Codegen_Apitest');
		$test->testval = $saveBody;
		$test->name = $saveTitle;
		$test->apiaccess_id = $apiaccess_id;
		$test->save();

	}

	protected function setResponseProfile()
	{

		switch ($this->request->method())
		{
			case 'POST':
				$container = $this->request;
				$verb = 'post';
				break;
			case 'GET':
				$container = $this->request;
				$verb = 'query';
				break;
			case 'PUT':
				$container = $this;
				$verb = 'put';
				break;
			case 'DELETE':
				$container = $this;
				$verb = 'delete';
				break;
			default:
				break;
		}

		if(!isset($verb)) return false;

		$rp_key = $container->{$verb}('response_profile');
		if(!$rp_key) return false;

		$rp_config = Kohana::$config->load('response-profiles');
		if(array_key_exists($rp_key,$rp_config)) $this->response_profile = array("response_profile" => $rp_config[$rp_key]);
		return;
	}

	function is_logged_user(){
		if(!$this->user){
			return false;
		}
		return true;
	}

	function is_admin_user(){
		return $this->is_admin;
	}

	function throw_permission_error($permission_error_code = ""){
		//add more $permission_code to manage all the error message here
		if($permission_error_code == Constant::NOT_OWNER){
			$error_array = array(
				"error" => "Owner required",
				"desc" => "Please make sure your are the owner"
			);
		}else{
			$error_array = array(
				"error" => "This action requires admin permission",
				"desc" => "Please contact your administrator"
			);
		}

		$this->modelNotSetError($error_array);
		return false;
	}

	function throw_authentication_error(){
		$error_array = array(
			"error" => "This action requires authentication",
			"desc" => "Please login first"
		);
		$this->modelNotSetError($error_array);
		return false;
	}

	/*****************************************************************
	 *                UNIVERSAL ACTION METHODS
	 ****************************************************************/
	public function action_post_addtag(){
		$this->payloadDesc = "Add a new tag";
		$arguments = array();
		// CHECK FOR PARAMETERS:
		// subject_type_id (REQUIRED)
		// The ID of the subject type / entity type of the tag's subject (this is a row from the enttypes table)

		if((int)trim($this->request->post('subject_type_id')) > 0)
		{
			$arguments["subject_type_id"] = (int)trim($this->request->post('subject_type_id'));
		}

		if((int)trim($this->request->post('subject_id')) > 0)
		{
			$arguments["subject_id"] = (int)trim($this->request->post('subject_id'));
		}

	/*	if((int)trim($this->request->post('users_id')) > 0)
		{
			$arguments["users_id"] = (int)trim($this->request->post('users_id'));
		}else{
			$arguments["users_id"] = $this->user->id;
		}   */

		$arguments["users_id"] = $this->user->id;


		if(trim($this->request->post('bib_number')) != "")
		{
			$arguments["bib_number"] = (int)trim($this->request->post('bib_number'));
		}

		if((int)trim($this->request->post('media_id')) > 0)
		{
			$arguments["media_id"] = $media_id = (int)trim($this->request->post('media_id'));
		}
		else
		{
			$this->modelNotSetError();
			return;
		}

		if($tag_array = json_decode(trim($this->request->post('tag_array'))))
		{
			Model_Site_Tag::addFromArray($tag_array,$media_id);
			return Model_Media_Base::getTaggedObjects($media_id);
		}
		else
		{
			$result = $this->mainModel->addTag($arguments);
			if(get_class($result) == get_class($this->mainModel))
			{
				return $result;
			}
			elseif(get_class($result) == 'ORM_Validation_Exception')
			{
				//parse error and add to error array
				$this->processValidationError($result,$this->mainModel->error_message_path);
				return false;
			}
		}


	}

	public function action_get_tags()
	{
		$media_id = get_class($this->mainModel) == 'Model_Media_Base' ? $this->mainModel->id : $this->mainModel->media_id;
		return Model_Media_Base::getTaggedObjects($media_id);
	}

	public function action_post_addvote(){
		$this->payloadDesc = "Add a new Vote";
		$arguments = array();

		if((int)trim($this->request->post('subject_type_id')) > 0)
		{
		$arguments["subject_type_id"] = (int)trim($this->request->post('subject_type_id'));
		}

		if((int)trim($this->request->post('subject_id')) > 0)
		{
			$arguments["subject_id"] = (int)trim($this->request->post('subject_id'));
		}
		$arguments['voter_users_id'] = $this->user->id;

		$result = $this->mainModel->addVote($arguments);
		if(get_class($result) == get_class($this->mainModel))
		{
			return $result;
		}
		elseif(get_class($result) == 'ORM_Validation_Exception')
		{
			//parse error and add to error array
			$this->processValidationError($result,$this->mainModel->error_message_path);
			return false;
		}
	}

	public function action_post_addcomment()
	{
		//Must logged user can do action
		if (!$this->is_logged_user()){
			return $this->throw_authentication_error();
		}

		$this->payloadDesc = "Add comments";
		$arguments = array();
		// CHECK FOR PARAMETERS:

		if ($this->mainModel->id){
			$arguments["subject_enttypes_id"] = $ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$arguments["subject_id"] = $subject_id = $this->mainModel->id;
		}

		// The text of the comment
		if(trim($this->request->post('comment')) != "")
		{
			$arguments["comment"] = trim($this->request->post('comment'));
		}

		$arguments['users_id'] = $this->user->id;

		$comment_model = ORM::factory("Site_Comment");
		$result = $comment_model->addComment($arguments);

		if(get_class($result) == get_class($comment_model))
		{
			return $result;
		}
		elseif(get_class($result) == 'ORM_Validation_Exception')
		{
			//parse error and add to error array
			$this->processValidationError($result,$this->mainModel->error_message_path);
			return false;
		}
	}

	public function action_post_addvideo()
	{
		//$this->populateAuthVars();
		//Must logged user can do action
		if (!$this->is_logged_user()){
			return $this->throw_authentication_error();
		}

		$valid_object_types = array(
			"Model_Sportorg_Games_Base",
			"Model_Sportorg_Match",
			"Model_User_Base",
			"Model_User_Resume_Data_Vals",
			"Model_Sportorg_Org",
			"Model_Sportorg_Team",
		);

		if ($this->mainModel->id && in_array(get_class($this->mainModel),$valid_object_types)){
			$arguments["subject_type_id"] = $ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$arguments["subject_id"] = $subject_id = $this->mainModel->id;
		}
		elseif(!in_array(get_class($this->mainModel),$valid_object_types))
		{
			$ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$ent_type = ORM::factory('Site_Enttype',$ent_types_id);

			//create error because the model isn't the correct type
			// Create Array for Error Data
			$error_array = array(
				"error" => "You can't upload a video for a ".$ent_type->name,
			);

			// Set whether it is a fatal error
			$is_fatal = true;

			// Call method to throw an error
			$this->addError($error_array,$is_fatal);
		}
		else
		{
			$this->modelNotSetError();
			return false;
		}

		// cannot send this request because it will try to instantiate video with this model's id
		$req = new Request('/api/video/add');
		$req->method($this->request->method());
		$req->post($this->request->post());
		$req->cookie($this->request->cookie());

		$resp = new Response();

		$video_controller = new Controller_Api_Video($req,$resp);
		$result = $video_controller->action_post_add();

		if(get_class($result) == 'Model_Media_Video')
		{
			$arguments['media_id'] = $result->media_id;
			$tag = ORM::factory('Site_Tag');
			$tag->addTag($arguments);

			return $result;
		}
		elseif(get_class($result) == 'ORM_Validation_Exception')
		{
			//parse error and add to error array
			$this->processValidationError($result,$this->mainModel->error_message_path);
			return false;
		}


	}

	public function action_post_addimage()
	{
		//Must logged user can do action
		if (!$this->is_logged_user()){
			return $this->throw_authentication_error();
		}

		$valid_object_types = array(
			"Model_Sportorg_Games_Base",
			"Model_Sportorg_Match",
			"Model_User_Base",
			"Model_User_Resume_Data_Vals",
			"Model_Sportorg_Org",
			"Model_Sportorg_Team",
		);

		if ($this->mainModel->id && in_array(get_class($this->mainModel),$valid_object_types)){
			$arguments["subject_type_id"] = $ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$arguments["subject_id"] = $subject_id = $this->mainModel->id;
		}
		elseif(!in_array(get_class($this->mainModel),$valid_object_types))
		{
			$ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$ent_type = ORM::factory('Site_Enttype',$ent_types_id);

			//create error because the model isn't the correct type
			// Create Array for Error Data
			$error_array = array(
				"error" => "You can't upload a image for a ".$ent_type->name,
			);

			// Set whether it is a fatal error
			$is_fatal = true;

			// Call method to throw an error
			$this->addError($error_array,$is_fatal);
		}
		else
		{
			$this->modelNotSetError();
			return false;
		}

		// cannot send this request because it will try to instantiate video with this model's id
		$req = new Request('/api/image/add');
		$req->method($this->request->method());
		$req->post($this->request->post());
		$req->cookie($this->request->cookie());

		$resp = new Response();

		$image_controller = new Controller_Api_Image($req,$resp);
		$result = $image_controller->action_post_add();

		if(get_class($result) == 'Model_Media_Image')
		{
			$media_id = $result->media_id;
			$arguments['media_id'] = $media_id;
			$tag = ORM::factory('Site_Tag');
			$tag->addTag($arguments);

			if(strlen($this->request->post('tag')) > 0){ Model_Site_Tag::addFromArray(json_decode($this->request->post('tag')),$media_id); }
			return $result;
		}
		elseif(get_class($result) == 'ORM_Validation_Exception')
		{
			//parse error and add to error array
			$this->processValidationError($result,$this->mainModel->error_message_path);
			return false;
		}
	}

	/**
	 * action_get_followers() Get followers on a specific subject
	 *
	 */
	public function action_get_followers()
	{
		$this->payloadDesc = "Get followers on a specific subject";

		if($this->mainModel->loaded() && !Ent::ent_can_follow($this->mainModel))
		{
			$ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$ent_type = ORM::factory('Site_Enttype',$ent_types_id);

			//create error because the model isn't the correct type
			// Create Array for Error Data
			$error_array = array(
				"error" => "You can't get followers of a(n) ".$ent_type->name,
			);

			// Set whether it is a fatal error
			$is_fatal = true;

			// Call method to throw an error
			$this->addError($error_array,$is_fatal);
			return false;
		}
		elseif(!$this->mainModel->loaded())
		{
			$this->modelNotSetError();
			return false;
		}

		return $followers = Model_User_Followers::get_followers($this->mainModel);

	}

	public function action_get_commentson()
	{
		$comments = Model_Site_Comment::getCommentsOn($this->mainModel);
	//	print_r($comments);
		return $comments;
	}

	public function action_get_comments()
	{
		return $this->action_get_commentson();
	}

	/**
	 * action_post_follow() Get followers on a specific subject
	 *
	 */
	public function action_post_follow()
	{
		// This requires that the user is logged in
		//$this->populateAuthVars();
		//Must logged user can do action
		if (!$this->is_logged_user()){
			return $this->throw_authentication_error();
		}

		$this->payloadDesc = "Become a Follower";

		if($this->mainModel->loaded() && !Ent::ent_can_follow($this->mainModel))
		{
			$ent_types_id = Ent::getMyEntTypeID($this->mainModel);
			$ent_type = ORM::factory('Site_Enttype',$ent_types_id);

			//create error because the model isn't the correct type
			// Create Array for Error Data
			$error_array = array(
				"error" => "You can't follow a(n) ".$ent_type->name,
			);

			// Set whether it is a fatal error
			$is_fatal = true;

			// Call method to throw an error
			$this->addError($error_array,$is_fatal);
			return false;
		}
		elseif(!$this->mainModel->loaded())
		{
			$this->modelNotSetError();
			return false;
		}

//		echo $this->mainModel->id;
//		echo $this->myID;

		$follow = ORM::factory('User_Followers');
		$follow->addFollower($this->user,$this->mainModel,true);
		return $follow;

	}


	public function action_post_flag()
	{
		if (!$this->is_logged_user())
		{
			return $this->throw_authentication_error();
		}

		if(!$this->mainModel->loaded())
		{
			$this->modelNotSetError();
			return false;
		}

		$flag = ORM::factory('Site_Flag');
		$result = $flag->addFlag($this->mainModel);
		if(get_class($result) == get_class($this->mainModel))
		{
			return $result;
		}
		elseif(get_class($result) == 'ORM_Validation_Exception')
		{
			//parse error and add to error array
			$this->processValidationError($result,$this->mainModel->error_message_path);
			return false;
		}
	}

	public function action_get_media()
	{

		if(!$this->mainModel->id)
		{
			$this->modelNotSetError();
			return false;
		}

		if((int)trim($this->request->query('sports_id')) > 0)
		{
			$sports_id = $arguments["sports_id"] = (int)trim($this->request->query('sports_id'));
		}
		elseif((int)trim($this->request->query('sport_id')) > 0)
		{
			$sports_id = $arguments["sports_id"] = (int)trim($this->request->query('sport_id'));
		}
		else
		{
			$sports_id = null;
		}

		$limit = null;
		if((int)trim($this->request->query('limit')) > 0) $limit = trim($this->request->query('limit'));

		$offset = 0;
		if((int)trim($this->request->query('offset')) > 0) $offset = trim($this->request->query('offset'));

		$media = ORM::factory('Media_Base');
		return $media->getTaggedMedia($this->mainModel, $sports_id,$limit,$offset);
	}

}