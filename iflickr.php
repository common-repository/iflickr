<?php
/*
    Plugin Name: iflickr
    Plugin URI: http://www.photopreneur.com/iflickr/
    Description: Easily add Creative Commons licensed images to your wordpress posts.
    Version: 1.01
    Author: Photopreneur
    Author URI: http://www.photopreneur.com/


    This product uses the Flickr API but is not endorsed or certified by Flickr.


    (email : camden@photopreneur.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// pre 2.6 compatibility
if (!defined('WP_CONTENT_URL')) {
  define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content'); }

// instantiate our class
$iflickr = new iflickr();

class iflickr {

  // keep track of the number of total results for a search
  var $count = 0;

  // the number of results to show in the jCarousel sidebar search
  var $carousel_perpage = 4;

  // the number of results to show in the 'add media' search
  var $addmedia_perpage = 28;

  // set the domain for our internationalization
  var $l = 'iflickr';

  /*
   *  Our class constructor adds the actions to run this plugin
   */

  function __construct() {

    // create an administrative options page
    add_action('admin_menu', array($this, 'add_options_page'));

    // don't load the plugin if we don't have an api key set or if it is invalid
    $valid = false;
    if (strlen(get_option('iflickr_api_key')) != 0) {
       $valid = $this->validate_api_key(); }

    if ($valid) {
      // create default attribution text
      add_option('iflickr_attr_text', $value = 'Photo by', $deprecated = '', $autoload = 'yes');

      // add the Flickr tab to the image upload pages
      add_action('media_upload_image', array($this, 'add_media_tab'));
      add_action('media_upload_gallery', array($this, 'add_media_tab'));
      add_action('media_upload_library', array($this, 'add_media_tab'));
      add_action('media_upload_flickr', array($this, 'add_media_tab'));

      // insert our search html
      add_action('media_upload_flickr', array($this, 'insert_media_search'));
      add_action('submitpost_box', array($this, 'insert_sidebar_search'), 20); // set weight high so it goes at bottom
      add_action('submitpage_box', array($this, 'insert_sidebar_search'), 20); // set weight high so it goes at bottom

      // ensure that our javascript functions are added to the header
      add_action('admin_print_scripts', array($this, 'js_admin_header'));

      // set our ajax function handlers
      add_action('wp_ajax_iflickr_side_search', array($this, 'side_search'));
      add_action('wp_ajax_iflickr_carousel', array($this, 'carousel'));
      add_action('wp_ajax_iflickr_load_properties', array($this, 'load_properties'));
    }
  }

  /*
   *  Add a flickr tab to a tabs array
   */

  function add_flickr_tab($tabs) {

    $tabs['flickr'] = __('iflickr');
    return $tabs;
  }

  /*
   *  Add the Flickr search tab in the 'add media' pages
   */

  function add_media_tab() {

    // only add the tab while adding images
    if ($_REQUEST['type'] == 'image') {
      add_filter('media_upload_tabs', array($this, 'add_flickr_tab')); }
  }

  /*
   *  When the admin menu is loaded, add a new option page for this plugin
   */

  function add_options_page() {

    // user level 8 and above can edit plugin settings
    add_options_page('iflickr Options', 'iflickr', 8, dirname(__FILE__).'/admin-options.html');  
  }

  /*
   *  Called by jCarousel AJAX to grab more results for the sidebar search
   */

  function carousel() {

    $perpage = 100;  // might as well grab a lot at once
    $first = $_POST['first'];
    $last = $_POST['last'];
    $post_id = $_POST['post_id'];
    $offsetpage = ceil($first / $perpage);

    $photos = $this->search($_POST['tags'], $offsetpage, $perpage);
    // results might span two pages, grab the next page and merge the results
    $photos2 = $this->search($_POST['tags'], $offsetpage+1, $perpage);
    foreach ($photos2 as $photo) {
      $photos[] = $photo; }

    $counter = 0;
    $xml = "<data>";
    foreach ($photos as $photo) {
      $number = ($perpage *  ($offsetpage-1)) + $counter;
      // grab the requested images
      if ($number >= $first and $number <= $last) {
        $xml .= "<image><![CDATA[<a href='".WP_CONTENT_URL."/plugins/iflickr/properties.php?photo_id={$photo['id']}&post_id={$post_id}&TB_iframe=true&height=400&width=400' class='thickbox-{$number}'>";
        $xml .= "<img src='{$photo['thumbnail']}' alt='".htmlentities($photo['title'])."' width='75' height='75'/></a>]]></image>"; }
      $counter++; }
    $xml .= "</data>";

    // must send the right header or jQuery will give a 'parserror'
    header('Content-type: text/xml'); 
    die($xml);
  }

  /*
   *  Given a url, loads the xml into a simplexml object
   *
   *  Not all hosts allow_url_fopen, so if that fails, use CURL to grab the xml
   */

  function get_xml($url) {
    $xml = @simplexml_load_file($url);
    if (!$xml) {
      $ch = @curl_init();
      if (is_resource($ch)) {
        $timeout = 5; 
        curl_setopt ($ch, CURLOPT_URL, $url);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $data = curl_exec($ch);
        curl_close($ch);
        $xml = simplexml_load_string($data); } 
      else {
        return false; } }
    return $xml;
  }

  /*
   *  Adds our javascript functions to the head of the HTML
   */

  function js_admin_header() {

    // ensure that jquery is loaded for jcarousel
    wp_print_scripts(array('jquery'));

  ?>

    <script type="text/javascript" src="<?php echo WP_CONTENT_URL; ?>/plugins/iflickr/jcarousel/lib/jquery.jcarousel.pack.js"></script>
    <link rel="stylesheet" type="text/css" href="<?php echo WP_CONTENT_URL; ?>/plugins/iflickr/jcarousel/lib/jquery.jcarousel.css" />
    <link rel="stylesheet" type="text/css" href="<?php echo WP_CONTENT_URL; ?>/plugins/iflickr/jcarousel/skins/tango/skin.css" />

    <script type="text/javascript">
      //<![CDATA[

      function iflickrAddPhoto(sourceurl, width, height, title, username, profileurl) {
        var imgclass  = "<?php echo htmlentities(get_option('iflickr_img_class')); ?>";
        var attrclass = "<?php echo htmlentities(get_option('iflickr_attr_class')); ?>";
        var attrtext  = "<?php echo htmlentities(get_option('iflickr_attr_text')); ?>";
        var html = "<img src='"+sourceurl+"' alt='"+title+"' width="+width+" height="+height+" class='"+imgclass+"' /><br clear=all>" +
                   "<span class='"+attrclass+"'>"+attrtext+"&nbsp;<a href='"+profileurl+"'>"+username+"</a></span>";
        var win = window.dialogArguments || opener || parent || top;
        win.send_to_editor(html);
        return false; }

      function iflickrCheckReturn(event) {
        if (event.keyCode == 13) {
          iflickrSideSearch();
          return false; }
        else {
          return true; } }

      function iflickrItemLoadCallback(carousel, state) {
        if (carousel.has(carousel.first, carousel.last)) {
          return; }
        var ajax = jQuery.ajax({
          type: "POST",
          url: "admin-ajax.php",
          dataType: "xml",
          data: "action=iflickr_carousel&tags=" + document.post.iflickrtags.value + "&first=" + carousel.first + "&last=" + carousel.last + "&post_id=" + jQuery("#post_ID").val(),
          success: function(xml, status) {
            jQuery(xml).find('image').each(function(i) {
              var newindex = carousel.first + i;
              if (!carousel.has(newindex)) {
                carousel.add(carousel.first + i, jQuery(this).text()); 
                tb_init('a.thickbox-' + newindex); } }); } }); }

      function iflickrLoadProperties(postID) {
        var ajax = jQuery.ajax({
          type: 'POST',
          url: '<?php echo get_option('siteurl'); ?>/wp-admin/admin-ajax.php',
          data: 'action=iflickr_load_properties&post_id='+postID+'&photo_id=<?php echo $_REQUEST['photo_id'] ?>&referer=' + escape('<?php echo $_SERVER['HTTP_REFERER'] ?>'),
          success: function(js) {
            eval(js); } }); }

      function iflickrPleaseWait() {
        document.iflickrdownload.iflickrsubmit.value = 'Please wait...'; }

      function iflickrSideSearch() {
        jQuery('#iflickrresults').html('<?php _e('Please wait...', 'iflickr'); ?>');
        var ajax = jQuery.ajax({
          type: "POST",
          url: "admin-ajax.php",
          data: "action=iflickr_side_search&tags=" + document.post.iflickrtags.value,
          success: function(js) {
            eval(js); } }); }

      //]]>
    </script>
  <?php
  }

  /*
   *  Create the search page for the 'add media' tab
   */

  function insert_media_search() {

    global $wp_version;
    $tags = $_REQUEST['tags'];
    $offsetpage = (int) $_REQUEST['paged'];
    $this->search($tags, $offsetpage, $this->addmedia_perpage);
    if ($wp_version < '2.6') {
      add_action('admin_head_iflickr_media_upload', 'media_admin_css'); }
    else {
      wp_admin_css( 'media' ); }
    media_upload_header();
    return wp_iframe('iflickr_media_upload', $this);
  }

  /*
   *  Create the search box on the sidebar
   */

  function insert_sidebar_search() {

    $html  = "<div class='side-info'><h5>".__("Search Flickr", $this->l)."</h5></div><input type='text' name='iflickrtags' id='iflickrtags' size='16' onkeypress='return iflickrCheckReturn(event)'>";
    $html .= "<input type='button' value='go' class='button' onclick='return iflickrSideSearch()'>";
    $html .= "<div name='iflickrresults' id='iflickrresults'></div>";
    echo $html;
  }

  /*
  *  Creates the page content for choosing photo properties
  */

  function load_properties() {

    $post_id = $_REQUEST['post_id'];
    $photo_id = $_REQUEST['photo_id'];
    $api_key = get_option('iflickr_api_key');

    // grab the username and title for this image
    $info_url = "http://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key={$api_key}&photo_id={$photo_id}";
    $info_xml = $this->get_xml($info_url);
    $description = htmlentities("{$info_xml->photo->title}");
    $filename = htmlentities($photo_id);

    // grab the various size options for this photo
    $sizes_url = "http://api.flickr.com/services/rest/?method=flickr.photos.getSizes&api_key={$api_key}&photo_id={$photo_id}";
    $sizes_xml = $this->get_xml($sizes_url);
    $size_count = 0;
    foreach ($sizes_xml->sizes->size as $size) {
      if ($size_count == '2') {
        $image_url = $size['source']; }
      foreach ($size->attributes() as $key => $val) {
        $sizes[$size_count][$key] = "{$val}"; } 
      $size_count++; }
    $html .= "<p align='center'><img src='{$image_url}'></p>";
    $html .= "<form method='post' name='iflickrdownload' action='properties.php'>";
    $html .= "<input type='hidden' name='photo_id' value='{$photo_id}'>";
    $html .= "<input type='hidden' name='post_id' value='{$post_id}'>";
    $html .= "<table border=0 cellpadding=0 cellspacing=0 align='center'>";

    $size_count = 0;
    $html .= "<tr><td>".__('Size:', $this->l)."</td><td><select name='size'>";
    foreach ($sizes as $size) {
      $selected = ($size_count == 2) ? ' selected' : '';  // set the second image as the default size, as it's the one that's being displayed
      $html .= "<option value='{$size_count}'{$selected}>{$size['width']}x{$size['height']}</option>"; 
      $size_count++; }
    $html .= "</select></td></tr>";

    $html .= "<tr><td>".__('File Name:', $this->l)."</td><td><input type='text' name='filename' value='{$filename}'></tr></tr>";
    $html .= "<tr><td>".__('Description:', $this->l)."</td><td><input type='text' name='description' value='{$description}'></tr></tr>";
    $html .= "<tr><td colspan=2 align=center><input id='iflickrsubmit' type='submit' name='submit' value='".__('insert image', $this->l)."' class='button' onclick='iflickrPleaseWait()' /></td></tr></table></form>";
    if (strpos($_REQUEST['referer'], "media-upload") !== false) {
      $html .= "<div style='text-align: center'>---<br><br><a href='{$_REQUEST['referer']}' class='button'>".__('cancel', $this->l)."</a></div>"; }

    $js = "jQuery('#iflickrcontent').html(\"{$html}\");";
    die($js);
  }

  /*
   *  Find images on Flickr that match the given tags and return them as an array.
   */

  function search($tags, $offsetpage=0, $perpage=20) {

    $api_key = get_option('iflickr_api_key');

    if (strlen($tags) == 0) {
      // no tags to search on, return empty array
      return array(); }

    // split the tags on spaces, insert commas
    $tags = explode(" ", $tags);
    foreach ($tags as $tag) {
      $tag_url .= urlencode($tag) . ","; }
    $tag_url = substr($tag_url, 0, strlen($tag_url)-1);  // strip the last comma

    // license '1' is Attribution - Noncommerical - Share-Alike
    // license '2' is Attribution - Noncommercial
    // license '3' is Attribution - Noncommercial - No derivative
    // license '4' is Attribution - 2.0 Generic
    // license '5' is Attribution - Share-Alike
    // license '6' is Attribution - No Derivative
    //   we use licenses 4,5,6
    $search_url  = "http://api.flickr.com/services/rest/?method=flickr.photos.search";
    $search_url .= "&api_key={$api_key}&tags={$tag_url}&per_page={$perpage}&page={$offsetpage}&license=4,5,6&sort=relevance&tag_mode=all";

    // we use the REST implementation of the API, as it's the simplest for our needs
    $search_xml = $this->get_xml($search_url);
    // step through our results and place them into an array of results
    $photo_count = 0;
    foreach ($search_xml->photos->photo as $photo) {
      // store attributes to our array
      foreach ($photo->attributes() as $key => $val) {
        $photos[$photo_count][$key] = "{$val}"; }
      // construct the link to the Square sized image to use as a thumbnail
      $farm = $photos[$photo_count]['farm'];
      $server = $photos[$photo_count]['server'];
      $id = $photos[$photo_count]['id'];
      $secret = $photos[$photo_count]['secret'];
      $photos[$photo_count]['thumbnail'] = "http://farm{$farm}.static.flickr.com/{$server}/{$id}_{$secret}_s.jpg";
      $photo_count++;
    }

    $this->count = $search_xml->photos['pages'] * $perpage;
    $this->photos = $photos;
    return $photos;
  }

  /*
   *  Create the jCarousel for our search results
   */

  function side_search() {

    // do a search just so that $this->count will be set, which we need when initializing the carousel
    $photos = $this->search($_POST['tags'], 0, $this->carousel_perpage);

    if ($this->count > 0) {
      $haveresults = true;
      $html  = "<style type='text/css'>#iflickr-carousel .jcarousel-item-placeholder { background: transparent url(".WP_CONTENT_URL."/plugins/iflickr/loading.gif) no-repeat; }</style>";
      $html .= "<table border=0 align='center'><tr><td><ul id='iflickr-carousel' class='jcarousel-skin-tango'></ul></div></td></tr></table>"; }
    else {
      $haveresults = false; 
      $html  = "Sorry, no photos found."; }

    $js = "jQuery('#iflickrresults').html(\"{$html}\");";
    if ($haveresults) {
      $js .= "jQuery(document).ready(function() { jQuery('#iflickr-carousel').jcarousel({vertical: true, size: {$this->count}, itemScroll: {$this->carousel_perpage}, itemLoadCallback: iflickrItemLoadCallback});});"; }

    // return our javascript
    die($js);
  }

  /*
   *  Save a flickr file to the local server, and add metadata for it to the database
   */

  function upload_file($name, $source, $description, $author, $link, $post_id) {

    // first check if user can upload
    if(!current_user_can('upload_files')) {
      $this->auth_required(__('You do not have permission to upload files.')); }

    // read the raw binary data of the source image
    $fp = @fopen($source, 'r');
    if ($fp) {
      // if allow_url_fopen, use that
      $bits = NULL;
      while(!feof($fp)) {
        $bits .= fread($fp, 4096); }
      fclose($fp); }
    else {
      // failed to open url, try to use curl
      $ch = @curl_init();
      if (is_resource($ch)) {
        $timeout = 5; 
        curl_setopt ($ch, CURLOPT_URL, $source);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $bits = curl_exec($ch);
        curl_close($ch); }
    }

    // download the file to the local server
/* TODO: check to see if filesize is too large?? */
    $name = sanitize_file_name($name);
    $filetype = wp_check_filetype($source);
    $file = wp_upload_bits("{$name}.{$filetype['ext']}", NULL, $bits);

    // construct the attachment array
    $attachment_data = array(
      'post_title' => $name,
      'post_content' => $description,
      'post_type' => 'attachment',
      'post_parent' => $post_id,
      'post_mime_type' => $filetype['type'],
      'guid' => $file['url']
    );

    // add this file as an attachment
    $attachment_id = wp_insert_attachment($attachment_data, $file['file']);

    // create default metadata for this attachment, including generating a thumbnail image
    $metadata = wp_generate_attachment_metadata($attachment_id, $file['file']);
    // insert out own metadata
    $metadata['iflickr_author'] = $author;
    $metadata['iflickr_link'] = $link;
    $metadata['iflickr_url'] = $file['url'];
    wp_update_attachment_metadata($attachment_id, $metadata);

    // return new url
    $return['url'] = $file['url'];
    $return['link'] = $link;
    return $return;
  }

  /*
   * Tests a Flickr API key to see if it is valid
   */

  function validate_api_key() {

    $api_key = get_option('iflickr_api_key');
    $test_url  = "http://api.flickr.com/services/rest/?method=flickr.photos.getRecent&api_key={$api_key}";
    $test_xml = $this->get_xml($test_url);

    if (!$test_xml) {
      return false; }
    if ($test_xml->err['code'] == 100 ) {
      return false; }
    else {
      return true; }
  }

}  // end class definition


