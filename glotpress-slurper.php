<?php

class Plugin_Slurper extends GP_Plugin {
	public $id = 'plugin_slurper';

	private $path;


	public function __construct() {
		parent::__construct();

		$this->path = dirname( __FILE__ );
	}

	/**
	 * Check the plugins directory and retrieve all plugin files with plugin data.
	 *
	 * WordPress only supports plugin files in the base plugins directory
	 * (wp-content/plugins) and in one directory above the plugins directory
	 * (wp-content/plugins/my-plugin). The file it looks for has the plugin data and
	 * must be found in those two locations. It is recommended that do keep your
	 * plugin files in directories.
	 *
	 * The file with the plugin data is the file that will be included and therefore
	 * needs to have the main execution for the plugin. This does not mean
	 * everything must be contained in the file and it is recommended that the file
	 * be split for maintainability. Keep everything in one file for extreme
	 * optimization purposes.
	 *
	 * @return array Key is the plugin file path and the value is an array of the plugin data.
	 */
	public function get_plugins() {
		$wp_plugins   = array();
		$plugin_files = array();
		$plugins_dir  = @ opendir( $plugin_root);

		if ( $plugins_dir ) {
			while ( ( $file = readdir( $plugins_dir ) ) !== false ) {
				if ( substr( $file, 0, 1 ) == '.' )
					continue;

				if ( is_dir( $plugin_root . '/' . $file ) ) {
					$plugins_subdir = @ opendir( $plugin_root . '/' . $file );

					if ( $plugins_subdir ) {
						while ( ( $subfile = readdir( $plugins_subdir ) ) !== false ) {
							if ( substr( $subfile, 0, 1 ) == '.' )
								continue;

							if ( substr( $subfile, -4 ) == '.php' )
								$plugin_files[] = "$file/$subfile";
						}

						closedir( $plugins_subdir );
					}
				}
				else {
					if ( substr( $file, -4 ) == '.php' )
						$plugin_files[] = $file;
				}
			}

			closedir( $plugins_dir );
		}

		if ( empty( $plugin_files ) )
			return $wp_plugins;

		foreach ( $plugin_files as $plugin_file ) {
			if ( ! is_readable( "$plugin_root/$plugin_file" ) )
				continue;

			$plugin_data = $this->get_plugin_data( "$plugin_root/$plugin_file" );

			if ( empty ( $plugin_data['Name'] ) )
				continue;

			$wp_plugins[ $this->plugin_basename( $plugin_file ) ] = $plugin_data;
		}

		uasort( $wp_plugins, array( $this, 'sort_uname_callback' ) );

		return $wp_plugins;
	}


	/**
	 * Callback to sort array by a 'Name' key.
	 *
	 * @since 3.1.0
	 * @access private
	 */
	function sort_uname_callback( $a, $b ) {
		return strnatcasecmp( $a['Name'], $b['Name'] );
	}


