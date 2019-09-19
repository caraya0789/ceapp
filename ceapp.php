<?php
/*
Plugin Name: Color Expression App
Plugin URI: http://ceapp.loc
Description: Adds features to the WordPress API to support the Color Expression App
Author: Cristian Araya
Version: 0.1.0
Author URI: https://codeskill.io
*/

class CE_App {

	protected static $instance;

	public static function get_instance() {
		if( null === self::$instance )
			self::$instance = new self();

		return self::$instance;
	}

	public function hooks() {
		add_action( 'rest_api_init', [ $this, 'endpoints' ] );
		add_filter( 'wp_mail_from_name', [ $this, 'sender_name' ] );
		// User columns
		add_filter( 'manage_users_columns', [ $this, 'add_user_columns' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'fill_user_columns' ], 10, 3 );
	}

	public function sender_name( $original ) {
		return 'App Color Expression';
	}

	public function endpoints() {
		register_rest_route( 'ceapp/v1', 'users/new', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [ $this, 'create_user' ]
		]);

		register_rest_route( 'ceapp/v1', 'users/recover', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [ $this, 'recover_password' ]
		]);

		register_rest_route( 'ceapp/v1', 'users/me/colors', [[
			'methods' => WP_REST_Server::READABLE,
			'callback' => [ $this, 'get_colors' ]
		],[
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [ $this, 'add_color' ]
		],[
			'methods' => WP_REST_Server::DELETABLE,
			'callback' => [ $this, 'remove_color' ]
		]]);

		register_rest_field( 'user', 'country', [
			'get_callback'  	=> [ $this, 'get_user_meta' ],
	        'update_callback'   => [ $this, 'update_user_meta' ],
	        'schema'            => null,
		]);

		register_rest_field( 'user', 'extra_text', [
			'get_callback'  	=> [ $this, 'get_user_meta' ],
	        'update_callback'   => [ $this, 'update_user_meta' ],
	        'schema'            => null,
		]);

		register_rest_field( 'user', 'image', [
			'get_callback'  	=> [ $this, 'get_user_meta' ],
	        'update_callback'   => [ $this, 'upload_user_image' ],
	        'schema'            => null,
		]);
	}

	public function create_user( WP_REST_Request $request ) {
		$email = $request->get_param('email');
		$passw = $request->get_param('password');
		$name = $request->get_param('name');

		if( email_exists( $email ) ) 
			return new WP_Error('user_exists', 'Ya existe un usuario con este email', [ 'status' => 500 ]);

		$user_id = username_exists( $email );
		if($user_id) 
			return new WP_Error('user_exists', 'Ya existe un usuario con este email', [ 'status' => 500 ]);

		$user_id = wp_create_user( $email, $passw, $email );

		wp_update_user([
			'ID' => $user_id,
			'first_name' => $name,
			'display_name' => $name,
		]);

		return get_userdata( $user_id );
	}

	public function recover_password( WP_REST_Request $request ) {
		$errors = new WP_Error();
		
		$email = $request->get_param('email');
		$user_data = get_user_by( 'email', $email );

		do_action( 'lostpassword_post', $errors );

		if ( $errors->has_errors() ) 
			return $errors;

		if ( ! $user_data ) {
			$errors->add( 'invalidcombo', __( '<strong>ERROR</strong>: There is no account with that username or email address.' ) );
			return $errors;
		}

		// Redefining user_login ensures we return the right case in the email.
		$user_login   = $user_data->user_login;
		$user_email   = $user_data->user_email;
		$key          = get_password_reset_key( $user_data );
		$new_password = wp_generate_password( 8, false );

		if ( is_wp_error( $key ) ) 
			return $key;

		$site_name = ( is_multisite() ) ? get_network()->site_name : wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$message = __( '¡Hola!' ) . "\r\n\r\n";
		$message .= __( 'Hemos restablecido tu contraseña de acceso al app de Color Expression.' ) . "\r\n\r\n";
		// $message .= sprintf( __( 'App: %s' ), $site_name ) . "\r\n\r\n";
		// $message .= sprintf( __( 'Email: %s' ), $user_login ) . "\r\n\r\n";
		$message .= sprintf( __( 'Tu nueva contraseña es: %s' ), $new_password ) . "\r\n\r\n";
		$message .= __( 'Puedes cambiar tu contraseña ingresando a Mi Perfil > Configuración > Cambiar contraseña.' ) . "\r\n\r\n";
		$message .= __( 'Atentamente,' ) . "\r\n\r\n";
		$message .= __( 'El equipo de Color Expression by Lanco.' ) . "\r\n\r\n";

		$title = __( 'Nueva Contraseña - Color Expression by Lanco' );

		if ( $message && ! wp_mail( $user_email, wp_specialchars_decode( $title ), $message ) ) 
			return new WP_Error('email_not_sent', 'El Email no pudo ser enviado', [ 'status' => 500 ]);

		wp_set_password( $new_password, $user_data->ID );

		return [
			'code' => 'email_sent',
			'message' => 'El Email ha sido enviado',
			'data' => [
				'status' => 200
			]
		];
	}

	protected function _upload_image( $data, $user_id ) {
		if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {

		    $data = substr($data, strpos($data, ',') + 1);
		    $type = strtolower($type[1]); // jpg, png, gif

		    if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) 
		    	return new WP_Error('invalid_image_type', 'La imagen debe ser jpg, jpeg, gif o png', [ 'status' => 500 ]);

		    $data = base64_decode($data);

		    if ($data === false) 
		    	return new WP_Error('invalid_image', 'No se pudo decodificar la imagen correctamente', [ 'status' => 500 ]);

		    $upload_dir = wp_upload_dir();

			$file_name = 'colors_'.$user_id.'_'.md5($user_id . '_' . time() . '_' . $type . '_' . rand()) . '.' . $type;

			file_put_contents($upload_dir['path'] . '/' . $file_name, $data);

			return $upload_dir['url'] . '/' . $file_name;

		}
		return new WP_Error('invalid_image', 'No se pudo decodificar la imagen correctamente', [ 'status' => 500 ]);
	}

	public function add_color( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if(!$user_id) 
			return new WP_Error('user_not_found', 'No se encontró ningún usuario', [ 'status' => 500 ]);

		$params = $request->get_body_params();
		if(count($params['colors']) == 0) 
			$params = $request->get_json_params();

		if(count($params['colors']) == 0) 
			return new WP_Error('color_not_in_request', 'No se encontró ningún color para agregar al usuario', [ 'status' => 500 ]);

		$colors = get_user_meta( $user_id, 'colors', true );

		if(!$colors)
			$colors = array();

		foreach($params['colors'] as $color) {
			if($color['image']) {
				// Upload image
				$url = $this->_upload_image($color['image'], $user_id);
				if(is_wp_error($url)) 
					return $url;

				$color['image'] = $url;				
			}

			$colors[] = $color;
		}

		update_user_meta( $user_id, 'colors', $colors );

		return [
			'code' => 'success',
			'message' => 'Colores guardados exitosamente',
			'data' => [
				'status' => 200,
				'colors' => $colors
			]
		];
	}

	public function get_colors() {
		$user_id = get_current_user_id();
		if(!$user_id) 
			return new WP_Error('user_not_found', 'No se encontró ningún usuario', [ 'status' => 500 ]);

		$colors = get_user_meta( $user_id, 'colors', true );

		if(!$colors)
			$colors = array();

		return $colors;
	}

	public function remove_color( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if(!$user_id) 
			return new WP_Error('user_not_found', 'No se encontró ningún usuario', [ 'status' => 500 ]);

		$index = $request->get_param('index');
		if(!$index || !is_numeric($index))
			return new WP_Error('missing_index', 'No se encontró el index a eliminar o no es válido', [ 'status' => 500 ]);

		$colors = get_user_meta( $user_id, 'colors', true );

		$index = (int) $index;
		if($index > count($colors) || !isset($colors[$index]))
			return new WP_Error('index_not_found', 'El index no existe', [ 'status' => 404 ]);

		if($colors && count($colors) > 0) 
			array_splice($colors, $index, 1);
		
		update_user_meta( $user_id, 'colors', $colors );

		return [
			'code' => 'success',
			'message' => '',
			'data' => [
				'status' => 200,
				'colors' => $colors
			]
		];
	}

	public function get_user_meta($user, $field_name, $request) {
		return get_user_meta( $user['id'], $field_name, true );
	}

	public function update_user_meta($value, $user, $field_name) {
		return update_user_meta( $user->ID, $field_name, $value );
	}

	public function upload_user_image($value, $user, $field_name) {
		$url = $this->_upload_image($value, $user->ID);
		if(!is_wp_error($url)) {
			update_user_meta( $user->ID, $field_name, $url );
		}
	}

	public function add_user_columns( $columns ) {
		$columns['country'] = 'Country';
		return $columns;
	}

	public function fill_user_columns( $val, $column_name, $user_id ) {
		switch ($column_name) {
	        case 'country' :
	            return get_user_meta( 'country', $user_id, true );
	            break;
	        default:
	    }
	    return $val;
	}

}

function ceapp_get_instance() {
	return CE_App::get_instance();
}

add_action( 'plugins_loaded', [ ceapp_get_instance(), 'hooks' ] );