/*
  *  Creates the page content for the 'add media' flickr search
  *
  *  Due to the way that the wp_iframe function works, this function cannot be part of the class defined above
  */

function iflickr_media_upload($iflickr) {

  $photos = $iflickr->photos;
  $post_id = $_REQUEST['post_id'];

  $html = "<form  method='get'  id='photos'     action='{$_SERVER['PHP_SELF']}'>
           <input type='hidden' name='tab'      value='{$_GET['tab']}' />
           <input type='hidden' name='post_id'  value='{$_GET['post_id']}' />
           <input type='hidden' name='action'   value='{$_GET['action']}' />
           <input type='hidden' name='style'    value='{$_GET['style']}' />
           <input type='hidden' name='_wpnonce' value='{$_GET['_wpnonce']}' />
           <input type='hidden' name='ID'       value='{$_GET['ID']}' />
           <input type='hidden' name='paged'    value='1' /> 
           <input type='hidden' name='type'     value='image' /> 
           <div class='tablenav'>";

  // create our page links
  $page_links = paginate_links(array(
    'base' => add_query_arg('paged', '%#%'),
    'format' => '',
    'mid_size' => '1',
    'end_size' => '0',
    'total' => ceil($iflickr->count / $iflickr->addmedia_perpage),
    'current' => $_REQUEST['paged']
  ));

  if ($page_links) {
    $html .= "<div class='tablenav-pages'>{$page_links}</div>"; }

  $html .= "<div class='alignleft'>";
  $html .= "Tags: <input type='text' name='tags' value='{$_REQUEST['tags']}' size='20' />";
  $html .= "<input class='button' type='submit' name='search' value='search' />";
  $html .= "</div>";

  $html .= "<br class='clear' /></div></form><br>";
  $html .= "<style type='text/css'>";
  $html .= "  .alignleft {";
  $html .= "     position: relative;";
  $html .= "     list-style-type: none;";
  $html .= "     padding: 5px;";
  $html .= "   }";
  $html .= "</style>";

  if (!isset($_REQUEST['tags'])) {
    $html .= "<h4 align='center'>" . __("Please enter tags to search for, separated by spaces.", $iflickr->l) . "</h4>"; }

  elseif (count($photos) == 0) {
    $html .= "<h4 align='center'>" . __("Sorry, no photos found!", $iflickr->l) . "</h4>"; }

  elseif (is_array($photos)) { 
    $html .= "<ul>";
    foreach ($photos as $photo) {
      $html .= "<li class='alignleft'>";
      $html .= "<a href='".WP_CONTENT_URL."/plugins/iflickr/properties.php?photo_id={$photo['id']}&post_id={$post_id}&TB_iframe=true&height=400&width=400' class='thickbox'>";
      $html .= "<img src='{$photo['thumbnail']}' alt='".htmlentities($photo['title'])."'/></a></li>"; }
    $html .= "</ul>"; }
  echo $html;
}

?>