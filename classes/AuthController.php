<?php defined('SYSPATH') or die('No direct script access.');

/**
 * $description
 *
 * Date: 3/20/13
 * Time: 12:27 AM
 *
 * @author: Mike Wrather
 *
 */

class AuthController extends Controller
{

	// Variables having to do with authentication
	protected $is_logged_in = false;
	protected $user;
	protected $user_roles = array();
	protected $is_admin = false;

	public function __construct(Request $request,Response $response){

		if($request->headers('isnative')) {
			global $isNative;
			$isNative = TRUE;
		}
		parent::__construct($request,$response);
	}
	/**
	 *  populateAuthVars() method will put data from the logged in user to this class's properties
	 */
	protected function populateAuthVars()
	{
		Auth::instance()->auto_login();
		// Check for Logged in User
		$user = Auth::instance()->get_user();

		// FOR TESTING ONLY
	//	if(!$user) $user = ORM::factory('User',425983);

		if(isset($user))
		{
			$this->is_logged_in = true;
			$this->user = ORM::factory('User_Base',$user->id);

			foreach($user->roles->find_all() as $role)
			{
				$this->user_roles[] = $role->name;
			}

			if(in_array("admin",$this->user_roles)) $this->is_admin = true;
		}
	}
}