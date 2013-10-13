<?php

require_once dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/gp-load.php';
require_once dirname( dirname( __FILE__ ) ) . '/inc/extract.php';

class Plugin_Slurper_Import extends GP_CLI {
	private $temp_folder;
	private $extractor;
	private $meta_headers;

	public function run() {
		$this->setup_extractor();

		$this->import_all_projects();
	}


	private function import_all_projects() {
		if( ! $this->create_plugin_main_project() ) {
			echo __( "Plugins couldn't be imported" ) . "\n";
			return;
		}

		$projects = GP::$plugins->plugin_slurper->get_plugins();

		foreach( $projects as $project_file => $project_data ) {
			$project = GP::$project->by_path( 'plugins/' . $plugin_data['Slug'] );

			if( ! $project ) {
				$post = array(
					'name'                => $plugin_data['Name'],
					'slug'                => $plugin_data['Slug'],
					'description'         => $plugin_data['Description'],
					'source_url_template' => 'plugins.trac.wordpress.org/browser/' . $plugin_data['Slug'] . '/%file%#L%line%',
					'parent_project_id'   => 0,
					'active'              => true
				);

				$new_project = new GP_Project( $post );

				if ( $this->invalid_and_redirect( $new_project ) )
					continue;

				$project = GP::$project->create_and_select( $new_project );
			}

			$result = $this->_import_single_project( $project );

			if( $result ) {
				list( $originals_added, $originals_existing ) = $result;
				echo sprintf( __( "%s %s new strings were added, %s existing were updated." ), $project->name . " \t", $originals_added, $originals_existing ) . "\n";
			}
			else {
				echo sprintf( __( "%s couldn't get updated" ), $project->name . " \t" ) . "\n";
			}
		}
	}


	private function create_plugin_main_project() {
		$project = GP::$project->by_path( 'plugins' );

		if( ! $project ) {
			$post = array(
				'name'                => 'Plugins',
				'slug'                => 'plugins',
				'description'         => 'All translatable plugins from WordPress.org',
				'source_url_template' => '',
				'parent_project_id'   => 0,
				'active'              => false
			);

			$new_project = new GP_Project( $post );

			if ( $this->invalid_and_redirect( $new_project ) )
				return false;

			$project = GP::$project->create_and_select( $new_project );
		}

		return $project->id;
	}



	private function _import_single_project( $project ) {
		$result = false;

		/*
		$repo         = GP::$plugins->gp_updater->get_project_repo( $project->id );
		$project_path = $this->path . $project->id;

		// Check if cloning was successful
		if( $result ) {
			$file = GP::$plugins->gp_updater->get_option_with_object_id( $project->id, 'main_file' );
			$type = GP::$plugins->gp_updater->get_option_with_object_id( $project->id, 'type' );

			// Get tranlsations
			$translations = $this->extractor->extract_from_directory( $project_path );

			// Get project meta
			$meta = $this->get_project_meta( $project_path, $type, $file );

			// Merge meta with translations
			$translations->merge_originals_with( $meta );

			// Insert new translations 
			return GP::$original->import_for_project( $project, $translations );	
		}
		*/

		return false;
	}

	private function get_project_meta( $path, $type = 'plugin', $file = false ) {
		$first_lines  = '';
		$translations = (object) array( 'entries' => array() );

		if( 'theme' == $type )
			$file = $path . '/style.css';
		else if( $file )
			$file = $path . '/' . $file;

		if( $file && is_file( $file ) ) {
			$extf = fopen( $file, 'r' );

			if ( ! $extf )
				return $translations;

			foreach( range( 1, 30 ) as $x ) {
				$line = fgets( $extf );

				if ( feof( $extf ) )
					break;

				if ( false === $line )
					return false;

				$first_lines .= $line;
			}

			foreach( $this->meta_headers as $header ) {
				$string = $this->get_addon_header( $header, $first_lines );

				if ( ! $string )
					continue;

				$args = array(
					'singular'           => $string,
					'extracted_comments' => $header . ' of the plugin/theme',
				);

				$translations->entries[] = new Translation_Entry( $args );
			}
		}

		return $translations;
	}

	private function get_addon_header( $header, $source ) {
		if ( preg_match( '|'.$header.':(.*)$|mi', $source, $matches ) )
			return trim( $matches[1] );
		else
			return false;
	}





	public function setup_extractor() {
		$rules = array(
			'_' => array('string'),
			'__' => array('string', 'domain'),
			'_e' => array('string', 'domain'),
			'_n' => array('singular', 'plural', null, 'domain'),
			'_n_noop' => array('singular', 'plural', 'domain'),
			'_x' => array('string', 'context', 'domain'),
			'_ex' => array('string', 'context', 'domain'),
			'_nx' => array('singular', 'plural', null, 'context', 'domain'),
			'_nx_noop' => array('singular', 'plural', 'context', 'domain'),
			'_n_js' => array('singular', 'plural'),
			'_nx_js' => array('singular', 'plural', 'context'),
			'esc_attr__' => array('string', 'domain'),
			'esc_html__' => array('string', 'domain'),
			'esc_attr_e' => array('string', 'domain'),
			'esc_html_e' => array('string', 'domain'),
			'esc_attr_x' => array('string', 'context', 'domain'),
			'esc_html_x' => array('string', 'context', 'domain'),
			'comments_number_link' => array('string', 'singular', 'plural'),
		);

		$this->extractor = new StringExtractor( $rules );

		$this->meta_headers = array(
			'Plugin Name',
			'Theme Name',
			'Plugin URI',
			'Theme URI',
			'Description',
			'Author',
			'Author URI',
			'Tags',
		);
	}

}

$gp_updater_script_import = new GP_Updater_Script_Import;
$gp_updater_script_import->run();