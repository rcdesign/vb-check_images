<?php
/*======================================================================*\
|| #################################################################### ||
|| # Check Images 0.2                                                 # ||
|| # ---------------------------------------------------------------- # ||
|| # Copyright 2011 Dmitry Davydov, Vitaly Puzrin.                    # ||
|| # All Rights Reserved.                                             # ||
|| # This file may not be redistributed in whole or significant part. # ||
|| #################################################################### ||
\*======================================================================*/

// ######################## SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE);
if (!is_object($vbulletin->db))
{
    exit;
}

// ############################# REQUIRE ##################################
require_once(DIR . '/includes/functions.php');
require_once(DIR . '/includes/functions_socialgroup.php');
require_once(DIR . '/includes/functions_check_images.php');
require_once(DIR . '/includes/blog_functions.php');
require_once(DIR . '/includes/class_bootstrap_framework.php');
require_once(DIR . '/vb/types.php');

if (!function_exists('ci_check_urls'))
{
    exit;
}

// ########################################################################
// ######################### START MAIN SCRIPT ############################
// ########################################################################

vB_Bootstrap_Framework::init();

$ci_content_types = vB_Types::instance();

/*
* get URLs that we need to check (status = PROCESSING and nextcheck < now)
*
* valid statuses are: 
*  PROCESSING - http code not 200, will be checked later
*  SUCCESS - got http code 200, not be checked anymore
*  REPLACE - got http code 200, but it too big or it not a picture
*  FAILED - number of attempts exceeded, not be checked anymore
*/
$ci_query_resource = $vbulletin->db->query("
    SELECT imagequeueid,
           url,
           contentid,
           contenttypeid,
           attempts
    FROM " . TABLE_PREFIX . "rcd_imagequeue
    WHERE status = 'PROCESSING' AND
          nextcheck < " . TIMENOW . "
    ");

// Queue URLs to check
$ci_urls = array();

// Full URL data (to update message)
$ci_urls_data = array();

// Fetch queued URL info
while ($ci_data = $vbulletin->db->fetch_array($ci_query_resource))
{
    $ci_urls_data[$ci_data['imagequeueid']] = $ci_data;
    $ci_urls[$ci_data['imagequeueid']] = $ci_data['url'];
}

$vbulletin->db->free_result($ci_query_resource);

// Check URLs (get status and content size)
$ci_urls_status = ci_check_urls($ci_urls);

if (is_array($ci_urls_status))
{
    // Now we have status and content size on every url
    foreach ($ci_urls_status as $key => $url_data)
    {
        if ($url_data['status'] == 'REPLACE')
        {
            // Get classname from contenttypeid
            $ci_content_type = $ci_content_types->getContentTypeClass($ci_urls_data[$key]['contenttypeid']);

            // Replace IMG tag to URL
            switch ($ci_content_type)
            {
                case 'Post':
                case 'Thread':
                    $ci_content = fetch_postinfo($ci_urls_data[$key]['contentid']);
                    $ci_manager =& datamanager_init($ci_content_type, $vbulletin, ERRTYPE_STANDARD, 'threadpost');
                    $ci_manager->set_existing($ci_content);
                    $ci_manager->set('pagetext', ci_replace_img_tag($ci_content['pagetext'], $ci_urls_data[$key]['url'], $url_data['size']));
                    $ci_manager->save();
                    break;
                case 'SocialGroupMessage':
                case 'SocialGroupDiscussion':
                    $ci_content = fetch_groupmessageinfo($ci_urls_data[$key]['contentid']);
                    $ci_manager =& datamanager_init('GroupMessage', $vbulletin);
                    $ci_manager->set_existing($ci_content);
                    $ci_manager->set('pagetext', ci_replace_img_tag($ci_content['pagetext'], $ci_urls_data[$key]['url'], $url_data['size']));
                    $ci_manager->save();
                    break;
                case 'BlogEntry':
                case 'BlogComment':
                    $ci_content = fetch_blog_textinfo($ci_urls_data[$key]['contentid']);
                    $ci_manager =& datamanager_init('BlogText', $vbulletin, ERRTYPE_STANDARD, 'blog');
                    $ci_manager->set_existing($ci_content);
                    $ci_manager->set('pagetext', ci_replace_img_tag($ci_content['pagetext'], $ci_urls_data[$key]['url'], $url_data['size']));
                    $ci_manager->save();
                    break;
            }
        }
        $ci_url_check_attempts = $ci_urls_data[$key]['attempts'] + 1;

        // If the status PROCESSING, but the number of attempts is over - set FAILED
        $ci_url_queue_status = ($url_data['status'] == 'PROCESSING' AND $ci_url_check_attempts >= CI_CHECK_COUNT) ? 'FAILED' : $url_data['status'] ;

        $vbulletin->db->query_write(" UPDATE " . TABLE_PREFIX . "rcd_imagequeue
                           SET   attempts = " . $ci_url_check_attempts . ",
                                 nextcheck = " . (TIMENOW + CI_CHECK_INTERVAL * 60) . ",
                                 status = '" . $ci_url_queue_status . "'
                           WHERE imagequeueid = " . $key . "
                        ");
    }
}

log_cron_action('', $nextitem, 1);

?>
