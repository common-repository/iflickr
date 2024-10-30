<?php

// pre 2.6 compatibility
$root = dirname(dirname(dirname(dirname(__FILE__))));
if (file_exists($root.'/wp-load.php')) {
  // WP >= 2.6
  require_once($root.'/wp-load.php');
  require_once($root.'/wp-admin/admin.php'); }
else {
  // WP < 2.6
  require_once($root.'/wp-config.php');
  require_once($root.'/wp-admin/admin.php'); }

// include the javascript we'll need to insert the image into the editor
wp_enqueue_script('media-upload');

// we use the built in wordpress function to create the headers and footers for our iframe
if (!isset($_POST['submit'])) {
  echo wp_iframe('iflickr_properties', $iflickr); }
else {
  echo wp_iframe('iflickr_download', $iflickr); }

/*
 *  Given the passed parameters, download the file and insert it into the editor
 */

function iflickr_download($iflickr) {

  $api_key = get_option('iflickr_api_key');
  $photo_id = $_REQUEST['photo_id'];
  $post_id = $_REQUEST['post_id'];
  $size_id = $_REQUEST['size'];
  $filename = $_REQUEST['filename'];
  $description = $_REQUEST['description'];

  // grab the various size options for this photo
  $sizes_url = "http://api.flickr.com/services/rest/?method=flickr.photos.getSizes&api_key={$api_key}&photo_id={$photo_id}";
  $sizes_xml = $iflickr->get_xml($sizes_url);
  $size_count = 0;
  foreach ($sizes_xml->sizes->size as $size) {
    foreach ($size->attributes() as $key => $val) {
      $sizes[$size_count][$key] = "{$val}"; } 
  $size_count++; }

  // grab the username and title for this image
  $info_url = "http://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key={$api_key}&photo_id={$photo_id}";
  $info_xml = $iflickr->get_xml($info_url);
  $username = "{$info_xml->photo->owner->attributes()->username}";
  $nsid = "{$info_xml->photo->owner->attributes()->nsid}";
  $link = "http://www.flickr.com/photos/{$nsid}/{$photo_id}";

  // select the correct size
  $size = $sizes[$size_id];
  $width = $size['width'];
  $height = $size['height'];
  $source = $size['source'];

  // upload our file
  $upload = $iflickr->upload_file($filename, $source, $description, $username, $link, $post_id);
  if (!isset($upload['url'])) {
    $html  = "<div align='center'><br><br><br><br><br>" . __("There was an error uploading the file.", $iflickr->l) . "</div>"; }
  else {
    $username = htmlentities(str_replace(array("\"", "'"), '', stripslashes($username)));
    $description = htmlentities(str_replace(array("\"", "'"), '', stripslashes($description)));
    $html  = "<div align='center'><br><br><br><br><br>" . __("Downloading image, please wait...", $iflickr->l) . "</div>";
    $html .= "<script type='text/javascript'>jQuery(document).ready(function() { iflickrAddPhoto('{$upload['url']}', '{$width}', '{$height}', '{$description}', '{$username}', '{$upload['link']}');parent.tb_remove(); });</script>"; }
  echo $html;
}

/*
 *  Call the AJAX to generate the properties page content... this way the window pops up without waiting on the results of the API call.
 */

function iflickr_properties() {

  $post_id = $_REQUEST['post_id'];
  echo "<div id='iflickrcontent' align='center'><br><br><br><br><br>" . __("Please wait while the image loads...", $iflickr->l) . "</div>";
  echo "<script type='text/javascript'>jQuery(document).ready(function() { iflickrLoadProperties('{$post_id}'); });</script>";
}



?>

