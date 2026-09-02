<?php
require_once __DIR__ . '/wp-load.php';

$fails = 0;
function check($label, $expected, $actual) {
    global $fails;
    $ok = $expected === $actual;
    echo ($ok ? "PASS" : "FAIL") . " — $label\n";
    if (!$ok) { echo "   expected: " . var_export($expected,true) . "\n   got:      " . var_export($actual,true) . "\n"; $fails++; }
}

// Create a real WP page with real content.
$page_id = wp_insert_post([
    'post_title' => 'Members Only Area',
    'post_content' => 'SECRET_MEMBER_CONTENT_12345',
    'post_status' => 'publish',
    'post_type' => 'page',
]);

// Create a membership plan restricting this exact page.
$plan_repo = new \CredoqMembership\Plan_Repository();
$plan_id = $plan_repo->save([
    'name' => 'Gold Plan',
    'duration_days' => 30,
    'rules' => [
        'restricted_pages' => [$page_id],
        'restriction_html' => '<p>UPGRADE_TO_SEE_THIS</p>',
        'unlock_url' => 'https://example.test/pricing',
    ],
]);

// Create a non-member user and a member user.
$suffix = time() . '_' . wp_rand(1000,9999);
$non_member_id = wp_insert_user(['user_login'=>'nonmember_'.$suffix,'user_pass'=>'x','user_email'=>'nm'.$suffix.'@test.test']);
$member_id = wp_insert_user(['user_login'=>'member_'.$suffix,'user_pass'=>'x','user_email'=>'m'.$suffix.'@test.test']);
if (is_wp_error($non_member_id) || is_wp_error($member_id)) {
    echo "USER CREATION FAILED: " . (is_wp_error($non_member_id) ? $non_member_id->get_error_message() : '') . (is_wp_error($member_id) ? $member_id->get_error_message() : '') . "\n";
    exit(1);
}

global $wpdb;
$wpdb->insert($wpdb->prefix.'credoq_user_memberships', [
    'user_id' => $member_id, 'plan_id' => $plan_id, 'status' => 'active',
    'purchase_date' => current_time('mysql', true),
    'expiry_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
    'order_id' => 0, 'wc_order_status' => '',
]);

function render_page_content($page_id) {
    global $post, $wp_query;
    $wp_query = new WP_Query(['page_id' => $page_id]);
    $GLOBALS['wp_the_query'] = $wp_query;
    $post = get_post($page_id);
    setup_postdata($post);
    ob_start();
    echo apply_filters('the_content', $post->post_content);
    $out = ob_get_clean();
    wp_reset_postdata();
    return $out;
}

// --- Non-logged-in visitor ---
wp_set_current_user(0);
$out = render_page_content($page_id);
check('Guest visitor: real content is BLOCKED', false, strpos($out, 'SECRET_MEMBER_CONTENT_12345') !== false);
check('Guest visitor: sees the plan\'s restriction_html', true, strpos($out, 'UPGRADE_TO_SEE_THIS') !== false);
check('Guest visitor: sees the unlock link', true, strpos($out, 'https://example.test/pricing') !== false);

// --- Logged-in NON-member ---
wp_set_current_user($non_member_id);
$out2 = render_page_content($page_id);
check('Non-member (logged in, no plan): still BLOCKED', true, strpos($out2, 'UPGRADE_TO_SEE_THIS') !== false);

// --- Logged-in MEMBER ---
wp_set_current_user($member_id);
$out3 = render_page_content($page_id);
check('Member (active Gold Plan): sees the REAL content', true, strpos($out3, 'SECRET_MEMBER_CONTENT_12345') !== false);
check('Member: does NOT see the restriction message', false, strpos($out3, 'UPGRADE_TO_SEE_THIS') !== false);

// --- An UNRESTRICTED page is never touched ---
$free_page_id = wp_insert_post(['post_title'=>'Free Page','post_content'=>'FREE_PUBLIC_CONTENT','post_status'=>'publish','post_type'=>'page']);
wp_set_current_user(0);
$out4 = render_page_content($free_page_id);
check('Unrestricted page: unaffected for guests', true, strpos($out4, 'FREE_PUBLIC_CONTENT') !== false);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
