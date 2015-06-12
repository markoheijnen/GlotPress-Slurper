<?php

require_once dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/gp-load.php';
require_once dirname( dirname( __FILE__ ) ) . '/inc/extract.php';

class Plugin_Slurper_Import extends GP_CLI {
	private $temp_folder;
	private $extractor;
	private $meta_headers;

	private $amount = 300;

	public function run() {
		$this->setup_extractor();

		$this->import_all_projects();
	}


	private function import_all_projects() {
		if ( ! isset( $this->args[0] ) || ! in_array( $this->args[0], array( 'start', 'continue' ) ) ) {
			$this->error( __('Define action') );
			return;
		}

		$action = $this->args[0];

		$plugin_project_id = $this->create_plugin_main_project();

		if ( ! $plugin_project_id ) {
			$this->error( __( "Plugins couldn't be imported" ) );
			return;
		}

		$plugins = GP::$plugins->plugin_slurper->get_plugins();

		if ( 'continue' == $action ) {
			$current = GP::$plugins->plugin_slurper->get_option( 'position' );

			if ( ! $current ) {
				$this->error( __( "All plugins already got loaded." ) );
				return;
			}

			$plugins = array_slice( $plugins, $current); 
		}
		else {
			$current = 0;
			GP::$plugins->plugin_slurper->update_option( 'position', 0 );
		}

		$counter = 0;
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$project = GP::$project->by_path( 'plugins/' . $plugin_data['Slug'] );

			if ( ! $project ) {
				$post = array(
					'name'                => $plugin_data['Name'],
					'slug'                => $plugin_data['Slug'],
					'description'         => $plugin_data['Description'],
					'source_url_template' => 'plugins.trac.wordpress.org/browser/' . $plugin_data['Slug'] . '/%file%#L%line%',
					'parent_project_id'   => $plugin_project_id,
					'active'              => true
				);

				$new_project = new GP_Project( $post );

				if ( ! $new_project->validate() ) {
					echo sprintf( __( "%s couldn't get created" ), $plugin_data['Name'] . " \t" ) . "\n";
					continue;
				}

				$project = GP::$project->create_and_select( $new_project );
			}

			$result = $this->import_project_originals( $project, $plugin_file, $plugin_data );

			if ( $result ) {
				list( $originals_added, $originals_existing ) = $result;
				echo sprintf( __( "%s %s new strings were added, %s existing were updated." ), $project->name . " \t", $originals_added, $originals_existing ) . "\n";

				$result = $this->import_project_translations( $project, $plugin_file, $plugin_data );

			}
			else {
				echo sprintf( __( "%s couldn't get updated" ), $project->name . " \t" ) . "\n";
			}

			$counter++;

			GP::$plugins->plugin_slurper->update_option( 'position', $current + $counter );

			if ( $counter >= $this->amount || 250000000 <= memory_get_usage() ) {
				if ( count( $plugins ) > $counter ) {
					echo __( "There is still more in the queue. Use 'php importer continue' to parse the rest." ) . "\n";
				}

				return false;
			}
		}

