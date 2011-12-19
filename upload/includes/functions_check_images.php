<?php
if (function_exists('curl_multi_init'))
{
    // Max simultaneous connections to one domain
    define("CI_DOMAIN_CONNECT_LIMIT", 16);

    // How many times the check image
    define("CI_CHECK_COUNT", 6);

    // The time interval for checking URL in minutes, 1 means the interval is determined by cron job
    define("CI_CHECK_INTERVAL", 1);

    // Max days to keep records in the DB
    define("CI_MAX_RECORD_AGE", 3);

    // Global array used to pass URLs from presave to postsave hook
    $ci_postponed_urls = array();

    /**
     * Checks URLs with limit of active sessions to same domain.
     *
     * Return array of 2 elements for each URL:
     *
     *  status:
     *   SUCCESS: image are OK
     *   REPLACE: this is not a image or it is too big
     *   PROCESSING: check failed
     *
     *  size: content size in Kb
     * 
     * @param array $urls URLs to check.
     * @return mixed $result Array or false if $urls is empty.
     */ 
    function ci_check_urls($urls = array())
    {
        global $vbulletin;
        
        if (empty($urls) || !is_array($urls)) return false;

        $curl_options = array(
                CURLOPT_HEADER => 1,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,

                // vkontakte.ru can have 4 redirects
                CURLOPT_MAXREDIRS => 6
        );

        $pool  = curl_multi_init();
        $result  = array();

        /*
        * Count URLs in queue by domain, ex:
        * ('www.example.com' => 5, 
        *  'www.example.net' => 1)
        */
        $domain_counter = array();

        // Connection pool for curl_multi
        $connections = array();

        // Count URLs to check
        $urls_count = count($urls);

        while ($urls_count > 0)
        {
            // Add a connection to CURL until they reach a limit connections
            foreach ($urls as $key => $url)
            {
                $domain = parse_url($url, PHP_URL_HOST);

                if (!isset($domain_counter[$domain]))
                {
                    $domain_counter[$domain] = 0;
                }

                // If the number of connections to the domain has not reached the limit - add one more
                if ($domain_counter[$domain] < CI_DOMAIN_CONNECT_LIMIT)
                {
                    $connections[$key] = curl_init($urls[$key]);
                    curl_setopt_array($connections[$key], $curl_options);
                    curl_multi_add_handle($pool, $connections[$key]);
                    $domain_counter[$domain]++;
                    unset($urls[$key]);
                }
            }

            // Run all requests in queue
            $running = null;
            do { $status = curl_multi_exec($pool, $running); }
            while ($running || $status === CURLM_CALL_MULTI_PERFORM);

            // If we have completed requests
            while ($done = curl_multi_info_read($pool))
            {
                $info = curl_getinfo($done['handle']);

                // Decrease domain counter
                $domain = parse_url($info['url'], PHP_URL_HOST);
                $domain_counter[$domain]--;

                // Decrease url counter
                $urls_count--;
            }
        }

        // URL check finished
        // Fill check result
        foreach ($connections as $key => $resource)
        {
            $http_header = curl_getinfo($resource);
            curl_multi_remove_handle($pool, $resource);

            if ($http_header['http_code'] == 200)
            {
                if ( (intval($http_header['download_content_length']) > ($vbulletin->options['ci_filesize'] * 1024)) OR
                     (strpos($http_header['content_type'], 'image/') === false) )
                {
                    $status = 'REPLACE';
                }
                else
                {
                    $status = 'SUCCESS';
                }
            }
            else
            {
                $status = 'PROCESSING';
            }

            $result[$key] = array('status' => $status, 'size' => intval($http_header['download_content_length']));
        }

        curl_multi_close($pool);
        return $result;
    }

    /** 
     * Find all IMG tags in message and check size. If size exceed the limit or this is not a image replace them to URL tag.
     * If URL is inaccessible it appended to $ci_image_queue global array ('key' => 'url') because we cannot save it now - lack of contentid.
     *
     * @param string &$message Message where need to check & replace.
     * @return array $ci_postponed_urls URLs which need to check later.
     */ 
    function ci_fix_images_in_msg(&$message)
    {
        $ci_postponed_urls = array();

        // If message contains IMG tags
        if (preg_match_all('#\[img\]\s*(https?://([^*\r\n]+|[a-z0-9/\\._\- !]+))\[/img\]#iUe', $message, $ci_img_tags))
        {
            // Filter ut ll data-uris
            foreach ($ci_img_tags[1] as $idx => $url) {
                if (preg_match('/;base64,[A-Za-z0-9+/]{2}[A-Za-z0-9+/=]{2,}$/', $url)) {
                    // got data uri - remove whole string
                    $message = str_ireplace($ci_img_tags[0][$idx], '', $message);
                    // and remove it from matching groups
                    foreach($ci_img_tags as $i => $arr) {
                        unset($ci_img_tags[$i][$idx]);
                    }
                }
            }
          
            // Get status of each url
            $ci_urls_status = ci_check_urls($ci_img_tags[1]);
            foreach ($ci_urls_status as $key => $url_data)
            {
                switch ($url_data['status'])
                {
                    case 'REPLACE':
                        $message = ci_replace_img_tag($message, $ci_img_tags[1][$key], $url_data['size']);
                        break;
                    case 'PROCESSING':
                        // Now we cannot save URL because lack of contentid,
                        // it will be inserted in table via _postsave hook
                        $ci_postponed_urls[] = $ci_img_tags[1][$key];
                        break;
                }
            }
        }

        return $ci_postponed_urls;
    }

    /** 
     * Write postponed URLs to table.
     * If the post was edited - skip successfully checked and check-in-progress URLs.
     *
     * @param array $ci_postponed_urls URLs list
     * @param int $message_id Content ID.
     * @param string $c_type Content type (Post, Comment, etc.).
     */ 
    function ci_queue_url_check($ci_postponed_urls, $message_id, $c_type)
    {
        global $vbulletin;

        // Exit if nothing to save
        if (empty($ci_postponed_urls))
        {
            return;
        }

        // ensure that the framework is initialized
        require_once(DIR . '/includes/class_bootstrap_framework.php');
        vB_Bootstrap_Framework::init();
        require_once(DIR . '/vb/types.php');
        $c_type_id = vB_Types::instance()->getContentTypeID($c_type);

        // If we have checked (or checking) URLs in queue - do not add them again
        $resource = $vbulletin->db->query("
                                    SELECT url
                                    FROM " . TABLE_PREFIX . "rcd_imagequeue
                                    WHERE `contentid` = " . $message_id . " AND
                                          `contenttypeid` = " . $c_type_id . " AND
                                          `status` IN ('SUCCESS', 'PROCESSING')
        ");

        $already_checked_url = array();

        while ($data = $vbulletin->db->fetch_array($resource))
        {
            $already_checked_url[] = $data['url'];
        }

        $vbulletin->db->free_result($resource);

        // Remove URL from postponed URLs list if it in queue already
        if (!empty($already_checked_url))
        {
            foreach($ci_postponed_urls as $key => $url)
            {
                if (in_array($url, $already_checked_url))
                {
                    unset($ci_postponed_urls[$key]);
                }
            }
        }

        // Exit if nothing is left to save
        if (empty($ci_postponed_urls))
        {
            return;
        }

        // Save URL with status PROCESSING, it will be checked later by cron job
        foreach ($ci_postponed_urls as $url)
        {
            $ci_sql_values[] = "('" . $vbulletin->db->escape_string($url) . "',
                                " . $message_id . ",
                                " . $c_type_id . ",
                                '" . $vbulletin->db->escape_string(parse_url($url, PHP_URL_HOST)) . "')";
        }

        $vbulletin->db->query_write("INSERT INTO " . TABLE_PREFIX . "rcd_imagequeue
                                     (`url`, `contentid`, `contenttypeid`, `domain`)
                                     VALUES
                                     " . implode(',', $ci_sql_values) . "
                                    ");
    }

    /**
     * Replaces the IMG tag on the URL and adds the size.
     *
     * @param string $message Message where need to replace the tag.
     * @param string $url Content of IMG tag which need to replace.
     * @param int $size Content size (in bytes) to append after URL tag.
     * @return string $message Message with replaced tags.
     */
    
    function ci_replace_img_tag($message, $url, $size=0)
    {
        global $vbphrase;

        $ci_phrase = '';

        if ($size > 0)
        {
            $ci_phrase .= ' ' . construct_phrase($vbphrase['ci_img_chk_failed'], intval($size / 1024));
        }

        $to_replace = '[IMG]' . $url . '[/IMG]';
        $replacement = '[URL]' . $url . '[/URL]' . $ci_phrase;

        return str_ireplace($to_replace, $replacement, $message);
    }
}
