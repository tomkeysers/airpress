<?php

class AirpressVirtualPosts {

	private $config = false;
	private $AirpressCollection = false;

	function init(){
		global $wp_rewrite;

		add_action( 'parse_request',		array($this,'check_for_actual_page') );
		add_action( 'template_redirect',	array($this,'check_for_virtual_page') );//, -10);
		add_action( 'the_post',				array($this,'last_chance_for_data') );//, -10);

		// If Yoast SEO is NOT installed, we need to modify the
		// build in wordpress canonical URL. Unfortunately there's
		// no hook or filter for this yet so I'm replicating it
		// in this class and running it through my own "filter"
		$plugins = get_option("active_plugins");
		if (!in_array("wordpress-seo/wp-seo.php", $plugins)){
  			remove_action( 'wp_head', 'rel_canonical' );
  			add_action( 'wp_head', array($this,'rel_canonical') );
		} else {
			add_filter( 'wpseo_canonical',		array($this,'wpseo_canonical') );
		}	
	}
   
	/*
	If one of our redirect rules matched, we want to check to see if it WOULD HAVE matched
	an actual wordpress post/page
	*/
	public function check_for_actual_page( $request ) {

		if (isset($request->matched_rule)){
			// populate $matches var to save parts of the request string
			preg_match("/" . str_replace("/", "\/", $request->matched_rule) . "/", $request->request,$matches);

			$configs = get_airpress_configs("airpress_vp");

			if ( count($configs) == 0){
				return $request;
			}

			// figure out which config to use
			foreach($configs as $config){
				if ($request->matched_rule == $config["pattern"]){
					airpress_debug(0,$config["name"]." VirtualPost	".$config["pattern"]);
					$this->config = $config;
					break; // we got what we came for, let's jet
				}
			}
		}

		// If request is empty, then the home page was requested
		// and we're not going to allow home page to be virtual at this time...
		// mostly because, why?
		if (!empty($request->request)){

			$requested_post = get_page_by_path( $request->request ); // See if the original request WOULD have returned a page or 404

        	if ($this->config){
        		$this->config["requested_post"] = $requested_post;
        	}

			// if the requested post was REAL
	        if( $requested_post ){

	        	// This undoes the redirect because it turns out there IS an ACTUAL page
	        	// that WOULD have been served. And since we want REAL pages to override
	        	// Virtual posts, let's undo what we've done.
	            $request->query_vars['page_id'] = $requested_post->ID;
	        }
	    }

	    // an active config means that we should ask Airtable for some data
		if ($this->config){
			
			// This gets the API connection config
			$connection = get_airpress_config("airpress_cx",$this->config["connection"]);

			// We've matched a config. Let's check and see if a cooresponding record exists
			$query = new AirpressQuery();

			$query->setConfig($connection);

			$query->table($this->config["table"]);
			
			// Process formula to inject any vars
			$formula = $this->config["formula"];
			$i = 1;
			while (isset($matches[$i])){
				// use matches to replace variables in string like this.
				// AND({Field Name} = '$1',{Field 2} = '$2')
				$formula = str_replace("$".$i,$matches[$i],$formula);
				$i++;
			}

			$query->filterByFormula($formula);

			apply_filters("airpress_virtualpost_query",$query,$request);

			
			$result = new AirpressCollection($query);

			if (count($result) > 0){
				$this->AirpressCollection = $result;
			}

		}

	    return $request;
	}

	public function last_chance_for_data($post){
		if ( ! $this->config && function_exists("is_cornerstone") && is_cornerstone() == "render"){
			// The cornerstone renderer doesn't use permalinks, just a post ID.
			// So we have to wait until the post is setup, then determine the 
			// permalink, THEN determine if the permalink matches any of our rules.
			$protocol = (empty($_SERVER["HTTPS"]))? "http" : "https";
			$remove = $protocol."://".$_SERVER["HTTP_HOST"]."/";
			$request = new StdClass();
			$request->request = str_replace($remove,"",get_permalink($post->ID));
			
			$request = apply_filters("airpress_virtualpost_last_chance",$request);


			$configs = get_airpress_configs("airpress_vp");
			// figure out which config to use
			foreach($configs as $config){
				if (preg_match("/" . str_replace("/", "\/", $config["pattern"]) . "/", $request->request,$matches)){
					$request->matched_rule = $config["pattern"];
					break; // we got what we came for, let's jet
				}
			}

			$this->check_for_actual_page( $request );
			$this->setupPostObject();
		}
	}


	public function check_for_virtual_page(){
		global $wp, $wp_query;
		
		// if request matched one of our VirtualPost redirect rules
		if ($this->config){

			// if request doesn't match a real post
			if ($this->config["requested_post"] == false){

				// if this virtual post returned data from Airtable, set it up
				if ($this->AirpressCollection){
					$slug_field = $this->config["field"];
					$wp_query->post->guid = $wp_query->post->post_name = $this->AirpressCollection[0][$slug_field];
				} else {
					airpress_debug("no data");
					$this->force_404();
				}

			} else {

				// Yes, the request was for a real post, however it was for the template post so 404
				if ($this->config["requested_post"]->ID == $this->config["template"] && !is_user_logged_in()){
					$this->force_404();
				}

			}

			$this->setupPostObject();
		}
	}

	private function setupPostObject(){
		global $post;

		$post->AirpressCollection = $this->AirpressCollection;

		do_action("airpress_virtualpost_setup",$this->config);
	}

	function force_404(){
		// 1. Ensure `is_*` functions work
		global $wp_query;
		$wp_query->set_404();
		 
		// 2. Fix HTML title
		add_action( 'wp_title', function () {
				return '404: Not Found';
			}, 9999 );
	 
	    // 3. Throw 404
	    status_header( 404 );
	    nocache_headers();
	 
	    // 4. Show 404 template
	    require get_404_template();
	 
	    // 5. Stop execution
	    exit;
	}

	function wpseo_canonical($canonical_url){
		global $wp;

		if ($this->config){
			$slug_field = $this->config["field"];
			if (isset($this->AirpressCollection[0][$slug_field])){
				$canonical_url = $_SERVER["HTTP_HOST"].dirname($_SERVER["REQUEST_URI"])."/".$this->AirpressCollection[0][$slug_field]."/";
			}
		}
		
		return $canonical_url;
	}

	function rel_canonical() {
		if ( ! is_singular() ) {
			return;
		}

		if ( ! $id = get_queried_object_id() ) {
			return;
		}

		$url = get_permalink( $id );

		$page = get_query_var( 'page' );
		if ( $page >= 2 ) {
			if ( '' == get_option( 'permalink_structure' ) ) {
				$url = add_query_arg( 'page', $page, $url );
			} else {
				$url = trailingslashit( $url ) . user_trailingslashit( $page, 'single_paged' );
			}
		}

		$cpage = get_query_var( 'cpage' );
		if ( $cpage ) {
			$url = get_comments_pagenum_link( $cpage );
		}

		$url = $this->wpseo_canonical($url);

		echo '<link rel="canonical" href="' . esc_url( $url ) . "\" />\n";
	}


}

?>