		GP::$plugins->plugin_slurper->update_option( 'position', 0 );
		echo __( "All plugins are loaded." ) . "\n";
	}


	private function create_plugin_main_project() {
		$project = GP::$project->by_path( 'plugins' );

		if ( ! $project ) {
			$post = array(
				'name'                => 'Plugins',
				'slug'                => 'plugins',
				'description'         => 'All translatable plugins from WordPress.org',
				'source_url_template' => '',
				'parent_project_id'   => 0,
				'active'              => false
			);

			$new_project = new GP_Project( $post );

			if ( ! $new_project->validate() ) {
				return false;
			}

			$project = GP::$project->create_and_select( $new_project );
		}

		return $project->id;
	}



	private function import_project_originals( $project, $main_file, $plugin_data ) {
		$result = false;

		$project_path = GP::$plugins->plugin_slurper->path . '/' . $plugin_data['Slug'];

		// Get tranlsations
		$translations = $this->extractor->extract_from_directory( $project_path );

		// Get project meta
		$meta = $this->get_project_meta( $project_path, 'plugin', GP::$plugins->plugin_slurper->path . '/' . $main_file );

		// Merge meta with translations
		$translations->merge_originals_with( $meta );

		// Insert new translations 
		return GP::$original->import_for_project( $project, $translations );
	}

	private function import_project_translations( $project, $folder, $plugin_data ) {
		$project_path = GP::$plugins->plugin_slurper->path . '/' . $plugin_data['Slug'];
		$domain_path  = trailingslashit( trailingslashit( $project_path ) . $plugin_data['DomainPath'] );
		$domain_dir   = @ opendir( $domain_path );

		if ( ! $domain_dir ) {
			echo sprintf( __( "%s doesn't has a valid language folder" ), $project->name ) . "\n";
			return false;
		}

		while ( ( $file = readdir( $domain_dir ) ) !== false ) {
			if ( substr( $file, -3 ) != '.po' ) {
				continue;
			}

			$wp_locale = str_replace( array( $plugin_data['TextDomain'] . '-', '.po' ), '', $file );
			$locale    = GP_Locales::by_field( 'wp_locale', $wp_locale );

			if ( ! $locale ) {
				echo sprintf( __( "%s has the wp-locale '%s' that doesn't exist in GlotPress" ), $project->name, $wp_locale ) . "\n";
				continue;
			}
			
			$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, 'default', $locale->slug );

			if ( ! $translation_set ) {
				$data = array(
					'name'       => $locale->english_name,
					'slug'       => 'default',
					'locale'     => $locale->slug,
					'project_id' => $project->id,
				);
				$new_set = new GP_Translation_Set( $data );

				if ( ! $new_set->validate() ) {
					echo sprintf( __( "%s has the wp-locale '%s' that couldn't create the translation set." ), $project->name, $wp_locale ) . "\n";
					continue;
				}

				$translation_set = GP::$translation_set->create_and_select( $new_set );

				echo sprintf( __( "%s created a translation set for the wp-locale '%s'." ), $project->name, $wp_locale ) . "\n";
			}

			$format       = GP::$formats['po'];
			$translations = $format->read_translations_from_file( $domain_path . $file, $project );

			if ( ! $translations ) {
				echo sprintf( __( "%s has the wp-locale '%s' that couldn't import it's translations." ), $project->name, $wp_locale ) . "\n";
				continue;
			}

			$translations_added = $translation_set->import( $translations );

			echo sprintf( __( "%s added %s translations for wp-locale '%s'." ), $project->name, $translations_added, $wp_locale ) . "\n";
		}

		closedir( $domain_dir );
	}







	private function get_project_meta( $path, $type = 'plugin', $file = false ) {
		$first_lines  = '';
		$translations = (object) array( 'entries' => array() );

		if ( 'theme' == $type ) {
			$file = $path . '/style.css';
		}
		else if( $file ) {
			$file = $path . '/' . $file;
		}

		if ( $file && is_file( $file ) ) {
			$extf = fopen( $file, 'r' );

			if ( ! $extf ) {
				return $translations;
			}

			foreach ( range( 1, 30 ) as $x ) {
				$line = fgets( $extf );

				if ( feof( $extf ) ) {
					break;
				}

				if ( false === $line ) {
					return false;
				}

				$first_lines .= $line;
			}

			foreach ( $this->meta_headers as $header ) {
				$string = $this->get_addon_header( $header, $first_lines );

				if ( ! $string ) {
					continue;
				}

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
		if ( preg_match( '|'.$header.':(.*)$|mi', $source, $matches ) ) {
			return trim( $matches[1] );
		}
		else {
			return false;
		}
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

$gp_plugin_slurper_import = new Plugin_Slurper_Import;
$gp_plugin_slurper_import->run();