	/**
	 * Parse the plugin contents to retrieve plugin's metadata.
	 *
	 * The metadata of the plugin's data searches for the following in the plugin's
	 * header. All plugin data must be on its own line. For plugin description, it
	 * must not have any newlines or only parts of the description will be displayed
	 * and the same goes for the plugin data. The below is formatted for printing.
	 *
	 * <code>
	 * /*
	 * Plugin Name: Name of Plugin
	 * Plugin URI: Link to plugin information
	 * Description: Plugin Description
	 * Author: Plugin author's name
	 * Author URI: Link to the author's web site
	 * Version: Must be set in the plugin for WordPress 2.3+
	 * Text Domain: Optional. Unique identifier, should be same as the one used in
	 *		plugin_text_domain()
	 * Domain Path: Optional. Only useful if the translations are located in a
	 *		folder above the plugin's base path. For example, if .mo files are
	 *		located in the locale folder then Domain Path will be "/locale/" and
	 *		must have the first slash. Defaults to the base folder the plugin is
	 *		located in.
	 * Network: Optional. Specify "Network: true" to require that a plugin is activated
	 *		across all sites in an installation. This will prevent a plugin from being
	 *		activated on a single site when Multisite is enabled.
	 *  * / # Remove the space to close comment
	 * </code>
	 *
	 * Plugin data returned array contains the following:
	 *		'Name' - Name of the plugin, must be unique.
	 *		'Title' - Title of the plugin and the link to the plugin's web site.
	 *		'Description' - Description of what the plugin does and/or notes
	 *		from the author.
	 *		'Author' - The author's name
	 *		'AuthorURI' - The authors web site address.
	 *		'Version' - The plugin version number.
	 *		'PluginURI' - Plugin web site address.
	 *		'TextDomain' - Plugin's text domain for localization.
	 *		'DomainPath' - Plugin's relative directory path to .mo files.
	 *		'Network' - Boolean. Whether the plugin can only be activated network wide.
	 *
	 * Some users have issues with opening large files and manipulating the contents
	 * for want is usually the first 1kiB or 2kiB. This function stops pulling in
	 * the plugin contents when it has all of the required plugin data.
	 *
	 * The first 8kiB of the file will be pulled in and if the plugin data is not
	 * within that first 8kiB, then the plugin author should correct their plugin
	 * and move the plugin data headers to the top.
	 *
	 * The plugin file is assumed to have permissions to allow for scripts to read
	 * the file. This is not checked however and the file is only opened for
	 * reading.
	 *
	 * @link http://trac.wordpress.org/ticket/5651 Previous Optimizations.
	 * @link http://trac.wordpress.org/ticket/7372 Further and better Optimizations.
	 * @since 1.5.0
	 *
	 * @param string $plugin_file Path to the plugin file
	 * @return array See above for description.
	 */
	function get_plugin_data( $plugin_file) {
		$default_headers = array(
			'Name' => 'Plugin Name',
			'PluginURI' => 'Plugin URI',
			'Version' => 'Version',
			'Description' => 'Description',
			'Author' => 'Author',
			'AuthorURI' => 'Author URI',
			'TextDomain' => 'Text Domain',
			'DomainPath' => 'Domain Path',
			'Network' => 'Network',
			// Site Wide Only is deprecated in favor of Network.
			'_sitewide' => 'Site Wide Only',
		);

		$plugin_data = $this->get_file_data( $plugin_file, $default_headers, 'plugin' );

		// Site Wide Only is the old header for Network
		if ( ! $plugin_data['Network'] && $plugin_data['_sitewide'] )
			$plugin_data['Network'] = $plugin_data['_sitewide'];

		$plugin_data['Network'] = ( 'true' == strtolower( $plugin_data['Network'] ) );
		unset( $plugin_data['_sitewide'] );

		$plugin_data['Title']      = $plugin_data['Name'];
		$plugin_data['AuthorName'] = $plugin_data['Author'];

		return $plugin_data;
	}


	/**
	 * Retrieve metadata from a file.
	 *
	 * Searches for metadata in the first 8kiB of a file, such as a plugin or theme.
	 * Each piece of metadata must be on its own line. Fields can not span multiple
	 * lines, the value will get cut at the end of the first line.
	 *
	 * If the file data is not within that first 8kiB, then the author should correct
	 * their plugin file and move the data headers to the top.
	 *
	 * @see http://codex.wordpress.org/File_Header
	 *
	 * @since 2.9.0
	 * @param string $file Path to the file
	 * @param array $default_headers List of headers, in the format array('HeaderKey' => 'Header Name')
	 * @param string $context If specified adds filter hook "extra_{$context}_headers"
	 */
	function get_file_data( $file, $default_headers, $context = '' ) {
		// We don't need to write to the file, so just open for reading.
		$fp = fopen( $file, 'r' );

		// Pull only the first 8kiB of the file in.
		$file_data = fread( $fp, 8192 );

		// PHP will close file handle, but we are good citizens.
		fclose( $fp );

		// Make sure we catch CR-only line endings.
		$file_data   = str_replace( "\r", "\n", $file_data );
		$all_headers = $default_headers;

		foreach ( $all_headers as $field => $regex ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] )
				$all_headers[ $field ] = trim( preg_replace( "/\s*(?:\*\/|\?>).*/", '', $match[1] ) );
			else
				$all_headers[ $field ] = '';
		}

		return $all_headers;
	}


	/**
	 * Gets the basename of a plugin.
	 *
	 * This method extracts the name of a plugin from its filename.
	 *
	 * @package WordPress
	 * @subpackage Plugin
	 * @since 1.5
	 *
	 * @access private
	 *
	 * @param string $file The filename of plugin.
	 * @return string The name of a plugin.
	 */
	function plugin_basename($file) {
		$file       = str_replace( '\\','/',$file ); // sanitize for Win32 installs
		$file       = preg_replace( '|/+|','/', $file ); // remove any duplicate slash
		$plugin_dir = str_replace( '\\','/', $this->path ); // sanitize for Win32 installs
		$plugin_dir = preg_replace( '|/+|','/', $plugin_dir ); // remove any duplicate slash
		$file       = preg_replace( '#^' . preg_quote( $plugin_dir, '#' ) . '/|^' . '/#','',$file ); // get relative path from plugins dir
		$file       = trim( $file, '/' );

		return $file;
	}

}

GP::$plugins->plugin_slurper = new Plugin_Slurper;