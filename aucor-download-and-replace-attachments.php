<?php
/*
Plugin Name: Aucor Download and Replace Attachments
Plugin URI:
Version: 0.1.0
Author: Aucor Oy
Author URI: https://github.com/aucor
Description: Download and replace attachments (img and a tags) in WordPress content and add them to Media Library.
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: aucor-download-and-replace-attachments
*/

defined('ABSPATH') or die('Nope');

if (defined('WP_CLI') && WP_CLI) {

  class Aucor_Download_And_Replace_Attachments {

    protected $assoc_args;
    protected $args;
    protected $attachment_ids;

    protected $updated;
    protected $ignored;

    /**
     * wp aucor-download-and-replace-attachments run
     *
     * --post_type=page
     * --from_domain=aucor.fi
     * --extensions=jpg,png
     * --image_size=large
     */
    public function run($args = array(), $assoc_args = array()) {

      $this->args = $args;
      $this->assoc_args = $assoc_args;

      $this->attachment_ids = [];

      global $wpdb;

      $query_args = array(
        'post_type'       => (isset($this->args['post_type'])) ? $this->args['post_type'] : 'any',
        'posts_per_page'  => -1,
        'post_status'     => 'any',
        'lang'             => '',
      );

      $query = new WP_Query($query_args);

      while ($query->have_posts()) : $query->the_post();

        $this_post_was_updated = false;

        // deal with content
        $the_content = get_the_content();
        $altered_content = $this->replace_media_in_content($the_content, $query->post);

        if ($the_content !== $altered_content) {

          // update with wpdb to avoid revisions and changes to modified dates etc
          $wpdb->update(
            $wpdb->posts,
            array(
              'post_content' => $altered_content // data
            ),
            array(
              'ID' => $query->post->ID // where
            ),
            array(
              '%s' // data format
            ),
            array(
            '%d' // where format
            )
          );

          $this_post_was_updated = true;

        }

        if ($this_post_was_updated) {
          $this->updated++;
        } else {
          $this->ignored++;
        }

      endwhile;
      wp_reset_query();

      /**
       * Fix problem of new attachments beign sometimes created on wrong post type
       */
      foreach($this->attachment_ids as $attachment_id) {
        set_post_type($attachment_id, 'attachment');
      }

      WP_CLI::success('Done: ' . $this->updated . ' posts modified, ' . $this->ignored . ' posts ignored.');

    }

    /**
     * Checks if given src/url should be downloaded
     *
     * @param string $src resource's url
     *
     * @return bool
     */
    protected function should_src_be_downloaded($src) {

      if (empty($src)) {
        return false;
      }

      if ($this->assoc_args['extensions']) {
        $allowed_extensions = explode(',', $this->assoc_args['extensions']);
      } else {
        $allowed_extensions = array(
          'png',
          'jpg',
          'jpeg',
          'gif',
          'tif',
          'bmp',
          'psd',
          'svg',
          'eps',
          'pic',

          'pdf',
          'doc',
          'docx',
          'dot',
          'xls',
          'xlt',
          'xlsx',
          'xml',
          'ppt',
          'pot',
          'pptx',
          'txt',
          'text',
          'csv',

          'mp4',
          'avi',

          'zip',
          'rar',
          'tar',
          'gz',
          'tgz',

          'mp3',
          'wav',
          'wma',
          'ogg',
        );
      }

      // already local?
      if (strstr($src, $_SERVER['HTTP_HOST'])) {
        return false;
      }

      // already local?
      if (strstr($src, get_site_url())) {
        return false;
      }

      // relative?
      if (substr($src, 0, 1) === '/' && substr($src, 0, 2) !== '//') {
        return false;
      }

      // if from_domain is specified, check that it is found
      if (isset($this->assoc_args['from_domain']) && !strstr($src, $this->assoc_args['from_domain'])) {
        return false;
      }

      // does it have allowed extension
      $found_extension = substr(strrchr($src, '.'), 1);
      if (!empty($found_extension) && in_array($found_extension, $allowed_extensions)) {
        return true;
      }

      return false;

    }

    /**
     * Replace media in content
     *
     * @param string  content markup
     * @param WP_Post target post object
     */
    function replace_media_in_content($content, $post) {

      $content = $this->replace_content_img($content, $post);
      $content = $this->replace_content_link($content, $post);

      return $content;

    }

    /**
     * Replace images in content with translated versions
     *
     * @param string $content html post content
     * @param obj $post current post object
     *
     * @return string filtered content
     */
    protected function replace_content_img($content, $post) {

      // get all images in content (full <img> tags)
      preg_match_all('/<img[^>]+>/i', $content, $img_array);

      // no images in content
      if(empty($img_array))
        return $content;

      // prepare nicer array structure
      $img_and_meta = array();
      for ($i=0; $i < count($img_array[0]); $i++) {
        $img_and_meta[$i] = array('tag' => $img_array[0][$i]);
      }

      foreach($img_and_meta as $i=>$arr) {

        // get src
        preg_match('/ src="([^"]*)"/i', $img_array[0][$i], $src_temp);
        $img_and_meta[$i]['src'] = !empty($src_temp) ? $src_temp[1] : '';

        // should this src be downloaded?
        if(!$this->should_src_be_downloaded($img_and_meta[$i]['src'])) {
          continue;
        }

        // download attachment and get the new src
        $attachment = $this->get_attachment_replacement($img_and_meta[$i]['src'], $post->ID);
        if (is_array($attachment)) {

          $img_and_meta[$i]['new_src'] = $attachment['image_url'];

          // create new tag that is ready to replace the original
          $img_and_meta[$i]['new_tag'] = preg_replace('/src="([^"]*)"/i', ' src="' . $img_and_meta[$i]['new_src'] . '"', $img_and_meta[$i]['tag']);

          // @todo handle existing size and id attributes
          // @todo handle image size attribute better for small images
          $classes = 'wp-image-' . $attachment['attachment_id'] . ' size-' . $attachment['image_size'];


          // inject new classes
          if (strstr($img_and_meta[$i]['new_tag'], 'class="')) {
            $img_and_meta[$i]['new_tag'] = str_replace('class="', 'class="' . $classes . ' ', $img_and_meta[$i]['new_tag']);
          } else {
            $img_and_meta[$i]['new_tag'] = str_replace('<img ', '<img class="' . $classes . '"', $img_and_meta[$i]['new_tag']);
          }

          // replace image inside content
          $content = str_replace($img_and_meta[$i]['tag'], $img_and_meta[$i]['new_tag'], $content);

        }

      }

      return $content;

    }

    /**
     * Replace links in content
     *
     * @param string $content html post content
     * @param obj $post current post object
     *
     * @return string filtered content
     */
    protected function replace_content_link($content, $post) {

      // get all images in content (full <a> tags)
      preg_match_all('/ href="([^"]*)"/i', $content, $href_array);

      // prepare nicer array structure
      $href_and_meta = array();
      for ($i=0; $i < count($href_array[0]); $i++) {
        $href_and_meta[$i] = array('tag' => $href_array[0][$i]);
      }

      foreach($href_and_meta as $i=>$arr) {

        // get url
        preg_match('/ href="([^"]*)"/i', $href_array[0][$i], $src_temp);
        $href_and_meta[$i]['src'] = !empty($src_temp) ? $src_temp[1] : '';

        // should this src be downloaded?
        if(!$this->should_src_be_downloaded($href_and_meta[$i]['src'])) {
          continue;
        }

        // download attachment and get the new src
        $attachment = $this->get_attachment_replacement($href_and_meta[$i]['src'], $post->ID);
        if (is_array($attachment)) {

          $href_and_meta[$i]['new_src'] = $attachment['attachment_url'];

          // create new tag that is ready to replace the original
          $href_and_meta[$i]['new_tag'] = preg_replace('/href="([^"]*)"/i', ' href="' . $href_and_meta[$i]['new_src'] . '"', $href_and_meta[$i]['tag']);

          // replace image inside content
          $content = str_replace($href_and_meta[$i]['tag'], $href_and_meta[$i]['new_tag'], $content);

        }

      }

      return $content;

    }

    /**
     * Download attachment => no need to do any checks at this point
     *
     * @param string $src the full url of file to be replaced
     * @param int    $post_id the id of post to be attached
     *
     * @return string $file_url new url
     */
    protected function get_attachment_replacement($src, $post_id) {

      // gives us access to the download_url() and wp_handle_sideload() functions
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . '/wp-admin/includes/media.php';
      require_once ABSPATH . '/wp-admin/includes/image.php';

      if (!strstr($src, 'http://') && !strstr($src, 'https://')) {
        $src = 'http://' . $src;
      }

      $is_new_image = false;

      $attachment_id = null;

      // check existing image
      $sub_args = array(
        'post_type'      => 'any',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_key'       => '_og_url',
        'meta_value'     => $src,
      );
      $sub_query = new WP_Query($sub_args);
      while ($sub_query->have_posts()) : $sub_query->the_post();
        $attachment_id = get_the_ID();
      endwhile;

      if (empty($attachment_id)) {

        // download file to temp dir
        $temp_file = download_url($src, 300);
        if (!is_wp_error($temp_file)) {

          $mime_type = mime_content_type($temp_file);

          // An array similar to that of a PHP `$_FILES` POST array
          $file = array(
            'name'     => basename($src),
            'type'     => $mime_type,
            'tmp_name' => $temp_file,
            'error'    => 0,
            'size'     => filesize($temp_file),
          );

          $attachment_id = media_handle_sideload($file, $post_id);

          if (!is_wp_error($attachment_id)) {

            $is_new_image = true;

            // media_handle_sideload creates posts sometimes, hotfix
            if (get_post_type($attachment_id !== 'attachment')) {
              set_post_type($attachment_id, 'attachment');
            }

            // save og url for later
            update_post_meta($attachment_id, '_og_url', $src);

          }

        }

      }

      // return image data
      if (!empty($attachment_id) && !is_wp_error($attachment_id)) {

        $this->attachment_ids[$attachment_id] = $attachment_id;

        $image_size = isset($this->assoc_args['image_size']) ? $this->assoc_args['image_size'] : 'large';

        $image_url = '';
        $image_src = wp_get_attachment_image_src($attachment_id, $image_size);
        if (!empty($image_src) && !is_wp_error($image_src)) {
          $image_url = $image_src[0];
        }

        $attachment_url = wp_get_attachment_url($attachment_id);

        $attachment_status = ($is_new_image) ? 'new' : 'existing';
        WP_CLI::log('Replacing "' . $src . '" with ' . $attachment_status . ' attachment #' . $attachment_id . ' (' . $attachment_url . ') on post #' . $post_id);

        return [
          'attachment_id'      => $attachment_id,
          'image_size'         => $image_size,
          'image_url'          => $image_url,
          'attachment_url'     => $attachment_url
        ];

      }

      WP_CLI::warning('No replacement for attachment "' . $src . '" on post #' . $post_id);

      return false;

    }

  }

  WP_CLI::add_command('aucor-download-and-replace-attachments', 'Aucor_Download_And_Replace_Attachments');

}
