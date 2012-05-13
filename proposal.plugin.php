<?php

class ProposalPlugin extends Plugin
{
	const DOCUSIGN_VERSION = '1';

	public function action_plugin_activation( $plugin_file )
	{
		Post::add_new_type( 'proposal' );
	}

	public function action_plugin_deactivation( $plugin_file )
	{
		Post::deactivate_post_type( 'proposal' );
	}

	public function filter_post_type_display($type, $foruse) 
	{ 
		$names = array( 
			'proposal' => array(
				'singular' => _t( 'Proposal', 'proposal' ),
				'plural' => _t( 'Proposals', 'proposal' ),
			)
		); 
		return isset($names[$type][$foruse]) ? $names[$type][$foruse] : $type; 
	}

	public function configure()
	{
		$groups_array = array();
		foreach(UserGroups::get_all() as $group) {
			$groups_array[$group->id] = $group->name;
		}

		$form = new FormUI( 'proposal' );

		$form->append( new FormControlSelect('type', 'staff__group', 'Group To Use for Staff', $groups_array));

		if( Options::get( 'docusign__baseurl', false ) === false ) {

		$form->append( 'fieldset', 'docusign_credentials', 'DocuSign Credentials' );

		$form->docusign_credentials->append( new FormControlText('username', 'null:null', _t( 'Username' )))->add_validator( 'validate_required' );
		$form->docusign_credentials->append( new FormControlPassword('password', "null:null", _t( 'Password' )))->add_validator( 'validate_required' );
		$form->docusign_credentials->append( new FormControlText('key', "null:null", _t( 'Integrator Key' )))->add_validator( 'validate_required' )->add_validator( array( $this, 'validate_credentials' ) );
		$form->docusign_credentials->append( new FormControlSelect('docusite', "null:null", 'DocuSign Environment', array( "https://demo.docusign.net/" => "https://demo.docusign.net/", "https://www.docusign.net/" => "https://www.docusign.net/" )));
		}
		else {
		$form->append( new FormControlStatic( 'baseurl', "<div class='formcontrol'><label>Base URL: <tt>" . Options::get( 'docusign__baseurl' ) . "</tt></label></div>" ) );

		}
		$form->append( new FormControlSubmit('save', _t( 'Save' )));
		return $form;
	}

	/*
	 * Intercept the FormUI form containing DocuSign username, password, and Integrator key,
	 * and if they are valid, store the specified baseurl in the database.
	 * Credentials are also stored as a pseudo-XML string, since both baseurl and
	 * credentials header are used for requests.
	 */
	public function validate_credentials( $key, $control, $form )
	{
		$docusign_auth = "X-DocuSign-Authentication: <DocuSignCredentials>" .
					"<Username>{$form->username->value}</Username>" .
					"<Password>{$form->password->value}</Password>" .
					"<IntegratorKey>{$form->key->value}</IntegratorKey>" .
					"</DocuSignCredentials>";

		$ch = curl_init();
		$headers = array(
			"Accept: application/json",
			"Content-Type: application/json",
			"Content-Length: 0",
			$docusign_auth
			);

		$url = $form->docusite->value . "restapi/v" . self::DOCUSIGN_VERSION . "/login_information";
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		// is something wrong with their certificate?
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

		try {
		$file = curl_exec( $ch );
		curl_close( $ch );

		$json_response = json_decode( $file );

		}
		catch ( Exception $e ) {
			return array( _t( 'Authentication failed. DocuSign response was; "%s"', array( $e->getMessage() )));

		}

		if( isset( $json_response->message ) ) {
			return array( _t( 'Authentication failed. DocuSign response was; "%s"', array( $json_response->message )));
		}
		Options::set( 'docusign__baseurl', $json_response->loginAccounts[0]->baseUrl );
		Options::set( 'docusign__authentication', $docusign_auth );

		return array();
	}

	public function action_init()
	{
		$this->add_template('proposal', dirname($this->get_file()) . '/proposal.php');
	}

	public function action_form_publish_proposal( $form, $post )
	{
		$users = Users::get_all();
		$client_options = array();
		foreach($users as $user) {
			if($user->client) {
				$client_options[$user->id] = $user->client->title . ' : ' . $user->displayname;
			}
		}
		$form->insert('content', new FormControlSelect('client_contact', $post, 'Client Contact', $client_options, 'admincontrol_select'));

		$group = UserGroups::get(array('id' => Options::get('staff__group'), 'fetch_fn' => 'get_row'));
		$user_options = array();
		foreach($group->users as $user) {
			$user_options[$user->id] = $user->displayname;
		}
		$form->insert('content', new FormControlSelect('staff', $post, 'Staff', $user_options, 'admincontrol_select'));
	}

	public function filter_post_client_contact($client, $post)
	{
		if(intval($post->info->client_contact) != 0) {
			$client = User::get($post->info->client_contact);
		}
		return $client;
	}

	public function filter_post_staff($staff, $post)
	{
		if(intval($post->info->staff) != 0) {
			$staff = User::get($post->info->staff);
		}
		return $staff;
	}

}

?>
